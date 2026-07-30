<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\WalletService;
use App\Services\Billing\AIUsageService;
use App\Services\Stripe\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function __construct(
        private WalletService $wallet,
        private AIUsageService $aiUsage,
        private StripeService $stripe,
    ) {}

    /** GET /api/wallet */
    public function show(Request $req): JsonResponse
    {
        $user = $req->user();
        $info = $this->wallet->getWalletInfo($user->id);
        return response()->json($info);
    }

    /** GET /api/wallet/history */
    public function history(Request $req): JsonResponse
    {
        $user = $req->user();
        $type = $req->query('type');
        $page = (int) $req->query('page', 1);
        $perPage = (int) $req->query('per_page', 20);

        $data = $this->wallet->getHistory($user->id, $page, $perPage, $type);
        return response()->json($data);
    }

    /** GET /api/wallet/usage */
    public function usage(Request $req): JsonResponse
    {
        $user = $req->user();
        $page = (int) $req->query('page', 1);
        $perPage = (int) $req->query('per_page', 20);
        $feature = $req->query('feature');

        $data = $this->aiUsage->getUsageHistory($user->id, $page, $perPage, $feature);
        return response()->json($data);
    }

    /** GET /api/wallet/pricing */
    public function pricing(Request $req): JsonResponse
    {
        return response()->json(['pricing' => $this->aiUsage->getPricingTable()]);
    }

    /** POST /api/wallet/auto-recharge */
    public function updateAutoRecharge(Request $req): JsonResponse
    {
        $req->validate([
            'enabled' => 'boolean',
            'threshold' => 'numeric|min:1|max:100',
            'recharge_amount' => 'numeric|min:1|max:500',
            'payment_method_id' => 'nullable|string',
        ]);

        $setting = $this->wallet->updateAutoRecharge($req->user()->id, $req->only([
            'enabled', 'threshold', 'recharge_amount', 'payment_method_id',
        ]));

        return response()->json(['auto_recharge' => [
            'enabled' => $setting->enabled,
            'threshold' => (float) $setting->threshold,
            'recharge_amount' => (float) $setting->recharge_amount,
        ]]);
    }

    /** POST /api/wallet/topup/prepare */
    public function prepareTopup(Request $req): JsonResponse
    {
        $req->validate([
            'amount' => 'required|numeric|min:1|max:500',
            'provider' => 'required|string|in:stripe,paypal,razorpay,manual',
        ]);

        $user = $req->user();
        $amount = (float) $req->input('amount');
        $provider = $req->input('provider');

        // For Stripe, create a checkout session
        if ($provider === 'stripe') {
            $token = $req->bearerToken();
            $successUrl = config('app.url') . "/app/billing?wallet=success&session_id={CHECKOUT_SESSION_ID}";
            $cancelUrl = config('app.url') . "/app/billing?wallet=cancelled";

            try {
                $result = $this->stripe->createWalletTopupSession($user, $amount);

                // Pre-create topup record
                $topup = \App\Models\WalletTopup::create([
                    'user_id' => $user->id,
                    'payment_provider' => 'stripe',
                    'stripe_session_id' => $result['session_id'],
                    'amount' => $amount,
                    'currency' => 'USD',
                    'payment_status' => 'pending',
                    'description' => "Wallet top-up of \${$amount}",
                ]);

                return response()->json([
                    'checkout_url' => $result['checkout_url'],
                    'session_id' => $result['session_id'],
                    'topup_id' => $topup->id,
                ]);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }

        // For manual/offline, just create pending record
        $topup = \App\Models\WalletTopup::create([
            'user_id' => $user->id,
            'payment_provider' => $provider,
            'amount' => $amount,
            'currency' => 'USD',
            'payment_status' => 'pending',
            'description' => "Wallet top-up of \${$amount} (pending)",
        ]);

        return response()->json([
            'topup_id' => $topup->id,
            'message' => 'Top-up initiated. You will be credited once payment is confirmed.',
        ]);
    }

    /**
     * POST /api/billing/wallet/verify-topup
     * Called by frontend after Stripe redirects with ?wallet=success&session_id=...
     * Verifies the Stripe session is paid, then credits the wallet.
     */
    public function verifyWalletTopup(Request $req): JsonResponse
    {
        $req->validate(['session_id' => 'required|string']);

        $user = $req->user();
        $sessionId = $req->input('session_id');

        Log::info('WalletTopup: verifyWalletTopup called', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
        ]);

        // Check if already processed (idempotency)
        $existingTopup = \App\Models\WalletTopup::where('stripe_session_id', $sessionId)->first();
        if ($existingTopup && $existingTopup->payment_status === 'completed') {
            Log::info('WalletTopup: already processed, returning current state', [
                'topup_id' => $existingTopup->id,
                'session_id' => $sessionId,
            ]);
            $wallet = $this->wallet->getWalletInfo($user->id);
            return response()->json([
                'success' => true,
                'already_processed' => true,
                'balance' => $wallet['wallet']['balance'],
                'message' => 'Wallet already credited.',
            ]);
        }

        // Verify with Stripe
        try {
            $session = $this->stripe->getSession($sessionId);
        } catch (\Exception $e) {
            Log::error('WalletTopup: Stripe session retrieval failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Could not verify payment with Stripe.'], 500);
        }

        if ($session->payment_status !== 'paid') {
            Log::warning('WalletTopup: payment not completed', [
                'session_id' => $sessionId,
                'payment_status' => $session->payment_status,
            ]);
            return response()->json([
                'error' => 'Payment not completed.',
                'payment_status' => $session->payment_status,
            ], 402);
        }

        // Double-check metadata matches this user
        $metadataUserId = (int) ($session->metadata['user_id'] ?? 0);
        if ($metadataUserId !== $user->id) {
            Log::error('WalletTopup: user mismatch', [
                'session_user_id' => $metadataUserId,
                'request_user_id' => $user->id,
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get amount from session
        $amount = (float) ($session->metadata['amount'] ?? 0);
        if (!$amount) {
            $amount = (float) ($session->amount_total ?? 0) / 100;
        }

        if (!$amount) {
            Log::error('WalletTopup: no amount found', ['session_id' => $sessionId]);
            return response()->json(['error' => 'Invalid payment amount.'], 400);
        }

        Log::info('WalletTopup: processing topup', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'amount' => $amount,
        ]);

        // Credit the wallet atomically
        DB::transaction(function () use ($user, $amount, $sessionId, $existingTopup) {
            // Update or create topup record
            if ($existingTopup) {
                $existingTopup->update([
                    'payment_status' => 'completed',
                    'paid_at' => now(),
                ]);
            } else {
                \App\Models\WalletTopup::create([
                    'user_id' => $user->id,
                    'payment_provider' => 'stripe',
                    'stripe_session_id' => $sessionId,
                    'amount' => $amount,
                    'currency' => 'USD',
                    'payment_status' => 'completed',
                    'paid_at' => now(),
                    'description' => "Wallet top-up of \${$amount}",
                ]);
            }

            // Credit wallet
            $this->wallet->topup(
                $user->id,
                $amount,
                'stripe',
                $sessionId,
                'completed',
                "Wallet top-up via Stripe checkout"
            );
        });

        Log::info('WalletTopup: wallet credited successfully', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'amount' => $amount,
        ]);

        // Return updated wallet balance
        $wallet = $this->wallet->getWalletInfo($user->id);
        return response()->json([
            'success' => true,
            'already_processed' => false,
            'amount_credited' => $amount,
            'balance' => $wallet['wallet']['balance'],
            'available_balance' => $wallet['wallet']['available_balance'],
        ]);
    }

    /** GET /api/wallet/topup/{id}/status */
    public function topupStatus(Request $req, int $id): JsonResponse
    {
        $topup = \App\Models\WalletTopup::where('id', $id)
            ->where('user_id', $req->user()->id)
            ->firstOrFail();

        return response()->json([
            'id' => $topup->id,
            'amount' => (float) $topup->amount,
            'currency' => $topup->currency,
            'status' => $topup->payment_status,
            'provider' => $topup->payment_provider,
            'created_at' => $topup->created_at->toIso8601String(),
        ]);
    }
}
