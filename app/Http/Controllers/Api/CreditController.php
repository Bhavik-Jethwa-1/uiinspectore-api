<?php

namespace App\Http\Controllers\Api;

use App\Models\CreditPack;
use App\Models\CreditPurchase;
use App\Services\Billing\WalletService;
use App\Services\Stripe\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * CreditPack purchases — all credit packs top up the USD Wallet.
 *
 * Credit packs are a simplified purchase interface (e.g. "1000 credits for $10")
 * that maps directly to USD wallet topups. The wallet is the ONLY source of
 * truth for AI execution payment.
 *
 * OLD: UserCredit (credits as separate currency) → DEPRECATED, migrated below
 */
class CreditController extends \Illuminate\Routing\Controller
{
    private WalletService $wallet;
    private StripeService $stripe;

    public function __construct(
        WalletService $wallet,
        StripeService $stripe,
    ) {
        $this->wallet = $wallet;
        $this->stripe = $stripe;
    }

    /**
     * GET /api/billing/credits/balance
     *
     * Returns wallet balance in USD (the canonical balance).
     * Also returns legacy credit balance (deprecated, always 0 for new users).
     */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $walletInfo = $this->wallet->getWalletInfo($user['id']);

        return response()->json([
            // Primary: USD wallet balance
            'wallet_balance' => $walletInfo['wallet']['available_balance'],
            'wallet_currency' => $walletInfo['wallet']['currency'],
            'wallet_status' => $walletInfo['wallet']['status'],
            'lifetime_purchased' => $walletInfo['wallet']['lifetime_purchased'],
            'lifetime_spent' => $walletInfo['wallet']['lifetime_spent'],
            // Legacy: always return 0 for backward compat
            'credits_remaining' => 0,
            'total_purchased' => 0,
        ]);
    }

    /**
     * GET /api/billing/credits/packs
     *
     * Returns active credit packs with USD prices.
     * Packs are pre-defined wallet topup amounts.
     */
    public function packs(): JsonResponse
    {
        $packs = CreditPack::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price_cents')
            ->get()
            ->map(fn($p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'credits'     => $p->credits, // informational only
                'price_cents' => $p->price_cents,
                'price_usd'   => $p->price_cents / 100,
            ]);

        return response()->json(['packs' => $packs]);
    }

    /**
     * POST /api/billing/credits/packs/{id}/purchase
     *
     * Purchase a credit pack → tops up USD wallet via Stripe.
     * The wallet is credited in USD; no separate "credits" currency.
     */
    public function purchase(Request $request, int $id): JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $pack = CreditPack::where('id', $id)->where('is_active', true)->first();
        if (!$pack) return response()->json(['error' => 'Credit pack not found'], 404);

        $amountUsd = $pack->price_cents / 100; // USD amount

        // ── Stripe Checkout for wallet topup ──────────────────────────────────
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET_KEY'));
        try {
            $session = $stripe->checkout->sessions->create([
                'mode'          => 'payment',
                'line_items'    => [[
                    'price_data' => [
                        'currency'     => 'usd',
                        'product_data' => [
                            'name'        => $pack->name,
                            'description' => "Wallet top-up: {$pack->credits} credits (≈ {$amountUsd} USD)",
                        ],
                        'unit_amount'  => $pack->price_cents,
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'user_id'   => (string) $user['id'],
                    'pack_id'   => (string) $pack->id,
                    'pack_name' => $pack->name,
                    'type'      => 'credit_pack_purchase',
                ],
                'success_url' => $request->input('success_url',
                    config('app.url') . '/app/billing?section=wallet&topup=success'),
                'cancel_url'  => $request->input('cancel_url',
                    config('app.url') . '/app/billing?section=wallet&topup=cancelled'),
            ]);

            // Record pending purchase
            CreditPurchase::create([
                'user_id'           => $user['id'],
                'credit_pack_id'    => $pack->id,
                'stripe_session_id' => $session->id,
                'credits_purchased' => $pack->credits,
                'amount_cents'      => $pack->price_cents,
                'status'            => 'pending',
            ]);

            return response()->json([
                'checkout_url' => $session->url,
                'pack_name'    => $pack->name,
                'amount_usd'   => $amountUsd,
                'instant'      => false,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Payment initiation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/billing/credits/webhook
     *
     * Stripe webhook — credits wallet after successful payment.
     * Credit packs top up the wallet in USD.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature');
        $whsec = env('STRIPE_WEBHOOK_SECRET');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sig);
            if (!$event) {
                return response()->json(['error' => 'Webhook verification failed'], 400);
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Webhook verification failed'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $userId = (int) ($session->metadata['user_id'] ?? 0);
            $packId = (int) ($session->metadata['pack_id'] ?? 0);
            $type = $session->metadata['type'] ?? '';

            if ($userId && $type === 'credit_pack_purchase') {
                $pack = CreditPack::find($packId);
                $amountUsd = $pack ? $pack->price_cents / 100 : $session->amount_total / 100;

                // Credit the wallet
                $tx = $this->wallet->topup(
                    $userId,
                    $amountUsd,
                    'stripe',
                    $session->id,
                    'completed',
                    "Wallet top-up via credit pack: " . ($pack->name ?? 'Top-up'),
                );

                // Mark purchase complete
                if ($tx) {
                    CreditPurchase::where('stripe_session_id', $session->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'completed']);
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
