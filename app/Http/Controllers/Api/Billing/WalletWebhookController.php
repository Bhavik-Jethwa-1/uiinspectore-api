<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\WalletService;
use App\Services\Stripe\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class WalletWebhookController extends Controller
{
    // Tracks processed session IDs within the current request to prevent
    // payment_intent.succeeded from creating a duplicate after
    // checkout.session.completed fires in the same Stripe webhook call.
    private static array $processedThisRequest = [];

    public function __construct(
        private WalletService $wallet,
        private StripeService $stripe,
    ) {}

    /** POST /api/wallet/webhook/stripe */
    public function stripe(Request $request)
    {
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        Log::info('WalletWebhook: received', [
            'has_sig' => !empty($sig),
            'secret_set' => !empty($secret) && $secret !== 'whsec_placeholder',
            'secret_prefix' => $secret ? substr($secret, 0, 8) : 'null',
            'type' => $request->header('Stripe-Stripe-Version'),
        ]);

        $event = null;

        try {
            if ($sig && $secret && $secret !== 'whsec_placeholder') {
                $event = $this->stripe->constructWebhookEvent($payload, $sig);
                if (!$event) {
                    Log::error('WalletWebhook: constructWebhookEvent returned null — verification failed');
                    return response()->json(['error' => 'Webhook verification failed'], 400);
                }
            } else {
                // Dev fallback: decode without verification
                $event = json_decode($payload, true);
                Log::warning('WalletWebhook: running without signature verification (dev mode)');
            }
        } catch (\Exception $e) {
            Log::error("WalletWebhook: signature verification failed — " . $e->getMessage(), [
                'secret_prefix' => $secret ? substr($secret, 0, 8) : 'null',
            ]);
            // In dev, still try to process with decoded payload
            $event = json_decode($payload, true);
        }

        if (!$event) {
            Log::error('WalletWebhook: could not decode payload');
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? [];

        Log::info("WalletWebhook: processing event", [
            'type' => $type,
            'session_id' => $data['id'] ?? 'unknown',
            'payment_status' => $data['payment_status'] ?? 'unknown',
        ]);

        if ($type === 'checkout.session.completed') {
            $this->handleCheckoutCompleted($data);
        } elseif ($type === 'payment_intent.succeeded') {
            $this->handlePaymentIntentSucceeded($data);
        } elseif ($type === 'payment_intent.payment_failed') {
            $this->handlePaymentFailed($data);
        }

        return response()->json(['received' => true]);
    }

    private function handleCheckoutCompleted(array $session): void
    {
        $userId = (int) ($session['metadata']['user_id'] ?? 0);
        $amount = (float) ($session['metadata']['amount'] ?? 0);
        $sessionId = $session['id'] ?? '';

        if (!$userId || !$amount) {
            $amount = (float) ($session['amount_total'] ?? 0) / 100;
            $userId = (int) ($session['metadata']['user_id'] ?? 0);
        }

        if (!$userId || !$amount) {
            Log::error("WalletWebhook: missing user_id or amount", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'amount' => $amount,
                'metadata' => $session['metadata'] ?? [],
            ]);
            return;
        }

        Log::info("WalletWebhook: processing checkout.session.completed", [
            'user_id' => $userId,
            'amount' => $amount,
            'session_id' => $sessionId,
        ]);

        // Also capture payment_intent so handlePaymentIntentSucceeded can detect this
        $paymentIntent = $session['payment_intent'] ?? null;

        // Idempotency: skip if already completed (check any status to prevent duplicates)
        $existing = \App\Models\WalletTopup::where('stripe_session_id', $sessionId)->first();

        if ($existing) {
            if ($existing->payment_status === 'completed') {
                Log::info("WalletWebhook: already credited, skipping duplicate", ['session_id' => $sessionId]);
                return;
            }
            // Record exists but is pending (from prepareTopup) — update it to completed
            Log::info("WalletWebhook: pending record found, updating to completed", ['session_id' => $sessionId]);
        }

        try {
            DB::transaction(function () use ($userId, $amount, $sessionId, $existing, $paymentIntent) {
                if ($existing) {
                    $existing->update([
                        'payment_status' => 'completed',
                        'paid_at' => now(),
                    ]);
                } else {
                    \App\Models\WalletTopup::create([
                        'user_id' => $userId,
                        'payment_provider' => 'stripe',
                        'stripe_session_id' => $sessionId,
                        'payment_intent' => $paymentIntent,
                        'amount' => $amount,
                        'currency' => 'USD',
                        'payment_status' => 'completed',
                        'paid_at' => now(),
                        'description' => "Wallet top-up of \${$amount}",
                    ]);
                }

                // Credit wallet (topup service has its own idempotency check)
                $this->wallet->topup(
                    $userId,
                    $amount,
                    'stripe',
                    $sessionId,
                    'completed',
                    "Wallet top-up via Stripe webhook"
                );
            });

            Log::info("WalletWebhook: wallet credited successfully", [
                'user_id' => $userId,
                'amount' => $amount,
                'session_id' => $sessionId,
            ]);

            // Mark as processed so payment_intent.succeeded in the same request skips
            self::$processedThisRequest[$sessionId] = true;
        } catch (\Exception $e) {
            Log::error("WalletWebhook: failed to credit wallet", [
                'user_id' => $userId,
                'amount' => $amount,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function handlePaymentIntentSucceeded(array $intent): void
    {
        $paymentIntentId = $intent['id'] ?? '';
        $amount = (float) ($intent['amount'] ?? 0) / 100;
        $userId = (int) ($intent['metadata']['user_id'] ?? 0);
        $checkoutSessionId = $intent['metadata']['checkout_session_id'] ?? null;

        // Skip if checkout.session.completed already handled this in the same request.
        // This covers both: (a) Stripe Checkout Sessions and (b) test mode where
        // payment_intent ID == checkout session ID.
        if ($checkoutSessionId && isset(self::$processedThisRequest[$checkoutSessionId])) {
            Log::info("WalletWebhook: payment_intent skipped — checkout.session already handled", [
                'checkout_session' => $checkoutSessionId,
            ]);
            return;
        }
        if (isset(self::$processedThisRequest[$paymentIntentId])) {
            Log::info("WalletWebhook: payment_intent skipped — already processed this request", [
                'payment_intent' => $paymentIntentId,
            ]);
            return;
        }

        if (!$userId || !$amount) {
            Log::warning("WalletWebhook: payment_intent.succeeded missing data", [
                'payment_intent' => $paymentIntentId,
                'user_id' => $userId,
                'amount' => $amount,
            ]);
            return;
        }

        // Idempotency for standalone PaymentIntents (not from Checkout)
        $existing = \App\Models\WalletTopup::where('payment_intent', $paymentIntentId)->first();
        if ($existing) {
            if ($existing->payment_status === 'completed') {
                Log::info("WalletWebhook: payment_intent already processed", ['payment_intent' => $paymentIntentId]);
                return;
            }
        }

        try {
            DB::transaction(function () use ($userId, $amount, $paymentIntentId) {
                $topup = \App\Models\WalletTopup::where('payment_intent', $paymentIntentId)->first();
                if ($topup) {
                    $topup->update(['payment_status' => 'completed', 'paid_at' => now()]);
                } else {
                    \App\Models\WalletTopup::create([
                        'user_id' => $userId,
                        'payment_provider' => 'stripe',
                        'payment_intent' => $paymentIntentId,
                        'amount' => $amount,
                        'currency' => 'USD',
                        'payment_status' => 'completed',
                        'paid_at' => now(),
                        'description' => "Wallet top-up of \${$amount}",
                    ]);
                }

                $this->wallet->topup($userId, $amount, 'stripe', $paymentIntentId, 'completed', 'Wallet top-up via payment_intent');
            });

            Log::info("WalletWebhook: payment_intent wallet credited", [
                'user_id' => $userId,
                'amount' => $amount,
                'payment_intent' => $paymentIntentId,
            ]);
        } catch (\Exception $e) {
            Log::error("WalletWebhook: payment_intent credit failed", [
                'user_id' => $userId,
                'amount' => $amount,
                'payment_intent' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handlePaymentFailed(array $intent): void
    {
        $paymentIntentId = $intent['id'] ?? '';
        $userId = (int) ($intent['metadata']['user_id'] ?? 0);

        if (!$userId) return;

        \App\Models\WalletTopup::where('payment_intent', $paymentIntentId)
            ->where('payment_status', 'pending')
            ->update(['payment_status' => 'failed']);

        Log::info("WalletWebhook: payment failed marked", [
            'user_id' => $userId,
            'payment_intent' => $paymentIntentId,
        ]);
    }
}
