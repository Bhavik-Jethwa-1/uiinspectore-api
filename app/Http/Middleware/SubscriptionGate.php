<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\AccessControlService;
use App\Services\Billing\BillingServiceLocator;
use App\Services\Billing\BillingService;
use App\Services\Billing\WalletService;

/**
 * SubscriptionGate Middleware — enforces feature-level access control.
 *
 * Apply per-route to protect premium features.
 *
 * Usage in routes:
 *   Route::post('/ai/chat', [AIController::class, 'chat'])
 *       ->middleware('subscription:ai_chat');
 *
 *   Route::post('/ai/image', [AIController::class, 'generateImage'])
 *       ->middleware('subscription:ai_image_generation');
 *
 * Checks performed (in order):
 *   1. Valid authenticated user
 *   2. Feature is in user's subscription plan
 *   3. User is within their usage limits
 *
 * Does NOT check wallet — wallet is checked at execution time in AIUsageService.
 */
class SubscriptionGate
{
    private AccessControlService $access;

    public function __construct()
    {
        $this->access = new AccessControlService(
            BillingServiceLocator::billing(),
            BillingServiceLocator::wallet(),
            BillingServiceLocator::aiUsage(),
        );
    }

    public function handle(Request $request, Closure $next, string ...$features)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'error' => 'Authentication required',
                'code' => 'unauthenticated',
            ], 401);
        }

        // Check each required feature
        foreach ($features as $feature) {
            $access = $this->access->checkFeatureAccess($user, $feature);
            if (!$access['allowed']) {
                return response()->json([
                    'error' => 'Feature not available',
                    'code' => $access['reason'],
                    'message' => $access['message'],
                    'current_plan' => $access['current_plan'],
                    'upgrade_required' => true,
                    'upgrade_url' => '/app/pricing',
                ], 403);
            }

            $limitCheck = $this->access->checkUsageLimit($user, $feature);
            if (!$limitCheck['within_limit']) {
                return response()->json([
                    'error' => 'Usage limit exceeded',
                    'code' => $limitCheck['reason'],
                    'message' => $limitCheck['message'],
                    'feature' => $feature,
                    'used' => $limitCheck['used'],
                    'limit' => $limitCheck['limit'],
                    'remaining' => $limitCheck['remaining'],
                    'upgrade_required' => true,
                    'upgrade_url' => '/app/pricing',
                ], 429); // 429 Too Many Requests (rate limit style for usage limits)
            }
        }

        return $next($request);
    }
}
