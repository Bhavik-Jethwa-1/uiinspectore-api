<?php

namespace App\Http\Middleware;

use App\Services\Billing\BillingService;
use Closure;

/**
 * CheckUsage middleware — enforces monthly usage limits on API endpoints.
 *
 * Usage in routes (records 1 usage per call):
 *   ->middleware('usage:image_generations')
 *
 * With custom delta (e.g. batch of 5):
 *   ->middleware('usage:ai_generations:5')
 */
class CheckUsage
{
    public function __construct(private BillingService $billing) {}

    public function handle($request, Closure $next, string $feature, int $delta = 1)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Authentication required', 'code' => 'AUTH_REQUIRED'], 401);
        }

        $check = $this->billing->checkUsage($user, $feature, (int) $delta);

        if (!$check['allowed']) {
            return response()->json([
                'error'        => $check['message'],
                'code'         => 'USAGE_LIMIT_EXCEEDED',
                'feature'     => $feature,
                'used'         => $check['used'],
                'limit'        => $check['limit'],
                'remaining'    => 0,
                'percent_used' => $check['percent_used'],
                'upgrade_to'   => $check['upgrade_to'],
                'upgrade_prompt' => [
                    'title'       => 'Monthly Limit Reached',
                    'message'     => "You've used all {$check['used']} of your {$check['limit']} monthly {$feature}.",
                    'benefit'     => 'Upgrade to Pro for unlimited ' . $feature,
                    'cta'         => 'Upgrade Now',
                    'plan'        => 'pro',
                ],
            ], 429); // 429 Too Many Requests
        }

        // Record the usage
        $this->billing->recordUsage($user, $feature, (int) $delta);

        // Attach usage info to request for controller use
        $request->attributes->set('_usage', $check);

        return $next($request);
    }
}
