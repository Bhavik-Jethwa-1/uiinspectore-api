<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\{Plan, Subscription, Payment, Invoice};
use App\Services\Billing\{BillingService, UsageService, WalletService, BillingServiceLocator, SubscriptionManager};
use App\Services\Stripe\StripeService;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Billing API — subscription, plans, usage, and account management.
 *
 * SEPARATION OF CONCERNS:
 *   Subscription → WHAT user can access (features, limits, permissions)
 *   Wallet       → HOW MUCH AI usage user can consume (USD credits)
 *
 * These two systems are COMPLETELY independent.
 */
class BillingController extends Controller
{
    public function __construct(
        private BillingService $billing,
        private UsageService   $usage,
        private WalletService  $wallet,
        private StripeService  $stripe,
        private SubscriptionManager $subManager,
    ) {}

    // ─── Plans ─────────────────────────────────────────────────────────────

    public function plans(): JsonResponse
    {
        $plans = $this->billing->getAllActivePlans();
        $data = array_map(fn($plan) => $this->formatPlan($plan), $plans);
        return response()->json(['plans' => $data]);
    }

    public function plan(string $slug): JsonResponse
    {
        $plan = $this->billing->getPlan($slug);
        if (!$plan) return response()->json(['error' => 'Plan not found'], 404);
        return response()->json(['plan' => $this->formatPlan($plan)]);
    }

    // ─── Subscription ─────────────────────────────────────────────────────

    public function subscription(Request $req): JsonResponse
    {
        $user = $req->user();
        $sub  = $this->billing->getSubscription($user);
        $limits = $this->billing->getUserLimits($user);

        return response()->json([
            'subscription' => $sub ? [
                'id'              => $sub->id,
                'plan'            => $sub->plan?->name,
                'slug'            => $sub->getPlanSlug(),
                'status'          => $sub->status,
                'billing_cycle'   => $sub->billing_cycle,
                'amount'          => (float) $sub->amount,
                'currency'        => $sub->currency,
                'period_start'    => $sub->current_period_start?->toIso8601String(),
                'period_end'      => $sub->current_period_end?->toIso8601String(),
                'cancel_at_end'   => $sub->cancel_at_period_end,
                'is_active'       => $sub->isActive(),
            ] : null,
            'limits' => $limits,
        ]);
    }

    public function subscribe(Request $req): JsonResponse
    {
        $req->validate([
            'plan_slug'      => 'required|string',
            'billing_cycle'  => 'required|in:monthly,yearly',
            'payment_token'  => 'nullable|string',
        ]);

        $plan = $this->billing->getPlan($req->plan_slug);
        if (!$plan) return response()->json(['error' => 'Plan not found'], 404);

        $user = $req->user();

        // Free plan — just subscribe directly
        if ($plan->price_monthly == 0) {
            $sub = $this->billing->subscribe($user, $plan, $req->billing_cycle);
            return response()->json(['subscription' => $sub, 'success' => true]);
        }

        // TODO: Process payment via Stripe/Razorpay here
        // For now, return payment_required to trigger frontend payment flow
        return response()->json([
            'error'   => 'Payment required',
            'code'    => 'PAYMENT_REQUIRED',
            'plan'    => $this->formatPlan($plan),
            'amount'  => (float) ($req->billing_cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly),
            'currency'=> 'USD',
            'payment_flow' => 'stripe', // or 'razorpay', 'paypal'
        ], 402);
    }

    /**
     * POST /api/billing/create-checkout
     *
     * CRITICAL: Stripe ALWAYS receives the FULL plan price.
     * Credits are deducted AFTER successful payment in verifyCheckout().
     * This ensures Stripe Checkout always shows the exact plan price.
     */
    public function createCheckoutSession(Request $req): JsonResponse
    {
        $req->validate([
            'plan_slug'     => 'required|string',
            'billing_cycle'=> 'required|in:monthly,yearly',
        ]);

        $plan = $this->billing->getPlan($req->plan_slug);
        if (!$plan) return response()->json(['error' => 'Plan not found'], 404);
        if ($plan->price_monthly == 0) {
            return response()->json(['error' => 'Free plan does not need checkout'], 400);
        }

        $user = $req->user();

        $price = $req->billing_cycle === 'yearly'
            ? (float) $plan->price_yearly
            : (float) $plan->price_monthly;
        $priceCents = (int) ($price * 100);

        // Compute credit deduction (for post-payment deduction — NOT sent to Stripe)
        $creditBalance = (int) (\DB::table('user_credits')->where('user_id', $user->id)->value('credits_remaining') ?? 0);
        $creditDeduction = min($creditBalance, $priceCents); // capped at plan price
        $creditDollars = $creditDeduction / 100;

        // If credits cover the FULL price, subscribe directly without Stripe
        if ($creditBalance >= $priceCents) {
            $sub = $this->billing->subscribe($user, $plan, $req->billing_cycle);
            if ($creditDeduction > 0) {
                \DB::table('user_credits')->where('user_id', $user->id)
                    ->decrement('credits_remaining', $creditDeduction);
            }
            return response()->json([
                'url'        => null,
                'session_id' => null,
                'credits_used' => $creditDeduction,
                'subscription' => [
                    'id'            => $sub->id ?? 'free',
                    'plan'          => $plan->name,
                    'slug'          => $plan->slug,
                    'status'        => 'active',
                    'billing_cycle' => $req->billing_cycle,
                    'amount'        => $price,
                    'credits_applied' => $creditDollars,
                ],
            ]);
        }

        // Stripe always receives the FULL plan price — credits applied AFTER payment
        try {
            $result = $this->stripe->createSubscriptionSession(
                $user,
                $plan,
                $req->billing_cycle,
                $creditDeduction, // for audit log only, NOT subtracted from Stripe amount
            );

            return response()->json([
                'url'        => $result['url'],
                'session_id' => $result['session_id'],
                // Full plan price — this is what Stripe charges:
                'plan_price' => $price,
                // Credits that will be deducted from wallet AFTER successful payment:
                'credits_to_deduct' => $creditDollars,
                'credits_available' => $creditDollars,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create checkout session: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function verifyCheckout(Request $req): JsonResponse
    {
        $req->validate(['session_id' => 'required|string']);

        try {
            $session = $this->stripe->getSession($req->session_id);

            if ($session->payment_status !== 'paid') {
                return response()->json(['error' => 'Payment not completed', 'status' => $session->payment_status], 402);
            }

            $userId     = $session->metadata['user_id'] ?? null;
            $planSlug   = $session->metadata['plan_slug'] ?? null;
            $billingCycle = $session->metadata['billing_cycle'] ?? 'monthly';

            if (!$planSlug) {
                return response()->json(['error' => 'Invalid session metadata'], 400);
            }

            // Always use the authenticated user — not the metadata user_id.
            // The metadata user_id may differ from the JWT userId due to email-based
            // user resolution (e.g. userId=3 in JWT resolves to userId=1 by email).
            $user = $req->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $plan = $this->billing->getPlan($planSlug);
            if (!$plan) return response()->json(['error' => 'Plan not found'], 404);

            // Activate the subscription
            $sub = $this->billing->subscribe($user, $plan, $billingCycle);

            // Deduct credits AFTER successful payment (Stripe already received the full plan price)
            $creditsUsed = (int) ($session->metadata['credits_to_deduct_after_payment'] ?? 0);
            if ($creditsUsed > 0) {
                \DB::table('user_credits')->where('user_id', $user->id)
                    ->decrement('credits_remaining', $creditsUsed);
            }

            $creditsDollars = $creditsUsed / 100;

            return response()->json([
                'success'      => true,
                'subscription' => [
                    'id'            => $sub->id,
                    'plan'          => $sub->plan?->name,
                    'slug'          => $sub->getPlanSlug(),
                    'status'        => $sub->status,
                    'billing_cycle' => $sub->billing_cycle,
                    'amount'        => (float) $sub->amount,
                    'period_end'    => $sub->current_period_end?->toIso8601String(),
                ],
                'credits_used'     => $creditsUsed,
                'credits_applied'  => $creditsDollars,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Verification failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/billing/portal
     * Opens the Stripe Billing Portal for the authenticated user.
     * Users can manage payment methods, invoices, and cancel/change their plan.
     */
    public function billingPortal(Request $req): JsonResponse
    {
        $user      = $req->user();
        $returnUrl = $req->input('return_url', config('app.url') . '/app/billing');

        $portalUrl = $this->subManager->createBillingPortalSession($user, $returnUrl);

        if (!$portalUrl) {
            return response()->json([
                'error' => 'Could not create billing portal session. Ensure your account has a billing history.',
            ], 400);
        }

        return response()->json(['url' => $portalUrl]);
    }

    public function cancel(Request $req): JsonResponse
    {
        $user = $req->user();
        $sub  = $this->billing->getSubscription($user);
        if (!$sub) return response()->json(['error' => 'No active subscription'], 400);

        $immediately = $req->boolean('immediately', false);

        // Use Stripe API if available
        if (!empty($sub->provider_subscription_id)) {
            $this->subManager->cancelViaStripe($user, $immediately);
        } else {
            $this->billing->cancelSubscription($user, $immediately);
        }

        return response()->json([
            'success'   => true,
            'cancelled' => $immediately ? now()->toIso8601String() : null,
            'cancel_at_period_end' => !$immediately,
            'period_end' => $immediately ? null : $sub->fresh()->current_period_end?->toIso8601String(),
            'message'    => $immediately
                ? 'Subscription cancelled immediately'
                : 'Subscription will cancel at the end of the current billing period',
        ]);
    }

    public function resume(Request $req): JsonResponse
    {
        $sub = $this->billing->resumeSubscription($req->user());
        if (!$sub) return response()->json(['error' => 'No subscription to resume'], 400);

        return response()->json([
            'success' => true,
            'message' => 'Subscription resumed successfully',
        ]);
    }

    /**
     * POST /api/billing/change-plan
     *
     * Changes the user's subscription plan or billing cycle.
     *
     * Priority:
     *  1. If user has an existing Stripe subscription → UPDATE it via Stripe API (no new checkout)
     *  2. If no Stripe subscription → Create a new Checkout Session
     *  3. If Stripe API update fails → Fall back to checkout
     *
     * The frontend can show a "Manage Subscription" button that opens the billing portal
     * for a full Stripe-hosted experience.
     */
    public function changePlan(Request $req): JsonResponse
    {
        $req->validate([
            'plan_slug'     => 'required|string',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $user = $req->user();
        $plan = $this->billing->getPlan($req->plan_slug);
        if (!$plan) return response()->json(['error' => 'Plan not found'], 404);

        // Validate plan+billing_cycle is not the same as current
        $currentSub = $this->billing->getSubscription($user);
        if ($currentSub && $currentSub->plan?->slug === $plan->slug
            && $currentSub->billing_cycle === $req->billing_cycle) {
            return response()->json(['error' => 'You are already on this plan'], 400);
        }

        // Allow billing cycle change even if same plan slug (e.g. Pro Monthly → Pro Yearly)
        // The check above rejects only exact same plan+cycle combinations

        // Try Stripe API first (no new checkout needed)
        // changePlanViaStripe handles looking up the Stripe subscription if provider_subscription_id is missing
        if ($currentSub) {
            $result = $this->subManager->changePlanViaStripe($user, $plan, $req->billing_cycle);

            if (!$result['requires_checkout']) {
                $updatedSub = $result['subscription'];
                return response()->json([
                    'success'     => true,
                    'subscription' => [
                        'plan'           => $plan->name,
                        'slug'           => $plan->slug,
                        'billing_cycle'  => $updatedSub->billing_cycle,
                        'amount'         => (float) $updatedSub->amount,
                        'period_start'   => $updatedSub->current_period_start?->toIso8601String(),
                        'period_end'     => $updatedSub->current_period_end?->toIso8601String(),
                    ],
                    'via' => 'stripe_api',
                    'proration' => $result['proration'] ?? null,
                ]);
            }

            // Stripe API failed — fall through to checkout
            Log::warning('BillingController: Stripe API plan change failed, falling back to checkout', [
                'user_id' => $user->id,
                'reason'  => $result['reason'] ?? 'unknown',
            ]);
        }

        // Fall back: create a new checkout session
        $result = $this->stripe->createSubscriptionSession(
            $user,
            $plan,
            $req->billing_cycle,
        );

        return response()->json([
            'success'        => false,
            'requires_action' => true,
            'checkout_url'   => $result['url'],
            'session_id'     => $result['session_id'],
            'plan_price'     => $result['plan_price'],
            'credits_to_deduct' => $result['credits_to_deduct'],
        ]);
    }

    // ─── Usage ────────────────────────────────────────────────────────────

    public function usage(Request $req): JsonResponse
    {
        $user = $req->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        try {
            $usage  = $this->usage->getAllUsage($user);
            $limits = $this->billing->getUserLimits($user);

            $result = [];
            foreach ($usage as $feature => $data) {
                $result[$feature] = array_merge($data ?? [], [
                    'label'     => UsageService::featureLabel($feature),
                    'unlimited' => ($data['limit'] ?? 0) === -1,
                ]);
            }

            return response()->json([
                'usage'  => $result,
                'limits' => $limits,
                'period' => [
                    'start' => $this->usage->currentPeriod($user)['start']->toIso8601String(),
                    'end'   => $this->usage->currentPeriod($user)['end']->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Usage data unavailable'], 500);
        }
    }

    public function checkFeature(Request $req, string $feature): JsonResponse
    {
        $check = $this->billing->checkFeature($req->user(), $feature);
        return response()->json($check);
    }

    public function checkUsage(Request $req, string $feature): JsonResponse
    {
        $check = $this->billing->checkUsage($req->user(), $feature);
        return response()->json($check);
    }

    // ─── Payments ──────────────────────────────────────────────────────────

    public function payments(Request $req): JsonResponse
    {
        $payments = Payment::where('user_id', $req->get('auth_user')['id'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($p) => [
                'id'       => $p->id,
                'type'     => $p->type,
                'amount'   => (float) $p->amount,
                'currency' => $p->currency,
                'status'   => $p->status,
                'provider' => $p->provider,
                'created_at' => $p->created_at->toIso8601String(),
            ]);

        return response()->json(['payments' => $payments]);
    }

    public function invoices(Request $req): JsonResponse
    {
        $userId = $req->get('auth_user')['id'];

        // Plan subscription invoices
        $invoiceRows = Invoice::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        $invoices = [];
        foreach ($invoiceRows as $inv) {
            $invoices[] = [
                'id'            => 'inv_' . $inv->id,
                'invoice_number'=> $inv->invoice_number,
                'status'        => $inv->status,
                'subtotal'      => (float) $inv->subtotal,
                'tax'           => (float) $inv->tax,
                'total'         => (float) $inv->total,
                'currency'      => $inv->currency,
                'pdf_url'       => $inv->pdf_url,
                'paid_at'       => $inv->paid_at?->toIso8601String(),
                'created_at'    => $inv->created_at?->toIso8601String(),
                'description'   => ucfirst($inv->invoice_number ?: 'Subscription'),
            ];
        }

        // Credit pack purchases
        $purchaseRows = \DB::table('credit_purchases')
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        $purchases = [];
        foreach ($purchaseRows as $p) {
            $purchases[] = [
                'id'             => 'crd_' . $p->id,
                'invoice_number' => 'CRD-' . str_pad($p->id, 5, '0', STR_PAD_LEFT),
                'status'         => 'paid',
                'subtotal'       => round($p->amount_cents / 100, 2),
                'tax'            => 0,
                'total'          => round($p->amount_cents / 100, 2),
                'currency'       => 'USD',
                'pdf_url'        => null,
                'paid_at'        => $p->updated_at,
                'created_at'     => $p->created_at,
                'description'    => $p->credits_purchased . ' Credits',
            ];
        }

        // Merge and sort by date descending
        $all = collect($invoices)->merge($purchases)
            ->sortByDesc(fn($item) => $item['created_at'])
            ->values()
            ->take(20)
            ->all();

        return response()->json(['invoices' => $all]);
    }

    // ─── Dashboard ────────────────────────────────────────────────────────

    public function dashboard(Request $req): JsonResponse
    {
        $data = $this->billing->getBillingDashboard($req->user());

        // Add wallet data — wallet is completely separate from subscription
        $walletInfo = $this->wallet->getWalletInfo($req->user()->id);
        $data['wallet'] = $walletInfo['wallet'];
        $data['wallet']['auto_recharge'] = $walletInfo['auto_recharge'];

        return response()->json($data);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function formatPlan(Plan $plan): array
    {
        $featureFlags = is_array($plan->features) ? $plan->features : [];
        $features = [];
        foreach ($featureFlags as $key => $enabled) {
            if ($enabled) {
                $features[] = self::FEATURE_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key));
            }
        }

        return [
            'id'           => $plan->id,
            'name'         => $plan->name,
            'slug'         => $plan->slug,
            'description'  => $plan->description,
            'price_monthly'=> (float) $plan->price_monthly,
            'price_yearly' => (float) $plan->price_yearly,
            'limits'       => $plan->limits,
            'features'     => $features,
            'is_active'    => $plan->is_active,
            'is_free'      => $plan->price_monthly == 0,
        ];
    }

    private const FEATURE_LABELS = [
        'ai_autodesigner'       => 'AI Autodesigner',
        'ai_chat'              => 'AI Chat',
        'ai_product_consultant'=> 'AI Product Consultant',
        'image_generation'     => 'Image Generation',
        'screenshot_analysis' => 'Screenshot Analysis',
        'api_access'           => 'API Access',
        'projects'             => 'Projects',
        'screens'              => 'Screens',
        'exports'              => 'Exports',
        'templates'            => 'Templates',
        'team_members'         => 'Team Members',
        'team_billing'         => 'Team Billing',
        'team_usage_analytics' => 'Team Usage Analytics',
        'webhooks'             => 'Webhooks',
        'sso_ready'            => 'SSO Ready',
        'white_label'          => 'White Label',
        'custom_branding'      => 'Custom Branding',
        'dedicated_support'    => 'Dedicated Support',
        'custom_onboarding'    => 'Custom Onboarding',
        'enterprise_security'  => 'Enterprise Security',
        'figma_export'        => 'Figma Export',
        'react_export'         => 'React Export',
        'nextjs_export'        => 'Next.js Export',
        'vue_export'           => 'Vue Export',
        'unlimited_history'    => 'Unlimited History',
        'audit_logs'          => 'Audit Logs',
    ];
}
