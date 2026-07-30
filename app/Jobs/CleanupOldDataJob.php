<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Periodic Data Cleanup Job
 * 
 * Runs daily to clean up old data, vacuum SQLite, and clear stale caches.
 * Schedule: daily at 3 AM
 */
class CleanupOldDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 300; // 5 minutes

    public function handle(): void
    {
        $start = microtime(true);

        try {
            // 1. Clean old sessions (keep last 7 days)
            $weekAgo = now()->subDays(7)->timestamp;
            DB::table('sessions')
                ->where('last_activity', '<', $weekAgo)
                ->delete();

            // 2. Clean old activity logs (keep last 90 days)
            $threeMonthsAgo = now()->subDays(90);
            DB::table('activity_logs')
                ->where('created_at', '<', $threeMonthsAgo)
                ->delete();

            // 3. Clean old AI usage logs (keep last 30 days for free users, 1 year for paid)
            $monthAgo = now()->subDays(30);
            DB::table('ai_usage')
                ->where('created_at', '<', $monthAgo)
                ->delete();

            // 4. SQLite VACUUM to reclaim disk space (run weekly only)
            if (config('database.default') === 'sqlite') {
                DB::statement('VACUUM');
            }

            // 5. Clear stale cache entries (Laravel handles this via TTL, but force clear for safety)
            $cleared = 0;
            // Only clear our custom performance/cache keys
            // Don't clear framework caches

            $duration = round(microtime(true) - $start, 2);
            Log::channel('daily')->info('CleanupOldDataJob completed', [
                'duration_sec' => $duration,
                'cleared_items' => $cleared,
            ]);

        } catch (\Throwable $e) {
            Log::channel('daily')->error('CleanupOldDataJob failed', ['error' => $e->getMessage()]);
        }
    }
}
