<?php

namespace App\Services;

use App\Models\User;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Billing\FeatureUsage;
use App\Services\Billing\BillingService;
use App\Services\Billing\WalletService;
use App\Services\Billing\AIUsageService;
use Illuminate\Support\Facades\Log;

/**
 * AccessControlService — central gatekeeper for ALL feature access.
 *
 * This service implements the STRICT separation between:
 *   SUBSCRIPTION → WHAT the user is allowed to access (features, limits, permissions)
 *   WALLET       → HOW MUCH AI usage the user can consume (USD credits)
 *
 * RULES (enforced in this order):
 *   1. Check if feature is unlocked by subscription plan
 *   2. Check if user has not exceeded usage limits
 *   3. Check if wallet has sufficient balance for AI features
 *
 * These checks are INDEPENDENT and BOTH must pass for AI features.
 */
class AccessControlService
{
    public function __construct(
        private BillingService $billing,
        private WalletService $wallet,
        private AIUsageService $aiUsage,
    ) {}

    // ─── Subscription Feature Access ─────────────────────────────────────────

    /**
     * Check if user's subscription allows a specific feature.
     * Returns [allowed, reason, details]
     */
    public function checkFeatureAccess(User $user, string $feature): array
    {
        try {
            $hasFeature = $this->billing->hasFeature($user, $feature);
        } catch (\Throwable $e) {
            Log::error('AccessControlService.hasFeature error', [
                'user_id' => $user?->id,
                'user_class' => get_class($user),
                'feature' => $feature,
                'error' => $e->getMessage(),
            ]);
            // Fail open — allow access, let the AI controller handle actual auth
            return [
                'allowed' => true,
                'reason' => 'error_fallback',
                'message' => null,
                'current_plan' => 'unknown',
                'feature' => $feature,
                'upgrade_required' => false,
            ];
        }

        if (!$hasFeature) {
            $planSlug = $this->billing->getUserPlanSlug($user);
            $plan = $this->billing->getPlan($planSlug);
            return [
                'allowed' => false,
                'reason' => 'feature_not_in_plan',
                'message' => "This feature requires a higher plan. Please upgrade to access.",
                'current_plan' => $planSlug,
                'feature' => $feature,
                'upgrade_required' => true,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'message' => null,
            'current_plan' => $this->billing->getUserPlanSlug($user),
            'feature' => $feature,
            'upgrade_required' => false,
        ];
    }

    /**
     * Check if user is within their usage limits for a feature.
     * Returns [within_limit, reason, details]
     */
    public function checkUsageLimit(User $user, string $feature, int $delta = 1): array
    {
        $limits = $this->billing->getUserLimits($user);
        $limit = $limits[$feature] ?? null;

        // -1 = unlimited, null = no limit defined = allowed
        if ($limit === null || $limit === -1) {
            return [
                'within_limit' => true,
                'reason' => null,
                'message' => null,
                'feature' => $feature,
                'used' => 0,
                'limit' => $limit,
                'remaining' => PHP_INT_MAX,
            ];
        }

        $usage = $this->billing->getUsageService()->getUsage($user, $feature);
        $remaining = max(0, $limit - $usage);

        if (($usage + $delta) > $limit) {
            return [
                'within_limit' => false,
                'reason' => 'usage_limit_exceeded',
                'message' => "Monthly limit reached for {$feature}. Upgrade for more.",
                'feature' => $feature,
                'used' => $usage,
                'limit' => $limit,
                'remaining' => 0,
                'upgrade_required' => true,
            ];
        }

        return [
            'within_limit' => true,
            'reason' => null,
            'message' => null,
            'feature' => $feature,
            'used' => $usage,
            'limit' => $limit,
            'remaining' => $remaining,
            'upgrade_required' => false,
        ];
    }

    // ─── Full AI Access Check (subscription + wallet combined) ─────────────

    /**
     * Full access check for an AI feature — checks BOTH subscription AND wallet.
     *
     * Returns:
     *   [PASS]  → subscription OK, limit OK, wallet OK → proceed to AI
     *   [FAIL]  → clear reason why access was denied
     */
    public function canUseAI(
        User $user,
        string $provider,
        string $model,
        string $feature,
        int $inputTokens = 0,
        int $outputTokens = 0,
    ): array {
        // STEP 1: Subscription feature check
        $featureAccess = $this->checkFeatureAccess($user, $feature);
        if (!$featureAccess['allowed']) {
            return [
                'allowed' => false,
                'stage' => 'subscription_feature',
                'reason' => $featureAccess['reason'],
                'message' => $featureAccess['message'],
                'current_plan' => $featureAccess['current_plan'],
                'upgrade_required' => true,
            ];
        }

        // STEP 2: Usage limit check
        $limitCheck = $this->checkUsageLimit($user, $feature);
        if (!$limitCheck['within_limit']) {
            return [
                'allowed' => false,
                'stage' => 'subscription_limit',
                'reason' => $limitCheck['reason'],
                'message' => $limitCheck['message'],
                'used' => $limitCheck['used'],
                'limit' => $limitCheck['limit'],
                'remaining' => $limitCheck['remaining'],
                'upgrade_required' => true,
            ];
        }

        // STEP 3: Wallet balance check
        $walletCheck = $this->aiUsage->canAfford($user->id, $provider, $model, $feature, $inputTokens, $outputTokens);
        if (!$walletCheck['can_afford']) {
            return [
                'allowed' => false,
                'stage' => 'wallet',
                'reason' => 'insufficient_wallet_balance',
                'message' => 'Insufficient wallet balance. Please add funds to continue.',
                'cost' => $walletCheck['cost'],
                'available_balance' => $walletCheck['available_balance'],
                'shortage' => $walletCheck['shortage'],
                'is_low_balance' => $walletCheck['is_low_balance'],
                'wallet_balance_url' => '/app/billing?section=wallet',
                'upgrade_required' => false, // NOT a plan upgrade issue
                'recharge_required' => true,  // wallet topup needed
            ];
        }

        return [
            'allowed' => true,
            'stage' => 'passed',
            'reason' => null,
            'cost' => $walletCheck['cost'],
            'available_balance' => $walletCheck['available_balance'],
            'current_plan' => $featureAccess['current_plan'],
        ];
    }

    // ─── Quick Helpers ───────────────────────────────────────────────────────

    /**
     * Get complete access summary for a user.
     */
    public function getAccessSummary(User $user): array
    {
        $subscription = $this->billing->getSubscription($user);
        $planSlug = $this->billing->getUserPlanSlug($user);
        $limits = $this->billing->getUserLimits($user);
        $wallet = $this->wallet->getWalletInfo($user->id);

        return [
            'subscription' => [
                'plan' => $planSlug,
                'status' => $subscription?->status ?? 'none',
                'billing_cycle' => $subscription?->billing_cycle ?? null,
                'current_period_end' => $subscription?->current_period_end?->toIso8601String() ?? null,
                'is_active' => $subscription ? in_array($subscription->status, ['active', 'trialing']) : ($planSlug === 'free'),
            ],
            'wallet' => [
                'balance' => $wallet['wallet']['balance'],
                'available_balance' => $wallet['wallet']['available_balance'],
                'reserved_balance' => $wallet['wallet']['reserved_balance'],
                'currency' => $wallet['wallet']['currency'],
                'status' => $wallet['wallet']['status'],
                'is_low_balance' => $wallet['wallet']['available_balance'] < 2.00,
            ],
            'limits' => $limits,
        ];
    }

    /**
     * Get all features with their access status for the current user.
     */
    public function getAllFeatureAccess(User $user): array
    {
        $planSlug = $this->billing->getUserPlanSlug($user);
        $limits = $this->billing->getUserLimits($user);

        $features = [
            'ai_chat'               => ['label' => 'AI Chat', 'category' => 'ai'],
            'ai_vision'             => ['label' => 'AI Vision', 'category' => 'ai'],
            'ai_ui_review'          => ['label' => 'AI UI Review', 'category' => 'ai'],
            'ai_image_generation'    => ['label' => 'Image Generation', 'category' => 'ai'],
            'ai_research'           => ['label' => 'AI Research', 'category' => 'ai'],
            'ai_autodesigner'        => ['label' => 'AI Auto Designer', 'category' => 'ai'],
            'ai_redesign'           => ['label' => 'AI Redesign', 'category' => 'ai'],
            'ai_detect'             => ['label' => 'AI Detect', 'category' => 'ai'],
            'prompt_optimizer'       => ['label' => 'Prompt Optimizer', 'category' => 'ai'],
            'api_access'             => ['label' => 'API Access', 'category' => 'permissions'],
            'white_label'            => ['label' => 'White Label', 'category' => 'branding'],
            'custom_branding'        => ['label' => 'Custom Branding', 'category' => 'branding'],
            'figma_export'          => ['label' => 'Figma Export', 'category' => 'export'],
            'react_export'           => ['label' => 'React Export', 'category' => 'export'],
            'nextjs_export'         => ['label' => 'Next.js Export', 'category' => 'export'],
            'vue_export'             => ['label' => 'Vue Export', 'category' => 'export'],
            'team_workspace'         => ['label' => 'Team Workspace', 'category' => 'team'],
            'unlimited_history'      => ['label' => 'Unlimited History', 'category' => 'storage'],
        ];

        $result = [];
        foreach ($features as $key => $meta) {
            $hasFeature = $this->billing->hasFeature($user, $key);
            $limit = $limits[$key] ?? null;
            $usage = $limit !== null && $limit !== -1
                ? $this->billing->getUsageService()->getUsage($user, $key)
                : null;

            $result[$key] = [
                ...$meta,
                'enabled' => $hasFeature,
                'limit' => $limit,
                'used' => $usage,
                'remaining' => $usage !== null ? max(0, $limit - $usage) : null,
                'unlimited' => $limit === -1 || $limit === null,
            ];
        }

        return [
            'plan' => $planSlug,
            'features' => $result,
        ];
    }
}
