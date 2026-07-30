<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async AI Usage Logger
 * 
 * Logs AI usage to ai_usage table without blocking the API response.
 * Dispatch this job after every AI request completes.
 */
class LogAIUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        private int $userId,
        private string $provider,
        private string $model,
        private string $type,
        private int $inputTokens,
        private int $outputTokens,
        private float $cost,
        private int $responseTimeMs,
        private ?string $error = null,
    ) {}

    public function handle(): void
    {
        try {
            DB::table('ai_usage')->insert([
                'user_id' => $this->userId,
                'provider' => $this->provider,
                'model' => $this->model,
                'type' => $this->type,
                'input_tokens' => $this->inputTokens,
                'output_tokens' => $this->outputTokens,
                'total_tokens' => $this->inputTokens + $this->outputTokens,
                'cost' => $this->cost,
                'response_time_ms' => $this->responseTimeMs,
                'error' => $this->error,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('LogAIUsageJob failed', ['error' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('daily')->error('LogAIUsageJob permanently failed', [
            'user_id' => $this->userId,
            'provider' => $this->provider,
            'error' => $exception->getMessage(),
        ]);
    }
}
