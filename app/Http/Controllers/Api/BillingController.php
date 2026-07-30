<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BillingController extends \Illuminate\Routing\Controller
{
    /**
     * Plan presets with monthly pricing and quota limits.
     * Persisted in user billing files under database/billing/.
     */
    private array $plans = [
        'free' => [
            'name' => 'Free',
            'price_monthly' => 0,
            'currency' => 'USD',
            'limits' => [
                'analyses' => 5,
                'screenshots' => 10,
                'projects' => 1,
                'team_members' => 1,
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'price_monthly' => 19,
            'currency' => 'USD',
            'limits' => [
                'analyses' => 100,
                'screenshots' => 500,
                'projects' => 10,
                'team_members' => 1,
            ],
        ],
        'team' => [
            'name' => 'Team',
            'price_monthly' => 49,
            'currency' => 'USD',
            'limits' => [
                'analyses' => 500,
                'screenshots' => 2500,
                'projects' => 50,
                'team_members' => 10,
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price_monthly' => 199,
            'currency' => 'USD',
            'limits' => [
                'analyses' => -1, // unlimited
                'screenshots' => -1,
                'projects' => -1,
                'team_members' => -1,
            ],
        ],
    ];

    /**
     * Per-user subscription/invoice storage directory.
     */
    private function billingPath(int $userId): string
    {
        $dir = base_path('database/billing');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . "/user_{$userId}.json";
        if (!file_exists($path)) {
            file_put_contents($path, json_encode([
                'subscription' => $this->defaultSubscription('free'),
                'invoices' => [],
            ], JSON_PRETTY_PRINT));
        }
        return $path;
    }

    /**
     * Default subscription payload for a given plan.
     */
    private function defaultSubscription(string $plan): array
    {
        $preset = $this->plans[$plan] ?? $this->plans['free'];
        return [
            'plan' => $plan,
            'status' => $plan === 'free' ? 'active' : 'inactive',
            'started_at' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'trial_ends_at' => null,
            'payment_method' => null,
            'price_monthly' => $preset['price_monthly'],
            'currency' => $preset['currency'],
            'limits' => $preset['limits'],
        ];
    }

    /**
     * Read billing record for the authenticated user.
     */
    private function loadBilling(int $userId): array
    {
        $path = $this->billingPath($userId);
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return ['subscription' => $this->defaultSubscription('free'), 'invoices' => []];
        }
        if (!isset($data['subscription'])) {
            $data['subscription'] = $this->defaultSubscription('free');
        }
        if (!isset($data['invoices']) || !is_array($data['invoices'])) {
            $data['invoices'] = [];
        }
        return $data;
    }

    /**
     * Persist billing record for the authenticated user.
     */
    private function saveBilling(int $userId, array $data): void
    {
        $path = $this->billingPath($userId);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Count screenshots for a user by scanning their projects file.
     */
    private function countScreenshotsUsed(int $userId): int
    {
        $path = base_path("database/uizard/user_{$userId}.json");
        if (!file_exists($path)) return 0;
        $data = json_decode(file_get_contents($path), true) ?? [];
        $count = 0;
        foreach ($data['projects'] ?? [] as $p) {
            foreach ($p['screens'] ?? [] as $s) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Count AI analyses for a user (stored in analyses directory).
     */
    private function countAnalysesUsed(int $userId): int
    {
        $path = base_path("database/analyses/user_{$userId}.json");
        if (!file_exists($path)) return 0;
        $data = json_decode(file_get_contents($path), true) ?? [];
        return count($data['analyses'] ?? []);
    }

    /**
     * Build usage stats for current period.
     */
    private function buildUsage(int $userId, int $userProjects): array
    {
        $billing = $this->loadBilling($userId);
        $subscription = $billing['subscription'];
        $limits = $subscription['limits'] ?? $this->plans['free']['limits'];

        $analysesUsed = $this->countAnalysesUsed($userId);
        $screenshotsUsed = $this->countScreenshotsUsed($userId);
        $projectsUsed = $userProjects;

        $pct = function (int $used, int $limit): float {
            if ($limit === -1) return 0.0;
            if ($limit === 0) return 100.0;
            return round(min(100.0, ($used / $limit) * 100), 2);
        };

        return [
            'analyses' => [
                'used' => $analysesUsed,
                'limit' => $limits['analyses'],
                'remaining' => $limits['analyses'] === -1 ? -1 : max(0, $limits['analyses'] - $analysesUsed),
                'percent_used' => $pct($analysesUsed, $limits['analyses']),
            ],
            'screenshots' => [
                'used' => $screenshotsUsed,
                'limit' => $limits['screenshots'],
                'remaining' => $limits['screenshots'] === -1 ? -1 : max(0, $limits['screenshots'] - $screenshotsUsed),
                'percent_used' => $pct($screenshotsUsed, $limits['screenshots']),
            ],
            'projects' => [
                'used' => $projectsUsed,
                'limit' => $limits['projects'],
                'remaining' => $limits['projects'] === -1 ? -1 : max(0, $limits['projects'] - $projectsUsed),
                'percent_used' => $pct($projectsUsed, $limits['projects']),
            ],
            'period_start' => $subscription['current_period_start'] ?? null,
            'period_end' => $subscription['current_period_end'] ?? null,
        ];
    }

    /**
     * Build the public subscription payload (sans sensitive payment data).
     */
    private function publicSubscription(array $subscription): array
    {
        $plan = $subscription['plan'] ?? 'free';
        $preset = $this->plans[$plan] ?? $this->plans['free'];
        return [
            'plan' => $plan,
            'plan_name' => $preset['name'],
            'status' => $subscription['status'] ?? 'inactive',
            'price_monthly' => $subscription['price_monthly'] ?? $preset['price_monthly'],
            'currency' => $subscription['currency'] ?? $preset['currency'],
            'limits' => $subscription['limits'] ?? $preset['limits'],
            'started_at' => $subscription['started_at'] ?? null,
            'current_period_start' => $subscription['current_period_start'] ?? null,
            'current_period_end' => $subscription['current_period_end'] ?? null,
            'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
            'canceled_at' => $subscription['canceled_at'] ?? null,
            'trial_ends_at' => $subscription['trial_ends_at'] ?? null,
            'payment_method' => $subscription['payment_method'] ?? null,
        ];
    }

    /**
     * Generate an invoice for a billing period.
     */
    private function buildInvoice(string $plan, string $periodStart, string $periodEnd, array $usage): array
    {
        $preset = $this->plans[$plan] ?? $this->plans['free'];
        $subtotal = $preset['price_monthly'];
        $tax = round($subtotal * 0.08, 2);
        $total = round($subtotal + $tax, 2);

        return [
            'id' => 'inv_' . bin2hex(random_bytes(6)),
            'number' => 'UI-' . date('Y') . '-' . str_pad((string) random_int(1000, 99999), 5, '0', STR_PAD_LEFT),
            'plan' => $plan,
            'plan_name' => $preset['name'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'issued_at' => date('Y-m-d H:i:s'),
            'status' => 'paid',
            'currency' => $preset['currency'],
            'line_items' => [
                [
                    'description' => $preset['name'] . ' subscription',
                    'quantity' => 1,
                    'unit_price' => $preset['price_monthly'],
                    'amount' => $preset['price_monthly'],
                ],
            ],
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'usage_snapshot' => [
                'analyses_used' => $usage['analyses']['used'] ?? 0,
                'screenshots_used' => $usage['screenshots']['used'] ?? 0,
                'projects_used' => $usage['projects']['used'] ?? 0,
            ],
        ];
    }

    /**
     * GET /api/billing
     * Return the current user's subscription.
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $billing = $this->loadBilling((int) $user['id']);
        $subscription = $billing['subscription'] ?? $this->defaultSubscription('free');

        return response()->json([
            'success' => true,
            'data' => $this->publicSubscription($subscription),
        ]);
    }

    /**
     * POST /api/billing/subscribe
     * Subscribe the current user to a paid plan.
     */
    public function subscribe(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'plan' => 'required|string|in:pro,team,enterprise',
            'payment_method' => 'sometimes|array',
            'trial' => 'sometimes|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $plan = $request->input('plan');
        $isTrial = (bool) $request->input('trial', false);
        $paymentMethod = $request->input('payment_method');

        $billing = $this->loadBilling((int) $user['id']);
        $existing = $billing['subscription'] ?? $this->defaultSubscription('free');

        // If already on this plan and active, nothing to do.
        if ($existing['plan'] === $plan && in_array($existing['status'], ['active', 'trialing'], true)) {
            return response()->json([
                'success' => true,
                'data' => $this->publicSubscription($existing),
                'message' => 'Already subscribed to this plan',
            ]);
        }

        $preset = $this->plans[$plan];
        $now = time();
        $periodStart = date('Y-m-d H:i:s', $now);
        $periodEnd = date('Y-m-d H:i:s', strtotime('+30 days', $now));
        $trialEnd = $isTrial ? date('Y-m-d H:i:s', strtotime('+14 days', $now)) : null;

        $subscription = [
            'plan' => $plan,
            'status' => $isTrial ? 'trialing' : 'active',
            'started_at' => $periodStart,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'trial_ends_at' => $trialEnd,
            'payment_method' => $paymentMethod ?: ($existing['payment_method'] ?? null),
            'price_monthly' => $preset['price_monthly'],
            'currency' => $preset['currency'],
            'limits' => $preset['limits'],
        ];

        $billing['subscription'] = $subscription;

        // Issue first invoice for the new subscription period unless trial.
        if (!$isTrial) {
            $projectsPath = base_path("database/uizard/user_{$user['id']}.json");
            $projects = 0;
            if (file_exists($projectsPath)) {
                $projectsData = json_decode(file_get_contents($projectsPath), true) ?? [];
                $projects = count($projectsData['projects'] ?? []);
            }
            $usage = $this->buildUsage((int) $user['id'], $projects);
            $billing['invoices'][] = $this->buildInvoice($plan, $periodStart, $periodEnd, $usage);
        }

        $this->saveBilling((int) $user['id'], $billing);

        return response()->json([
            'success' => true,
            'data' => $this->publicSubscription($subscription),
            'message' => $isTrial ? 'Trial started' : 'Subscribed successfully',
        ]);
    }

    /**
     * POST /api/billing/cancel
     * Cancel current subscription at period end.
     */
    public function cancel(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $billing = $this->loadBilling((int) $user['id']);
        $subscription = $billing['subscription'] ?? $this->defaultSubscription('free');

        if ($subscription['plan'] === 'free' || $subscription['status'] === 'inactive') {
            return response()->json([
                'success' => true,
                'data' => $this->publicSubscription($subscription),
                'message' => 'No active paid subscription',
            ]);
        }

        $subscription['cancel_at_period_end'] = true;
        $subscription['canceled_at'] = date('Y-m-d H:i:s');

        $billing['subscription'] = $subscription;
        $this->saveBilling((int) $user['id'], $billing);

        return response()->json([
            'success' => true,
            'data' => $this->publicSubscription($subscription),
            'message' => 'Subscription will be canceled at the end of the current period',
        ]);
    }

    /**
     * GET /api/billing/invoices
     * List invoices for the authenticated user.
     */
    public function invoices(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $billing = $this->loadBilling((int) $user['id']);
        $invoices = $billing['invoices'] ?? [];

        // Newest first.
        usort($invoices, fn($a, $b) => ($b['issued_at'] ?? '') <=> ($a['issued_at'] ?? ''));

        return response()->json([
            'success' => true,
            'data' => array_values($invoices),
            'meta' => [
                'count' => count($invoices),
                'total' => array_sum(array_column($invoices, 'total')),
            ],
        ]);
    }

    /**
     * GET /api/billing/usage
     * Current usage vs plan limits.
     */
    public function usage(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $billing = $this->loadBilling((int) $user['id']);
        $subscription = $billing['subscription'] ?? $this->defaultSubscription('free');

        $projectsPath = base_path("database/uizard/user_{$user['id']}.json");
        $projects = 0;
        if (file_exists($projectsPath)) {
            $projectsData = json_decode(file_get_contents($projectsPath), true) ?? [];
            $projects = count($projectsData['projects'] ?? []);
        }

        $usage = $this->buildUsage((int) $user['id'], $projects);

        return response()->json([
            'success' => true,
            'data' => [
                'plan' => $subscription['plan'],
                'usage' => $usage,
            ],
        ]);
    }
}
