<?php

namespace App\Services\Billing;

use App\Models\Billing\{Plan, Subscription, FeatureUsage, SubscriptionHistory, Payment, Invoice};
use App\Models\User;
use App\Services\Billing\UsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Core billing service — handles plan lookups, subscriptions, and feature gating.
 * All permission checks are server-side only.
 */
class BillingService
{
    public function __construct(private UsageService $usage) {}

    public function getUsageService(): UsageService
    {
        return $this->usage;
    }

    // ─── Plan Gating ────────────────────────────────────────────────────────

    public function getPlan(string $slug): ?Plan
    {
        return Plan::where('slug', $slug)->where('is_active', true)->first();
    }

    public function getAllActivePlans(): array
    {
        return Plan::where('is_active', true)->orderBy('sort_order')->get()->all();
    }

    public function getPlanLimits(Plan $plan): array
    {
        return [
            'ai_generations'   => $plan->getLimit('ai_generations', -1),
            'image_generations' => $plan->getLimit('image_generations', -1),
            'ai_chat'          => $plan->getLimit('ai_chat', -1),
            'screenshot_analysis' => $plan->getLimit('screenshot_analysis', -1),
            'projects'         => $plan->getLimit('projects', -1),
            'exports'          => $plan->getLimit('exports', -1),
            'templates'        => $plan->getLimit('templates', -1),
            'storage_mb'       => $plan->getLimit('storage_mb', 100),
            'team_members'      => $plan->getLimit('team_members', 1),
            'history_days'      => $plan->getLimit('history_days', 7),
            'ai_autodesigner'   => $plan->hasFeature('ai_autodesigner'),
            'ai_redesign'       => $plan->hasFeature('ai_redesign'),
            'api_access'        => $plan->hasFeature('api_access'),
            'white_label'       => $plan->hasFeature('white_label'),
            'custom_branding'   => $plan->hasFeature('custom_branding'),
            'figma_export'     => $plan->hasFeature('figma_export'),
            'react_export'      => $plan->hasFeature('react_export'),
            'nextjs_export'    => $plan->hasFeature('nextjs_export'),
            'vue_export'       => $plan->hasFeature('vue_export'),
            'unlimited_history' => $plan->hasFeature('unlimited_history'),
            'team_workspace'   => $plan->hasFeature('team_workspace'),
        ];
    }

    // ─── Subscription ─────────────────────────────────────────────────────

    public function getSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->orderByDesc('created_at')
            ->first();
    }

    public function getDefaultPlan(): Plan
    {
        return Plan::where('slug', 'free')->firstOrFail();
    }

    public function getUserPlanSlug(User $user): string
    {
        return $this->getSubscription($user)?->getPlanSlug() ?? 'free';
    }

    public function getUserLimits(User $user): array
    {
        $sub = $this->getSubscription($user);
        if (!$sub || !$sub->plan) return $this->getPlanLimits($this->getDefaultPlan());
        return $this->getPlanLimits($sub->plan);
    }

    // ─── Feature Check ──────────────────────────────────────────────────────

    public function hasFeature(User $user, string $feature): bool
    {
        $sub = $this->getSubscription($user);
        return $sub?->getFeature($feature) ?? $this->getDefaultPlan()->hasFeature($feature);
    }

    public function checkFeature(User $user, string $feature): array
    {
        $has = $this->hasFeature($user, $feature);
        $planSlug = $this->getUserPlanSlug($user);
        return [
            'allowed'    => $has,
            'feature'   => $feature,
            'plan'      => $planSlug,
            'message'   => $has ? null : "This feature requires the Pro plan. Your current plan is {$planSlug}.",
            'upgrade_to' => $has ? null : 'pro',
        ];
    }

    // ─── Usage Check ────────────────────────────────────────────────────────

    public function checkUsage(User $user, string $feature, int $increment = 1): array
    {
        $limits = $this->getUserLimits($user);
        $limit  = $limits[$feature] ?? -1;
        $used   = $this->usage->getUsage($user, $feature);
        $remaining = $limit === -1 ? PHP_INT_MAX : max(0, $limit - $used);

        if ($limit !== -1 && ($used + $increment) > $limit) {
            return [
                'allowed'    => false,
                'feature'    => $feature,
                'used'      => $used,
                'limit'     => $limit,
                'remaining' => 0,
                'message'   => "Monthly limit reached for {$feature}. Upgrade to Pro for unlimited usage.",
                'upgrade_to' => 'pro',
                'percent_used' => $limit > 0 ? min(100, ($used / $limit) * 100) : 0,
            ];
        }

        return [
            'allowed'    => true,
            'feature'    => $feature,
            'used'       => $used,
            'limit'      => $limit,
            'remaining'  => $remaining - $increment,
            'percent_used' => $limit > 0 ? min(100, ($used / $limit) * 100) : 0,
        ];
    }

    public function recordUsage(User $user, string $feature, int $delta = 1): FeatureUsage
    {
        return $this->usage->record($user, $feature, $delta);
    }

    // ─── Subscribe ─────────────────────────────────────────────────────────

    public function subscribe(User $user, Plan $plan, string $billingCycle = 'monthly', array $meta = []): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $billingCycle, $meta) {
            // Cancel existing active subscriptions
            $user->subscriptions()->whereIn('status', ['active', 'trialing'])->update([
                'status' => 'cancelled', 'cancelled_at' => now(),
            ]);

            $price = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
            $periodStart = now();
            $periodEnd   = $billingCycle === 'yearly'
                ? now()->addYear()->startOfDay()
                : now()->addMonth()->startOfDay();

            $sub = Subscription::create([
                'user_id'    => $user->id,
                'plan_id'   => $plan->id,
                'status'    => 'active',
                'billing_cycle' => $billingCycle,
                'amount'    => $price,
                'currency'  => 'USD',
                'current_period_start' => $periodStart,
                'current_period_end'   => $periodEnd,
                'provider'  => $meta['provider'] ?? null,
                'provider_subscription_id' => $meta['provider_subscription_id'] ?? null,
            ]);

            SubscriptionHistory::create([
                'user_id' => $user->id,
                'subscription_id' => $sub->id,
                'action'  => 'created',
                'to_plan' => $plan->slug,
                'amount'  => $price,
                'metadata' => ['billing_cycle' => $billingCycle],
            ]);

            return $sub;
        });
    }

    public function cancelSubscription(User $user, bool $immediately = false): ?Subscription
    {
        return DB::transaction(function () use ($user, $immediately) {
            $sub = $this->getSubscription($user);
            if (!$sub) return null;

            if ($immediately) {
                $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            } else {
                $sub->update(['cancel_at_period_end' => true]);
            }

            SubscriptionHistory::create([
                'user_id' => $user->id,
                'subscription_id' => $sub->id,
                'action'  => 'cancelled',
                'from_plan' => $sub->plan?->slug,
                'metadata' => ['immediately' => $immediately],
            ]);

            return $sub;
        });
    }

    public function resumeSubscription(User $user): ?Subscription
    {
        return DB::transaction(function () use ($user) {
            $sub = $user->subscriptions()->where('cancel_at_period_end', true)->first();
            if (!$sub) return null;

            $sub->update(['cancel_at_period_end' => false]);

            SubscriptionHistory::create([
                'user_id' => $user->id,
                'subscription_id' => $sub->id,
                'action'  => 'resumed',
                'to_plan' => $sub->plan?->slug,
            ]);

            return $sub;
        });
    }

    public function changePlan(User $user, Plan $newPlan, string $billingCycle = 'monthly'): Subscription
    {
        return DB::transaction(function () use ($user, $newPlan, $billingCycle) {
            $oldSub = $this->getSubscription($user);
            $oldPlanSlug = $oldSub?->plan?->slug ?? 'free';

            $sub = $this->subscribe($user, $newPlan, $billingCycle, [
                'provider' => $oldSub?->provider,
                'provider_subscription_id' => $oldSub?->provider_subscription_id,
            ]);

            SubscriptionHistory::create([
                'user_id' => $user->id,
                'subscription_id' => $sub->id,
                'action'  => $newPlan->price_monthly > ($oldSub?->amount ?? 0) ? 'upgraded' : 'downgraded',
                'from_plan' => $oldPlanSlug,
                'to_plan'   => $newPlan->slug,
                'amount'    => $newPlan->price_monthly,
            ]);

            return $sub;
        });
    }

    // ─── Usage Reset (cron-called monthly) ───────────────────────────────

    public function resetUsageForNewPeriod(User $user): void
    {
        $this->usage->resetForNewPeriod($user);
    }

    // ─── Dashboard Data ────────────────────────────────────────────────────

    public function getBillingDashboard(User $user): array
    {
        $sub    = $this->getSubscription($user);
        $limits = $this->getUserLimits($user);
        $usage  = $this->usage->getAllUsage($user);
        $plan   = $sub?->plan;

        return [
            'subscription' => $sub ? [
                'plan'         => $plan?->name ?? 'Free',
                'slug'         => $sub->getPlanSlug(),
                'status'       => $sub->status,
                'billing_cycle'=> $sub->billing_cycle,
                'amount'       => (float) $sub->amount,
                'currency'     => $sub->currency,
                'period_start' => $sub->current_period_start?->toIso8601String(),
                'period_end'   => $sub->current_period_end?->toIso8601String(),
                'cancel_at_end'=> $sub->cancel_at_period_end,
                'is_active'    => $sub->isActive(),
            ] : null,
            'limits'       => $limits,
            'usage'        => $usage,
            'mrr'          => 0,
        ];
    }
}
