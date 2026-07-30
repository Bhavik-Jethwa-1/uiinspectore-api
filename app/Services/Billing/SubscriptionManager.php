<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Wallet;
use App\Services\Stripe\StripeService;
use App\Services\Billing\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manages Stripe subscription lifecycle operations.
 * All plan changes, cancellations, and billing portal operations go through here.
 */
class SubscriptionManager
{
    public function __construct(
        private StripeService $stripe,
        private BillingService $billing,
        private WalletService $wallet,
    ) {}

    // ─── Billing Portal ────────────────────────────────────────────────────────

    /**
     * Create a Stripe Billing Portal session for the user.
     * Allows users to manage their subscription, payment methods, invoices.
     */
    public function createBillingPortalSession(User $user, string $returnUrl): ?string
    {
        if (!$user->stripe_customer_id) {
            Log::warning('SubscriptionManager: No Stripe customer ID for user', ['user_id' => $user->id]);
            return null;
        }

        try {
            $session = $this->stripe->client()->billingPortal->sessions->create([
                'customer'   => $user->stripe_customer_id,
                'return_url' => $returnUrl,
            ]);

            Log::info('SubscriptionManager: Billing portal session created', [
                'user_id' => $user->id,
                'portal_session_id' => $session->id,
            ]);

            return $session->url;
        } catch (\Exception $e) {
            Log::error('SubscriptionManager: Failed to create billing portal session', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─── Plan Change (via Stripe API) ─────────────────────────────────────────

    /**
     * Change the user's plan via Stripe's subscription update API.
     * Used for: plan upgrade/downgrade, billing cycle change.
     * When user already has a Stripe subscription, we UPDATE it rather than creating a new one.
     */
    public function changePlanViaStripe(
        User   $user,
        Plan   $newPlan,
        string $billingCycle,
    ): array {
        $sub = $this->billing->getSubscription($user);

        // No Stripe subscription yet — create one via checkout
        if (!$sub) {
            return ['requires_checkout' => true, 'reason' => 'no_subscription_record'];
        }

        // If provider_subscription_id is missing, try to find it from Stripe
        // This handles cases where the subscription was created before we tracked the ID
        if (empty($sub->provider_subscription_id) && !empty($user->stripe_customer_id)) {
            try {
                $stripeSubs = $this->stripe->client()->subscriptions->all([
                    'customer' => $user->stripe_customer_id,
                    'status'   => 'active',
                    'limit'    => 1,
                ]);
                if (!empty($stripeSubs->data[0])) {
                    $stripeSubId = $stripeSubs->data[0]->id;
                    $sub->update(['provider_subscription_id' => $stripeSubId]);
                    $sub->refresh(); // Refresh to get the updated value
                    Log::info('SubscriptionManager: Linked Stripe subscription to local record', [
                        'user_id'       => $user->id,
                        'stripe_sub_id'  => $stripeSubId,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('SubscriptionManager: Could not look up Stripe subscription', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if (empty($sub->provider_subscription_id)) {
            return ['requires_checkout' => true, 'reason' => 'no_stripe_subscription'];
        }

        try {
            // Get the Stripe Price ID for the new plan
            $stripePriceId = $this->getStripePriceId($newPlan, $billingCycle);
            if (!$stripePriceId) {
                return ['requires_checkout' => true, 'reason' => 'no_stripe_price'];
            }

            // Update Stripe subscription with proration
            $updatedSub = $this->stripe->updateSubscription(
                $sub->provider_subscription_id,
                $stripePriceId,
                $billingCycle,
            );

            // Capture old values BEFORE updating (needed for proration calculation)
            $oldPeriodEnd   = $sub->current_period_end;
            $oldPeriodStart = $sub->current_period_start;
            $oldAmount      = (float) $sub->amount;
            $wasYearlyBilling = $sub->billing_cycle === 'yearly';

            // Update local subscription record
            $price = $billingCycle === 'yearly'
                ? (float) $newPlan->price_yearly
                : (float) $newPlan->price_monthly;

            $periodEnd = $billingCycle === 'yearly'
                ? now()->addYear()->startOfDay()
                : now()->addMonth()->startOfDay();

            $oldPlanSlug = $sub->plan?->slug ?? 'free';
            $isUpgrade = $newPlan->price_monthly > ($sub->amount ?? 0);

            $sub->update([
                'plan_id'              => $newPlan->id,
                'billing_cycle'        => $billingCycle,
                'amount'               => $price,
                'current_period_start' => now(),
                'current_period_end'   => $periodEnd,
                'status'               => 'active',
            ]);

            // Record history
            \App\Models\Billing\SubscriptionHistory::create([
                'user_id'             => $user->id,
                'subscription_id'     => $sub->id,
                'action'              => $isUpgrade ? 'upgraded' : 'downgraded',
                'from_plan'           => $oldPlanSlug,
                'to_plan'             => $newPlan->slug,
                'amount'              => $price,
                'metadata'            => [
                    'billing_cycle' => $billingCycle,
                    'stripe_subscription_id' => $sub->provider_subscription_id,
                    'via' => 'stripe_api',
                ],
            ]);

            // ── Wallet-first billing: credit unused yearly days, then charge next cycle from wallet ──
            // Stripe is charged by Stripe for the current switch. Wallet covers future cycles.
            $walletCredit = 0.0;
            $plan = $sub->plan ?? $newPlan;
            $nowMonthly = $billingCycle === 'monthly';

            if ($wasYearlyBilling && $nowMonthly
                && $oldPeriodEnd && $oldPeriodEnd > now()
                && $oldAmount >= 100
            ) {
                $daysTotal = $oldPeriodStart->diffInDays($oldPeriodEnd) ?: 365;
                $daysUsed = $oldPeriodStart->diffInDays(now());
                $daysRemaining = max(0, $daysTotal - $daysUsed);

                if ($daysRemaining > 0 && $daysTotal > 0) {
                    $dailyRate = $oldAmount / $daysTotal;
                    $walletCredit = round($dailyRate * $daysRemaining, 2);

                    if ($walletCredit > 0) {
                        try {
                            $this->wallet->credit(
                                $user->id,
                                $walletCredit,
                                'proration_refund',
                                "Unused days from {$plan->slug} yearly plan switch to monthly",
                                [
                                    'days_remaining' => $daysRemaining,
                                    'days_total'     => $daysTotal,
                                    'from_plan'      => $oldPlanSlug,
                                    'to_plan'        => $newPlan->slug,
                                    'stripe_sub_id'  => $sub->provider_subscription_id,
                                ],
                            );
                            Log::info('SubscriptionManager: Proration credit added to wallet', [
                                'user_id'       => $user->id,
                                'credit_amount' => $walletCredit,
                                'days_remaining'=> $daysRemaining,
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('SubscriptionManager: Failed to credit wallet', [
                                'user_id' => $user->id,
                                'error'   => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // ── Compute next billing details for wallet-first display ─────────────────────────
            $newPrice = $billingCycle === 'yearly'
                ? (float) $newPlan->price_yearly
                : (float) $newPlan->price_monthly;
            $walletBalance = Wallet::where('user_id', $user->id)->value('balance') ?? 0.0;
            $walletBalanceAfterCredit = round($walletBalance + $walletCredit, 2);
            $walletCoversNext = $walletBalanceAfterCredit >= $newPrice;
            $stripeShortfall  = $walletCoversNext ? 0.0 : round($newPrice - $walletBalanceAfterCredit, 2);

            Log::info('SubscriptionManager: Plan changed via Stripe API', [
                'user_id'    => $user->id,
                'from_plan'  => $oldPlanSlug,
                'to_plan'    => $newPlan->slug,
                'cycle'      => $billingCycle,
                'stripe_sub' => $sub->provider_subscription_id,
                'wallet_credit' => $walletCredit,
                'wallet_balance_after_credit' => $walletBalanceAfterCredit,
                'wallet_covers_next' => $walletCoversNext,
            ]);

            return [
                'requires_checkout' => false,
                'subscription'      => $sub->fresh(),
                'proration' => [
                    'credit_added'    => $walletCredit,
                    'wallet_balance'  => $walletBalanceAfterCredit,
                    'next_billing_on' => $sub->current_period_end?->toDateString(),
                    'next_billing_amount' => $newPrice,
                    'wallet_covers_next_cycle' => $walletCoversNext,
                    'stripe_will_charge' => $stripeShortfall,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('SubscriptionManager: Failed to change plan via Stripe', [
                'user_id' => $user->id,
                'new_plan' => $newPlan->slug,
                'error'    => $e->getMessage(),
            ]);
            // Fallback: require checkout
            return ['requires_checkout' => true, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Cancel the user's Stripe subscription.
     * @param bool $immediately If true, cancel now. If false, cancel at period end.
     */
    public function cancelViaStripe(User $user, bool $immediately = false): bool
    {
        $sub = $this->billing->getSubscription($user);
        if (!$sub || empty($sub->provider_subscription_id)) {
            return false;
        }

        try {
            if ($immediately) {
                $this->stripe->cancelSubscription($sub->provider_subscription_id);
            } else {
                $this->stripe->cancelSubscriptionAtPeriodEnd($sub->provider_subscription_id);
            }

            $sub->update([
                'cancel_at_period_end' => !$immediately,
                'cancelled_at'         => $immediately ? now() : null,
            ]);

            \App\Models\Billing\SubscriptionHistory::create([
                'user_id'         => $user->id,
                'subscription_id' => $sub->id,
                'action'          => 'cancelled',
                'from_plan'       => $sub->plan?->slug,
                'metadata'        => [
                    'immediately'              => $immediately,
                    'stripe_subscription_id'   => $sub->provider_subscription_id,
                ],
            ]);

            Log::info('SubscriptionManager: Subscription cancelled', [
                'user_id'     => $user->id,
                'immediately' => $immediately,
                'stripe_sub'  => $sub->provider_subscription_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('SubscriptionManager: Failed to cancel via Stripe', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ─── Sync from Stripe Webhook ─────────────────────────────────────────────

    /**
     * Handle Stripe subscription.updated event.
     * Syncs local subscription state with Stripe.
     */
    public function syncFromStripeEvent(object $stripeSub): ?Subscription
    {
        $customerId = $stripeSub->customer ?? null;
        if (!$customerId) return null;

        $user = User::where('stripe_customer_id', $customerId)->first();
        if (!$user) {
            Log::warning('SubscriptionManager: No user for Stripe customer', ['customer_id' => $customerId]);
            return null;
        }

        $localSub = $this->billing->getSubscription($user);
        if (!$localSub) return null;

        $status = $this->mapStripeStatus($stripeSub->status ?? 'active');
        $cancelAtPeriodEnd = $stripeSub->cancel_at_period_end ?? false;

        $localSub->update([
            'status'                => $status,
            'cancel_at_period_end'  => $cancelAtPeriodEnd,
            'current_period_start'  => $stripeSub->current_period_start
                ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_start)
                : null,
            'current_period_end'    => $stripeSub->current_period_end
                ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end)
                : null,
        ]);

        Log::info('SubscriptionManager: Synced from Stripe event', [
            'user_id'  => $user->id,
            'status'   => $status,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ]);

        return $localSub->fresh();
    }

    /**
     * Handle Stripe subscription.deleted event.
     */
    public function handleDeleted(User $user): void
    {
        $sub = $this->billing->getSubscription($user);
        if (!$sub) return;

        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        \App\Models\Billing\SubscriptionHistory::create([
            'user_id'         => $user->id,
            'subscription_id' => $sub->id,
            'action'          => 'expired',
            'from_plan'       => $sub->plan?->slug,
            'metadata'        => ['via' => 'stripe_webhook'],
        ]);

        Log::info('SubscriptionManager: Subscription deleted (webhook)', ['user_id' => $user->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getStripePriceId(Plan $plan, string $billingCycle): ?string
    {
        $field = $billingCycle === 'yearly' ? 'stripe_yearly_price_id' : 'stripe_monthly_price_id';
        $priceId = $plan->$field ?? null;

        if ($priceId) return $priceId;

        // Try to create one on the fly
        $this->stripe->getOrCreatePrice($plan, $billingCycle);
        return $plan->fresh()->$field;
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active', 'trialing'        => 'active',
            'past_due', 'unpaid'        => 'past_due',
            'canceled', 'cancelled'     => 'cancelled',
            'incomplete', 'incomplete_expired' => 'incomplete',
            default                     => $stripeStatus,
        };
    }
}
