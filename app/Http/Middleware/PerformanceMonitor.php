<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Performance Monitoring Middleware
 * 
 * Tracks every API request:
 * - Response Time
 * - SQL Query Count
 * - Memory Usage
 * - Slow Queries (>100ms)
 * 
 * Logs slow APIs (>300ms) to storage/logs/slow-api.log
 * Cache stats for 60 seconds to avoid log spam
 */
class PerformanceMonitor
{
    private float $startTime = 0;
    private int $startMemory = 0;

    public function handle(Request $request, Closure $next)
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);

        // Track SQL queries for this request
        DB::enableQueryLog();

        $response = $next($request);

        $this->recordMetrics($request, $response);

        return $response;
    }

    private function recordMetrics(Request $request, $response): void
    {
        $responseTime = (microtime(true) - $this->startTime) * 1000; // ms
        $memoryUsage = (memory_get_usage(true) - $this->startMemory) / 1024 / 1024; // MB
        $sqlQueries = DB::getQueryLog();
        $sqlCount = count($sqlQueries);
        $sqlTime = 0;
        $slowQueries = [];

        foreach ($sqlQueries as $query) {
            $time = ($query['time'] ?? 0);
            $sqlTime += $time;
            if ($time > 100) {
                $slowQueries[] = [
                    'sql' => $this->simplifySql($query['query'] ?? ''),
                    'time' => $time,
                    'bindings' => $query['bindings'] ?? [],
                ];
            }
        }

        // Log slow APIs (>300ms) - with cache to prevent log spam
        if ($responseTime > 300) {
            $cacheKey = 'slow_api:' . md5($request->method() . $request->path() . round($responseTime / 50) * 50);
            $cached = Cache::get($cacheKey);
            
            if (!$cached) {
                Log::channel('daily')->warning('SLOW_API', [
                    'endpoint' => $request->method() . ' ' . $request->path(),
                    'response_time_ms' => round($responseTime, 2),
                    'sql_queries' => $sqlCount,
                    'sql_time_ms' => round($sqlTime, 2),
                    'memory_mb' => round($memoryUsage, 2),
                    'slow_queries' => $slowQueries,
                    'status' => $response->getStatusCode(),
                ]);
                Cache::put($cacheKey, true, 60); // Prevent duplicate logs for 60s
            }
        }

        // Always record stats for performance tracking
        $statsKey = 'perf_stats:' . md5($request->path());
        $stats = Cache::get($statsKey, [
            'count' => 0,
            'total_time' => 0,
            'max_time' => 0,
            'avg_time' => 0,
            'max_sql' => 0,
            'slow_count' => 0,
        ]);

        $stats['count']++;
        $stats['total_time'] += $responseTime;
        $stats['max_time'] = max($stats['max_time'], $responseTime);
        $stats['avg_time'] = $stats['total_time'] / $stats['count'];
        $stats['max_sql'] = max($stats['max_sql'], $sqlCount);
        if ($responseTime > 300) $stats['slow_count']++;

        Cache::put($statsKey, $stats, 300); // 5 min sliding window

        // Add performance headers to response (only in debug/non-production)
        if (config('app.debug')) {
            $response->headers->set('X-Perf-Time', round($responseTime, 1) . 'ms');
            $response->headers->set('X-Perf-SQL', (string) $sqlCount);
            $response->headers->set('X-Perf-Memory', round($memoryUsage, 1) . 'MB');
        }
    }

    private function simplifySql(string $sql): string
    {
        // Remove excessive whitespace, truncate long queries
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (strlen($sql) > 200) {
            $sql = substr($sql, 0, 200) . '...';
        }
        return $sql;
    }
}
