<?php

namespace App\Http\Middleware;

use App\Services\Billing\BillingService;
use Closure;

/**
 * CheckFeature middleware — gates API endpoints by subscription plan feature.
 *
 * Usage in routes:
 *   ->middleware('feature:ai_autodesigner')
 *   ->middleware('feature:api_access')
 */
class CheckFeature
{
    public function __construct(private BillingService $billing) {}

    public function handle($request, Closure $next, string $feature)
    {
        $user = $request->user();

        // Unauthenticated users get free tier
        if (!$user) {
            return response()->json([
                'error' => 'Authentication required',
                'code'  => 'AUTH_REQUIRED',
            ], 401);
        }

        $check = $this->billing->checkFeature($user, $feature);

        if (!$check['allowed']) {
            return response()->json([
                'error'      => $check['message'],
                'code'       => 'FEATURE_NOT_ALLOWED',
                'feature'    => $feature,
                'plan'       => $check['plan'],
                'upgrade_to' => $check['upgrade_to'],
            ], 403);
        }

        return $next($request);
    }
}
