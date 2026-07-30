<?php

namespace App\Services\Stripe;

use App\Models\User;
use App\Models\Billing\Plan;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Exception;

/**
 * Centralized Stripe service — all Stripe operations MUST go through here.
 * API keys are read exclusively from environment variables.
 * No hardcoded credentials anywhere.
 */
class StripeService
{
    private ?StripeClient $client = null;
    private string $secretKey;
    private string $webhookSecret;
    private string $publishableKey;

    public function __construct()
    {
        $this->secretKey      = env('STRIPE_SECRET_KEY', '');
        $this->webhookSecret  = env('STRIPE_WEBHOOK_SECRET', '');
        $this->publishableKey = env('STRIPE_PUBLISHABLE_KEY', '');
    }

    // ─── Client (lazy) ────────────────────────────────────────────────────────

    public function client(): StripeClient
    {
        if ($this->client === null) {
            $this->client = new StripeClient($this->secretKey);
        }
        return $this->client;
    }

    public function getPublishableKey(): string
    {
        return $this->publishableKey;
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey) && !empty($this->publishableKey);
    }

    public function isWebhookConfigured(): bool
    {
        return !empty($this->webhookSecret) && $this->webhookSecret !== 'whsec_placeholder';
    }

    // ─── Subscription Checkout ─────────────────────────────────────────────────

    /**
     * Get or create a Stripe Price ID for a plan + billing cycle.
     * Prices are created once and reused for all subsequent checkouts.
     * The full plan price is always used — credits are NOT applied here.
     *
     * @param Plan   $plan
     * @param string $billingCycle  'monthly' | 'yearly'
     * @return string  Stripe Price ID
     * @throws \Exception if price creation fails
     */
    private function getOrCreatePrice(Plan $plan, string $billingCycle): string
    {
        $field = $billingCycle === 'yearly' ? 'stripe_yearly_price_id' : 'stripe_monthly_price_id';
        $price = $billingCycle === 'yearly' ? (float) $plan->price_yearly : (float) $plan->price_monthly;
        $interval = $billingCycle === 'yearly' ? 'year' : 'month';

        // Return cached ID if we have one — but verify it exists in Stripe.
        // If Stripe keys changed, the old price ID won't exist in the new account.
        if (!empty($plan->$field)) {
            try {
                $this->client()->prices->retrieve($plan->$field);
                return $plan->$field;
            } catch (\Exception $e) {
                // Price ID from old Stripe account — clear it and recreate
                Log::info("StripeService: Stored price ID not found in Stripe (keys changed?). Recreating.", [
                    'old_price_id' => $plan->$field,
                    'plan_slug' => $plan->slug,
                    'billing_cycle' => $billingCycle,
                ]);
                $plan->$field = null;
            }
        }

        Log::info("StripeService: Creating Stripe Price for plan", [
            'plan_slug'     => $plan->slug,
            'billing_cycle' => $billingCycle,
            'price'         => $price,
        ]);

        // Create a Stripe Product first (if no product exists for this plan)
        // We reuse by storing the price ID directly on the plan record
        $product = $this->client()->products->create([
            'name'        => $plan->name . ' Plan',
            'description' => $plan->description ?: "{$plan->name} - {$billingCycle}ly billing",
            'metadata'    => [
                'plan_slug'     => $plan->slug,
                'billing_cycle' => $billingCycle,
            ],
        ]);

        // Create the Price
        $stripePrice = $this->client()->prices->create([
            'currency'  => 'usd',
            'product'   => $product->id,
            'unit_amount' => (int) ($price * 100),
            'recurring' => [
                'interval' => $interval,
            ],
            'metadata' => [
                'plan_slug'     => $plan->slug,
                'billing_cycle' => $billingCycle,
            ],
        ]);

        // Cache the Price ID on the plan record
        $plan->$field = $stripePrice->id;
        $plan->save();

        Log::info("StripeService: Stripe Price created and cached", [
            'price_id'      => $stripePrice->id,
            'product_id'    => $product->id,
            'plan_slug'     => $plan->slug,
            'billing_cycle' => $billingCycle,
            'amount'        => $price,
        ]);

        return $stripePrice->id;
    }

    /**
     * Create a Stripe Checkout Session for a subscription plan.
     *
     * STRIPE ALWAYS RECEIVES THE FULL PLAN PRICE.
     * Credits are NOT deducted from the Stripe amount.
     * Credits are deducted in verifyCheckout() AFTER successful payment.
     *
     * @param User   $user
     * @param Plan   $plan
     * @param string $billingCycle  'monthly' | 'yearly'
     * @param int    $creditDeduction  Credits to apply AFTER payment (NOT to Stripe)
     */
    public function createSubscriptionSession(
        User   $user,
        Plan   $plan,
        string $billingCycle,
        int    $creditDeduction = 0,
    ): array {
        $price = $billingCycle === 'yearly'
            ? (float) $plan->price_yearly
            : (float) $plan->price_monthly;
        $priceCents = (int) ($price * 100);

        $appUrl = config('app.url', 'http://localhost:8000');

        Log::info("StripeService: Creating subscription session", [
            'user_id'        => $user->id,
            'user_email'     => $user->email,
            'plan_slug'      => $plan->slug,
            'plan_name'      => $plan->name,
            'billing_cycle'  => $billingCycle,
            'price'          => $price,
            'credit_deduction_cents' => $creditDeduction,
            // Stripe ALWAYS receives the full price:
            'stripe_amount'  => $price,
        ]);

        // Get or create the Stripe Price ID (creates once, reuses forever)
        $stripePriceId = $this->getOrCreatePrice($plan, $billingCycle);

        // Get or create a Stripe Customer — this ensures the correct email is used
        // rather than relying on customer_email which can be overridden by Stripe test login
        $stripeCustomerId = $this->getOrCreateCustomer($user);

        // Always use the Price ID — Stripe Checkout shows the exact price from the Price object
        $session = $this->client()->checkout->sessions->create([
            'payment_method_types'      => ['card'],
            'line_items'               => [[
                'price'    => $stripePriceId,
                'quantity' => 1,
            ]],
            'mode'                     => 'subscription',
            'customer'                 => $stripeCustomerId,
            'success_url'              => $appUrl . "/app/pricing?success=true&session_id={CHECKOUT_SESSION_ID}&credits_used={$creditDeduction}",
            'cancel_url'               => $appUrl . "/app/pricing?cancelled=true",
            'billing_address_collection' => 'required',
            'phone_number_collection'  => ['enabled' => true],
            'allow_promotion_codes'    => true,
            'metadata'                 => [
                'user_id'        => (string) $user->id,
                'plan_slug'      => $plan->slug,
                'billing_cycle'  => $billingCycle,
                'credits_to_deduct_after_payment' => (string) $creditDeduction,
            ],
            'subscription_data'        => [
                'metadata' => [
                    'user_id'       => (string) $user->id,
                    'plan_slug'     => $plan->slug,
                    'billing_cycle' => $billingCycle,
                ],
            ],
        ]);

        Log::info("StripeService: Checkout session created", [
            'session_id' => $session->id,
            'user_id'    => $user->id,
            'amount'     => $price,
        ]);

        return [
            'url'               => $session->url,
            'session_id'        => $session->id,
            'price_id'          => $stripePriceId,
            'plan_price'        => $price,
            'credits_to_deduct' => $creditDeduction / 100,
        ];
    }

    // ─── Wallet Topup Checkout ────────────────────────────────────────────────

    /**
     * Create a Stripe Checkout Session for wallet topup.
     *
     * @param User   $user
     * @param float  $amount  In dollars
     */
    public function createWalletTopupSession(User $user, float $amount): array
    {
        $amountCents = (int) ($amount * 100);
        $appUrl = config('app.url', 'http://localhost:8000');

        Log::info("StripeService: Creating wallet topup session", [
            'user_id'  => $user->id,
            'user_email' => $user->email,
            'amount'   => $amount,
        ]);

        $stripeCustomerId = $this->getOrCreateCustomer($user);

        $session = $this->client()->checkout->sessions->create([
            'mode'                      => 'payment',
            'line_items'                => [[
                'price_data' => [
                    'currency'    => 'usd',
                    'product_data' => [
                        'name'        => "Wallet Top-up",
                        'description' => "Add \${$amount} to your UI Inspectore wallet",
                    ],
                    'unit_amount' => $amountCents,
                ],
                'quantity'   => 1,
            ]],
            'customer'                  => $stripeCustomerId,
            'success_url'               => $appUrl . "/app/billing?wallet=success&session_id={CHECKOUT_SESSION_ID}",
            'cancel_url'               => $appUrl . "/app/billing?wallet=cancelled",
            'billing_address_collection' => 'required',
            'phone_number_collection'   => ['enabled' => true],
            'metadata'                  => [
                'user_id' => (string) $user->id,
                'type'    => 'wallet_topup',
                'amount'  => (string) $amount,
            ],
        ]);

        Log::info("StripeService: Wallet topup session created", [
            'session_id' => $session->id,
            'user_id'    => $user->id,
            'amount'     => $amount,
        ]);

        return [
            'checkout_url' => $session->url,
            'session_id'  => $session->id,
        ];
    }

    // ─── Session Retrieval ─────────────────────────────────────────────────────

    /**
     * Retrieve a checkout session by ID.
     */
    public function getSession(string $sessionId): object
    {
        return $this->client()->checkout->sessions->retrieve($sessionId);
    }

    /**
     * Retrieve a subscription by ID.
     */
    public function getSubscription(string $subscriptionId): object
    {
        return $this->client()->subscriptions->retrieve($subscriptionId);
    }

    // ─── Webhook Verification ─────────────────────────────────────────────────

    /**
     * Verify and construct a Stripe webhook event.
     * Returns null if verification fails.
     */
    public function constructWebhookEvent(string $payload, string $signature): ?object
    {
        if (!$this->isWebhookConfigured()) {
            Log::warning("StripeService: Webhook secret not configured — skipping verification");
            return null;
        }

        try {
            return \Stripe\Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (\Exception $e) {
            Log::error("StripeService: Webhook signature verification failed", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─── Customer Management ──────────────────────────────────────────────────

    /**
     * Get or create a Stripe customer for a user.
     */
    // ─── Subscription Management ────────────────────────────────────────────────

    /**
     * Update a Stripe subscription's price (plan change or billing cycle change).
     * Handles subscriptions with multiple items by:
     * 1. Deleting any item that already uses the target price (orphan)
     * 2. Updating the remaining item to the new price
     * This avoids "price already in use" errors from Stripe.
     */
    public function updateSubscription(
        string $stripeSubscriptionId,
        string $newPriceId,
        string $billingCycle,
    ): object {
        Log::info("StripeService: Updating subscription", [
            'subscription_id' => $stripeSubscriptionId,
            'new_price_id'   => $newPriceId,
            'billing_cycle'  => $billingCycle,
        ]);

        $subscription = $this->client()->subscriptions->retrieve($stripeSubscriptionId);
        $items = $subscription->items->data;

        // Identify: which item has the target price (to delete), which has other price (to update)
        $itemToUpdateId = null;
        $itemToDeleteId = null;

        foreach ($items as $item) {
            if ($item->price->id === $newPriceId) {
                // This item already uses the target price — delete it (orphaned)
                $itemToDeleteId = $item->id;
            } else {
                // This item has a different price — update it to the new price
                $itemToUpdateId = $item->id;
            }
        }

        // Step 1: Delete the item that already has the target price FIRST
        // (Stripe blocks updates when another item uses the target price)
        if ($itemToDeleteId) {
            try {
                $this->client()->subscriptionItems->delete($itemToDeleteId);
                Log::info("StripeService: Deleted orphaned subscription item", [
                    'deleted_item_id' => $itemToDeleteId,
                    'subscription_id' => $stripeSubscriptionId,
                ]);
            } catch (\Exception $e) {
                Log::warning("StripeService: Could not delete orphaned item", [
                    'item_id' => $itemToDeleteId,
                    'error'   => $e->getMessage(),
                ]);
                // Continue anyway — try to update the other item
            }
        }

        // Step 2: Update the remaining item to the new price
        if ($itemToUpdateId) {
            return $this->client()->subscriptions->update($stripeSubscriptionId, [
                'items' => [[
                    'id'       => $itemToUpdateId,
                    'price'    => $newPriceId,
                    'quantity' => 1,
                ]],
                'proration_behavior'  => 'create_prorations',
                'billing_cycle_anchor' => 'now',
            ]);
        }

        // No other item to update — create a new item
        return $this->client()->subscriptions->update($stripeSubscriptionId, [
            'items' => [[
                'price'    => $newPriceId,
                'quantity' => 1,
            ]],
            'proration_behavior'  => 'create_prorations',
            'billing_cycle_anchor' => 'now',
        ]);
    }

    /**
     * Cancel a subscription immediately.
     */
    public function cancelSubscription(string $stripeSubscriptionId): object
    {
        Log::info("StripeService: Cancelling subscription immediately", [
            'subscription_id' => $stripeSubscriptionId,
        ]);
        return $this->client()->subscriptions->cancel($stripeSubscriptionId);
    }

    /**
     * Cancel a subscription at the end of the current billing period.
     */
    public function cancelSubscriptionAtPeriodEnd(string $stripeSubscriptionId): object
    {
        Log::info("StripeService: Cancelling subscription at period end", [
            'subscription_id' => $stripeSubscriptionId,
        ]);
        return $this->client()->subscriptions->update($stripeSubscriptionId, [
            'cancel_at_period_end' => true,
        ]);
    }

    /**
     * Resume a subscription that was set to cancel at period end.
     */
    public function resumeSubscription(string $stripeSubscriptionId): object
    {
        return $this->client()->subscriptions->update($stripeSubscriptionId, [
            'cancel_at_period_end' => false,
        ]);
    }

    /**
     * Preview proration for a plan change.
     * Returns the amount that will be charged or credited.
     */
    public function previewProration(
        string $stripeSubscriptionId,
        string $newPriceId,
    ): array {
        $sub       = $this->client()->subscriptions->retrieve($stripeSubscriptionId);
        $itemId    = $sub->items->data[0]->id ?? null;
        if (!$itemId) return ['amount' => 0, 'description' => 'No item found'];

        $preview = $this->client()->invoices->createPreview([
            'customer' => $sub->customer,
            'subscription' => $stripeSubscriptionId,
            'subscription_details' => [
                'items' => [[
                    'price' => $newPriceId,
                    'quantity' => 1,
                ]],
                'proration_behavior' => 'create_prorations',
            ],
        ]);

        return [
            'amount'     => ($preview->amount_due ?? 0) / 100,
            'currency'  => $preview->currency ?? 'usd',
            'subtotal'   => ($preview->subtotal ?? 0) / 100,
            'description' => 'Proration preview',
        ];
    }

    public function getOrCreateCustomer(User $user): string
    {
        // If we have a stored customer ID, verify it still exists under this Stripe account.
        // If Stripe keys were changed (e.g. different Stripe account), the old customer ID
        // will return "No such customer" — in that case, create a new customer.
        if (!empty($user->stripe_customer_id)) {
            try {
                $this->client()->customers->retrieve($user->stripe_customer_id);
                return $user->stripe_customer_id;
            } catch (\Exception $e) {
                // Customer ID from old Stripe account — create a new one
                Log::info("StripeService: Stored customer ID not found in Stripe (keys changed?). Creating new customer.", [
                    'old_customer_id' => $user->stripe_customer_id,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ]);
                $user->stripe_customer_id = null;
            }
        }

        $customer = $this->client()->customers->create([
            'email' => $user->email,
            'metadata' => ['user_id' => (string) $user->id],
        ]);

        $user->stripe_customer_id = $customer->id;
        $user->save();

        Log::info("StripeService: New Stripe customer created", [
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'user_email' => $user->email,
        ]);

        return $customer->id;
    }
}
