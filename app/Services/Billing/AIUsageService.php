<?php

namespace App\Services\Billing;

use App\Models\AIUsage;
use App\Models\AIPricing;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AIUsageService
{
    public function __construct(
        private WalletService $wallet
    ) {}

    /**
     * Check if user can afford an AI call.
     */
    public function canAfford(int $userId, string $provider, string $model, string $feature, int $inputTokens = 0, int $outputTokens = 0): array
    {
        $cost = AIPricing::getCost($provider, $model, $feature, $inputTokens, $outputTokens);
        $walletInfo = $this->wallet->getWalletInfo($userId);
        $wallet = $walletInfo['wallet'];
        $available = (float) $wallet['available_balance'];

        return [
            'can_afford' => $available >= $cost,
            'cost' => $cost,
            'available_balance' => $available,
            'is_low_balance' => $available < 2.00,
            'shortage' => $cost > $available ? round($cost - $available, 4) : 0,
        ];
    }

    /**
     * Start an AI usage session — reserves cost from wallet.
     * Returns [success, reservation, usageRecord, error].
     */
    public function startSession(int $userId, string $provider, string $model, string $feature, int $inputTokens = 0, int $outputTokens = 0): array
    {
        $cost = AIPricing::getCost($provider, $model, $feature, $inputTokens, $outputTokens);
        $requestId = Str::uuid()->toString();

        // If cost is 0, no reservation needed
        if ($cost <= 0) {
            $usage = AIUsage::create([
                'user_id' => $userId,
                'provider' => $provider,
                'model' => $model,
                'feature' => $feature,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost' => 0,
                'status' => 'success',
                'request_id' => $requestId,
            ]);
            return [true, null, $usage, null];
        }

        // Reserve cost from wallet
        $description = "AI {$feature} • {$model}";
        $reservedTx = $this->wallet->reserve($userId, $cost, $description, $requestId);

        if (!$reservedTx) {
            $walletInfo = $this->wallet->getWalletInfo($userId);
            return [
                false,
                null,
                null,
                'insufficient_balance',
                [
                    'cost' => $cost,
                    'available' => $walletInfo['wallet']['available_balance'],
                    'shortage' => round($cost - $walletInfo['wallet']['available_balance'], 4),
                ],
            ];
        }

        // Create usage record in reserved state
        $usage = AIUsage::create([
            'user_id' => $userId,
            'provider' => $provider,
            'model' => $model,
            'feature' => $feature,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $cost,
            'wallet_transaction_type' => 'ai_usage',
            'wallet_transaction_id' => $reservedTx->id,
            'status' => 'reserved',
            'request_id' => $requestId,
        ]);

        // Update transaction with reference
        $reservedTx->update(['reference_id' => $usage->id]);

        return [true, $reservedTx, $usage, null];
    }

    /**
     * Confirm usage after successful AI call — deducts actual cost.
     */
    public function confirmSession(int $userId, string $requestId, int $inputTokens, int $outputTokens, ?array $metadata = null): void
    {
        $usage = AIUsage::where('request_id', $requestId)
            ->where('user_id', $userId)
            ->where('status', 'reserved')
            ->first();

        if (!$usage) {
            Log::warning("AIUsage confirmSession: record not found", [
                'user_id' => $userId,
                'request_id' => $requestId,
            ]);
            return;
        }

        // Recalculate cost with actual tokens
        $actualCost = AIPricing::getCost(
            $usage->provider,
            $usage->model,
            $usage->feature,
            $inputTokens,
            $outputTokens
        );

        // Update usage with actual token counts
        $usage->input_tokens = $inputTokens;
        $usage->output_tokens = $outputTokens;
        $usage->cost = $actualCost;
        $usage->status = 'success';
        if ($metadata) {
            $usage->metadata = array_merge($usage->metadata ?? [], $metadata);
        }
        $usage->save();

        // Find the reserved transaction
        $reservedTx = WalletTransaction::find($usage->wallet_transaction_id);
        if ($reservedTx) {
            $this->wallet->confirmUsage(
                $userId, $actualCost, $usage->provider,
                $usage->model, $usage->feature,
                $inputTokens, $outputTokens, $reservedTx, $usage
            );
        }

        // Check auto-recharge
        $this->wallet->checkAutoRecharge($userId);
    }

    /**
     * Fail session — release reservation and log error.
     */
    public function failSession(int $userId, string $requestId, string $errorMessage): void
    {
        $usage = AIUsage::where('request_id', $requestId)
            ->where('user_id', $userId)
            ->where('status', 'reserved')
            ->first();

        if ($usage) {
            $usage->status = 'failed';
            $usage->error_message = $errorMessage;
            $usage->save();

            $reservedTx = WalletTransaction::find($usage->wallet_transaction_id);
            if ($reservedTx) {
                $this->wallet->releaseReservation($userId, $reservedTx, $errorMessage);
            }
        }
    }

    /**
     * Get usage history for a user.
     */
    public function getUsageHistory(int $userId, int $page = 1, int $perPage = 20, ?string $feature = null): array
    {
        $query = AIUsage::where('user_id', $userId)
            ->orderByDesc('created_at');

        if ($feature) {
            $query->where('feature', $feature);
        }

        $total = $query->count();
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [
            'records' => $records->map(fn ($r) => [
                'id' => $r->id,
                'provider' => $r->provider,
                'model' => $r->model,
                'feature' => $r->feature,
                'input_tokens' => $r->input_tokens,
                'output_tokens' => $r->output_tokens,
                'cost' => (float) $r->cost,
                'status' => $r->status,
                'error_message' => $r->error_message,
                'request_id' => $r->request_id,
                'created_at' => $r->created_at->toIso8601String(),
            ]),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => ceil($total / $perPage),
            ],
            'summary' => [
                'total_cost' => (float) AIUsage::where('user_id', $userId)->where('status', 'success')->sum('cost'),
                'total_calls' => AIUsage::where('user_id', $userId)->where('status', 'success')->count(),
            ],
        ];
    }

    /**
     * Get pricing table.
     */
    public function getPricingTable(): array
    {
        return AIPricing::where('is_active', true)->get()->map(fn ($p) => [
            'provider' => $p->provider,
            'model' => $p->model,
            'feature' => $p->feature,
            'price_per_1k_input' => (float) $p->price_per_1k_input,
            'price_per_1k_output' => (float) $p->price_per_1k_output,
            'flat_call_fee' => (float) $p->flat_call_fee,
        ])->toArray();
    }
}
