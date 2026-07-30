<?php

namespace App\Services\Billing;

use App\Models\Billing\FeatureUsage;
use App\Models\Billing\UsageLog;
use App\Models\User;
use Carbon\Carbon;

/**
 * Tracks monthly usage per feature per user.
 * Automatically resets each billing period.
 */
class UsageService
{
    public const FEATURES = [
        'ai_generations',
        'image_generations',
        'ai_chat',
        'screenshot_analysis',
        'projects',
        'exports',
        'templates',
        'storage_mb',
        'api_requests',
        'team_seats',
    ];

    public function getUsage(User $user, string $feature): int
    {
        $period = $this->currentPeriod($user);
        $usage = FeatureUsage::where('user_id', $user->id)
            ->where('feature', $feature)
            ->where('period_start', $period['start'])
            ->where('period_end', $period['end'])
            ->first();

        return $usage?->used ?? 0;
    }

    public function getAllUsage(User $user): array
    {
        $period = $this->currentPeriod($user);
        $records = FeatureUsage::where('user_id', $user->id)
            ->where('period_start', $period['start'])
            ->where('period_end', $period['end'])
            ->get()
            ->keyBy('feature');

        $result = [];
        foreach (self::FEATURES as $feature) {
            $rec = $records[$feature] ?? null;
            $result[$feature] = [
                'used'    => $rec?->used ?? 0,
                'limit'   => $rec?->limit,
                'remaining'=> $rec?->remaining(),
                'exceeded'=> $rec?->exceeded() ?? false,
                'percent_used' => $rec?->percentUsed() ?? 0,
            ];
        }
        return $result;
    }

    public function record(User $user, string $feature, int $delta = 1): FeatureUsage
    {
        $period = $this->currentPeriod($user);

        $usage = FeatureUsage::updateOrCreate(
            [
                'user_id'      => $user->id,
                'feature'      => $feature,
                'period_start' => $period['start'],
                'period_end'   => $period['end'],
            ],
            []
        );

        $usage->increment('used', $delta);
        $usage = $usage->fresh();

        UsageLog::create([
            'user_id' => $user->id,
            'feature' => $feature,
            'delta'   => $delta,
            'action'  => 'increment',
            'metadata' => ['new_total' => $usage->used],
        ]);

        return $usage;
    }

    /**
     * Reset usage for all features for a user's new billing period.
     * Called by a scheduled cron job monthly.
     */
    public function resetForNewPeriod(User $user): void
    {
        FeatureUsage::where('user_id', $user->id)->update(['used' => 0]);

        UsageLog::create([
            'user_id'  => $user->id,
            'feature'  => 'all',
            'delta'    => 0,
            'action'   => 'reset',
            'metadata' => ['period' => $this->currentPeriod($user)],
        ]);
    }

    /**
     * Get current billing period for a user (monthly).
     */
    public function currentPeriod(User $user): array
    {
        // Start of current month to start of next month
        $start = now()->startOfMonth();
        $end   = now()->copy()->addMonth()->startOfMonth();
        return ['start' => $start, 'end' => $end];
    }

    /**
     * Check and return usage + limit + remaining for a specific feature.
     */
    public function check(string $feature, User $user, int $limit): array
    {
        $used = $this->getUsage($user, $feature);
        $remaining = $limit === -1 ? PHP_INT_MAX : max(0, $limit - $used);
        $exceeded  = $limit !== -1 && $used >= $limit;

        return [
            'used'       => $used,
            'limit'      => $limit,
            'remaining'  => $remaining,
            'exceeded'   => $exceeded,
            'percent_used' => $limit > 0 ? min(100, ($used / $limit) * 100) : 0,
        ];
    }

    /**
     * Get a human-readable label for a feature.
     */
    public static function featureLabel(string $feature): string
    {
        return match ($feature) {
            'ai_generations'       => 'AI Generations',
            'image_generations'    => 'Image Generation',
            'ai_chat'              => 'AI Chat Messages',
            'screenshot_analysis'   => 'Screenshot Analysis',
            'projects'             => 'Projects',
            'exports'              => 'Exports',
            'templates'            => 'Templates',
            'storage_mb'           => 'Storage',
            'api_requests'         => 'API Requests',
            'team_seats'           => 'Team Seats',
            default                => ucfirst(str_replace('_', ' ', $feature)),
        };
    }
}
