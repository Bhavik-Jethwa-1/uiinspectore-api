<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Models\AIUsage;
use App\Models\AIPricing;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminBillingController extends Controller
{
    /** GET /api/admin/billing/dashboard */
    public function dashboard(): \Illuminate\Http\JsonResponse
    {
        $today = Carbon::today();
        $monthStart = Carbon::today()->startOfMonth();

        // Wallet stats
        $totalWalletBalance = (float) Wallet::sum('balance');
        $totalReserved = (float) Wallet::sum('reserved_balance');
        $totalLifetimeSpent = (float) Wallet::sum('lifetime_spent');
        $totalLifetimePurchased = (float) Wallet::sum('lifetime_purchased');

        // Revenue stats
        $todayRevenue = (float) WalletTransaction::where('type', 'topup')
            ->whereDate('created_at', $today)
            ->where('status', 'completed')
            ->sum('amount');

        $monthRevenue = (float) WalletTransaction::where('type', 'topup')
            ->whereDate('created_at', '>=', $monthStart)
            ->where('status', 'completed')
            ->sum('amount');

        // Topup stats
        $pendingPayments = WalletTopup::where('payment_status', 'pending')->count();
        $failedPayments = WalletTopup::where('payment_status', 'failed')->count();
        $refundsTotal = (float) WalletTransaction::where('type', 'refund')->sum('amount');

        // AI Usage stats
        $todayAICost = (float) AIUsage::whereDate('created_at', $today)
            ->where('status', 'success')
            ->sum('cost');
        $monthAICost = (float) AIUsage::whereDate('created_at', '>=', $monthStart)
            ->where('status', 'success')
            ->sum('cost');
        $totalAICalls = AIUsage::where('status', 'success')->count();

        // Revenue by provider
        $revenueByProvider = AIUsage::where('status', 'success')
            ->select('provider', DB::raw('SUM(cost) as total_cost'), DB::raw('COUNT(*) as total_calls'))
            ->groupBy('provider')
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'total_cost' => (float) $r->total_cost,
                'total_calls' => (int) $r->total_calls,
            ]);

        // Revenue by feature
        $revenueByFeature = AIUsage::where('status', 'success')
            ->select('feature', DB::raw('SUM(cost) as total_cost'), DB::raw('COUNT(*) as total_calls'))
            ->groupBy('feature')
            ->get()
            ->map(fn ($r) => [
                'feature' => $r->feature,
                'total_cost' => (float) $r->total_cost,
                'total_calls' => (int) $r->total_calls,
            ]);

        // Top spending users
        $topSpendingUsers = Wallet::with('user:id,name,email')
            ->orderByDesc('lifetime_spent')
            ->take(10)
            ->get()
            ->map(fn ($w) => [
                'user_id' => $w->user_id,
                'name' => $w->user->name ?? 'Unknown',
                'email' => $w->user->email ?? '',
                'lifetime_spent' => (float) $w->lifetime_spent,
                'current_balance' => (float) $w->balance,
            ]);

        // Top up users
        $topUpUsers = Wallet::with('user:id,name,email')
            ->orderByDesc('lifetime_purchased')
            ->take(10)
            ->get()
            ->map(fn ($w) => [
                'user_id' => $w->user_id,
                'name' => $w->user->name ?? 'Unknown',
                'email' => $w->user->email ?? '',
                'lifetime_purchased' => (float) $w->lifetime_purchased,
                'current_balance' => (float) $w->balance,
            ]);

        // Most used models
        $topModels = AIUsage::where('status', 'success')
            ->select('provider', 'model', DB::raw('SUM(cost) as total_cost'), DB::raw('COUNT(*) as total_calls'))
            ->groupBy('provider', 'model')
            ->orderByDesc('total_calls')
            ->take(10)
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'model' => $r->model,
                'total_cost' => (float) $r->total_cost,
                'total_calls' => (int) $r->total_calls,
            ]);

        // Daily revenue last 30 days
        $dailyRevenue = collect(range(0, 29))->map(function ($daysAgo) use ($today) {
            $date = $today->copy()->subDays($daysAgo);
            $amount = (float) WalletTransaction::where('type', 'topup')
                ->whereDate('created_at', $date)
                ->where('status', 'completed')
                ->sum('amount');
            return [
                'date' => $date->toDateString(),
                'amount' => $amount,
            ];
        })->reverse()->values();

        // Wallet balance distribution
        $balanceDistribution = [
            'zero' => Wallet::whereRaw('balance = 0')->count(),
            'under_5' => Wallet::whereBetween('balance', [0.01, 5])->count(),
            'under_20' => Wallet::whereBetween('balance', [5.01, 20])->count(),
            'under_100' => Wallet::whereBetween('balance', [20.01, 100])->count(),
            'over_100' => Wallet::where('balance', '>', 100)->count(),
        ];

        // Recent topups
        $recentTopups = WalletTopup::with('user:id,name,email')
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'user' => ['id' => $t->user_id, 'name' => $t->user->name ?? '', 'email' => $t->user->email ?? ''],
                'amount' => (float) $t->amount,
                'provider' => $t->payment_provider,
                'status' => $t->payment_status,
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        return response()->json([
            'wallet' => [
                'total_balance' => $totalWalletBalance,
                'total_reserved' => $totalReserved,
                'total_lifetime_spent' => $totalLifetimeSpent,
                'total_lifetime_purchased' => $totalLifetimePurchased,
            ],
            'revenue' => [
                'today' => $todayRevenue,
                'month' => $monthRevenue,
                'pending_payments' => $pendingPayments,
                'failed_payments' => $failedPayments,
                'total_refunds' => $refundsTotal,
            ],
            'ai_usage' => [
                'today_cost' => $todayAICost,
                'month_cost' => $monthAICost,
                'total_calls' => $totalAICalls,
            ],
            'revenue_by_provider' => $revenueByProvider,
            'revenue_by_feature' => $revenueByFeature,
            'top_spending_users' => $topSpendingUsers,
            'top_up_users' => $topUpUsers,
            'top_models' => $topModels,
            'daily_revenue' => $dailyRevenue,
            'balance_distribution' => $balanceDistribution,
            'recent_topups' => $recentTopups,
        ]);
    }

    /** GET /api/admin/billing/topups */
    public function topups(Request $req): \Illuminate\Http\JsonResponse
    {
        $query = WalletTopup::with('user:id,name,email')
            ->orderByDesc('created_at');

        if ($req->query('status')) {
            $query->where('payment_status', $req->query('status'));
        }

        $perPage = (int) $req->query('per_page', 20);
        $page = (int) $req->query('page', 1);

        $total = $query->count();
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'topups' => $records->map(fn ($t) => [
                'id' => $t->id,
                'user_id' => $t->user_id,
                'user' => ['id' => $t->user_id, 'name' => $t->user->name ?? '', 'email' => $t->user->email ?? ''],
                'amount' => (float) $t->amount,
                'currency' => $t->currency,
                'provider' => $t->payment_provider,
                'payment_intent' => $t->payment_intent,
                'stripe_session_id' => $t->stripe_session_id,
                'status' => $t->payment_status,
                'created_at' => $t->created_at->toIso8601String(),
            ]),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => ceil($total / $perPage),
            ],
        ]);
    }

    /** POST /api/admin/billing/users/{id}/credit */
    public function creditUser(Request $req, int $id): \Illuminate\Http\JsonResponse
    {
        $req->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|string|in:bonus,refund,admin_credit,referral,adjustment',
            'description' => 'required|string|max:255',
        ]);

        $user = User::findOrFail($id);
        $service = app(\App\Services\Billing\WalletService::class);

        $tx = $service->credit(
            $id,
            (float) $req->input('amount'),
            $req->input('type'),
            $req->input('description'),
            ['admin_id' => $req->user()->id]
        );

        return response()->json([
            'transaction' => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'description' => $tx->description,
                'created_at' => $tx->created_at->toIso8601String(),
            ],
        ]);
    }
}
