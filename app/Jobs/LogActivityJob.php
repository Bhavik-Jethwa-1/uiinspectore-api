<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async Activity Logger
 * 
 * Logs project activity (team events, updates, etc.) without blocking API responses.
 */
class LogActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 15;

    public function __construct(
        private ?int $userId,
        private ?int $projectId,
        private string $action,
        private ?string $subjectType = null,
        private ?int $subjectId = null,
        private array $metadata = [],
    ) {}

    public function handle(): void
    {
        try {
            DB::table('activity_logs')->insert([
                'user_id' => $this->userId,
                'project_id' => $this->projectId,
                'action' => $this->action,
                'subject_type' => $this->subjectType,
                'subject_id' => $this->subjectId,
                'metadata' => json_encode($this->metadata),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('LogActivityJob failed', ['error' => $e->getMessage()]);
        }
    }
}
