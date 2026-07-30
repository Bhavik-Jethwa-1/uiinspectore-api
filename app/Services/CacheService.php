<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Centralized caching service for expensive operations.
 * Uses Laravel's cache driver (file/database by default, redis if available).
 */
class CacheService
{
    // Cache TTLs in seconds
    private const TTL_SHORT = 60;      // 1 minute
    private const TTL_MEDIUM = 300;    // 5 minutes
    private const TTL_LONG = 3600;     // 1 hour
    private const TTL_DAY = 86400;     // 24 hours

    // ─── Billing / Wallet Caching ───────────────────────────────────────────

    /**
     * Cache wallet info — called frequently on dashboard load
     */
    public function getWalletInfo(int $userId): array
    {
        return Cache::remember("wallet:{$userId}", self::TTL_SHORT, function () use ($userId) {
            return DB::table('wallets')->where('user_id', $userId)->first() ?? [
                'balance' => 0,
                'currency' => 'USD',
                'auto_recharge_enabled' => false,
            ];
        });
    }

    /**
     * Invalidate wallet cache when balance changes
     */
    public function invalidateWallet(int $userId): void
    {
        Cache::forget("wallet:{$userId}");
    }

    /**
     * Cache billing plans — rarely change
     */
    public function getBillingPlans(): array
    {
        return Cache::remember('billing:plans', self::TTL_LONG, function () {
            $plans = DB::table('billing_plans')->where('is_active', true)->get();
            return $plans->toArray();
        });
    }

    public function invalidateBillingPlans(): void
    {
        Cache::forget('billing:plans');
    }

    /**
     * Cache credit packs — rarely change
     */
    public function getCreditPacks(): array
    {
        return Cache::remember('credits:packs', self::TTL_LONG, function () {
            return DB::table('credit_packs')->where('is_active', true)->orderBy('credits', 'asc')->get()->toArray();
        });
    }

    public function invalidateCreditPacks(): void
    {
        Cache::forget('credits:packs');
    }

    // ─── Project Caching ────────────────────────────────────────────────────

    /**
     * Cache project list count per user — used for sidebar badges
     */
    public function getProjectStats(int $userId): array
    {
        return Cache::remember("projects:stats:{$userId}", self::TTL_MEDIUM, function () use ($userId) {
            return [
                'total' => DB::table('projects')->where('user_id', $userId)->whereNull('archived_at')->count(),
                'active' => DB::table('projects')->where('user_id', $userId)->where('status', 'active')->count(),
                'archived' => DB::table('projects')->where('user_id', $userId)->where('status', 'archived')->count(),
            ];
        });
    }

    public function invalidateProjectStats(int $userId): void
    {
        Cache::forget("projects:stats:{$userId}");
    }

    // ─── AI Settings Caching ────────────────────────────────────────────────

    /**
     * Cache AI provider settings — used on every AI request
     */
    public function getAISettings(): array
    {
        return Cache::remember('ai:settings', self::TTL_MEDIUM, function () {
            $path = base_path('storage/app/ai_settings.json');
            if (file_exists($path)) {
                return json_decode(file_get_contents($path), true) ?? [];
            }
            return [];
        });
    }

    public function invalidateAISettings(): void
    {
        Cache::forget('ai:settings');
    }

    /**
     * Cache AI usage stats per user — used in dashboard
     */
    public function getAIUsageStats(int $userId): array
    {
        return Cache::remember("ai:usage:{$userId}", self::TTL_SHORT, function () use ($userId) {
            $monthStart = now()->startOfMonth()->timestamp;
            return [
                'monthly_requests' => DB::table('ai_usage')
                    ->where('user_id', $userId)
                    ->where('created_at', '>=', $monthStart)
                    ->count(),
                'monthly_cost' => DB::table('ai_usage')
                    ->where('user_id', $userId)
                    ->where('created_at', '>=', $monthStart)
                    ->sum('cost'),
            ];
        });
    }

    public function invalidateAIUsage(int $userId): void
    {
        Cache::forget("ai:usage:{$userId}");
    }

    // ─── Template Caching ────────────────────────────────────────────────────

    /**
     * Cache built-in templates — static data
     */
    public function getBuiltInTemplates(): array
    {
        return Cache::remember('templates:builtin', self::TTL_DAY, function () {
            return [
                [
                    'id' => 'tmpl_saas_dashboard', 'name' => 'SaaS Dashboard',
                    'description' => 'Complete SaaS dashboard with charts, stats, and navigation',
                    'category' => 'dashboard', 'is_public' => true, 'is_built_in' => true,
                ],
                [
                    'id' => 'tmpl_mobile_app', 'name' => 'Mobile App',
                    'description' => 'iOS/Android style mobile app with bottom navigation',
                    'category' => 'mobile', 'is_public' => true, 'is_built_in' => true,
                ],
                [
                    'id' => 'tmpl_landing_page', 'name' => 'Landing Page',
                    'description' => 'Modern SaaS landing page with hero and pricing',
                    'category' => 'marketing', 'is_public' => true, 'is_built_in' => true,
                ],
                [
                    'id' => 'tmpl_e_commerce', 'name' => 'E-Commerce',
                    'description' => 'Online store with product grid and cart',
                    'category' => 'ecommerce', 'is_public' => true, 'is_built_in' => true,
                ],
                [
                    'id' => 'tmpl_portfolio', 'name' => 'Designer Portfolio',
                    'description' => 'Minimalist portfolio for designers and creatives',
                    'category' => 'portfolio', 'is_public' => true, 'is_built_in' => true,
                ],
                [
                    'id' => 'tmpl_blog', 'name' => 'Blog / CMS',
                    'description' => 'Content-focused blog with article layout',
                    'category' => 'blog', 'is_public' => true, 'is_built_in' => true,
                ],
            ];
        });
    }

    // ─── Performance Stats ──────────────────────────────────────────────────

    /**
     * Get cached performance stats for admin dashboard
     */
    public function getPerfStats(string $endpoint): array
    {
        return Cache::get("perf_stats:" . md5($endpoint), [
            'count' => 0, 'total_time' => 0, 'max_time' => 0,
            'avg_time' => 0, 'max_sql' => 0, 'slow_count' => 0,
        ]);
    }

    // ─── Warmup ─────────────────────────────────────────────────────────────

    /**
     * Pre-warm expensive caches on app startup (call from console)
     */
    public function warmup(): void
    {
        $this->getBuiltInTemplates();
    }
}
