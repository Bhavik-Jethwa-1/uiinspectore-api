<?php

namespace App\Services\Billing;

use App\Models\AIUsage;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Models\AutoRechargeSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    /**
     * Get or create a wallet for a user.
     */
    public function getOrCreateWallet(int $userId): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId],
            [
                'currency' => 'USD',
                'balance' => 0,
                'reserved_balance' => 0,
                'status' => 'active',
            ]
        );
    }

    /**
     * Get wallet with balance info.
     */
    public function getWalletInfo(int $userId): array
    {
        $wallet = $this->getOrCreateWallet($userId);

        return [
            'wallet' => [
                'id' => $wallet->id,
                'balance' => (float) $wallet->balance,
                'reserved_balance' => (float) $wallet->reserved_balance,
                'available_balance' => (float) round(($wallet->balance) - ($wallet->reserved_balance), 4),
                'currency' => $wallet->currency,
                'status' => $wallet->status,
                'lifetime_purchased' => (float) $wallet->lifetime_purchased,
                'lifetime_spent' => (float) $wallet->lifetime_spent,
                'lifetime_refunded' => (float) $wallet->lifetime_refunded,
            ],
            'auto_recharge' => $this->getAutoRechargeSetting($userId),
        ];
    }

    /**
     * Add funds to wallet (called after successful payment).
     */
    public function topup(int $userId, float $amount, string $provider, string $paymentIntent, string $status, ?string $description = null): WalletTransaction
    {
        return DB::transaction(function () use ($userId, $amount, $provider, $paymentIntent, $status, $description) {
            $wallet = $this->getOrCreateWallet($userId);

            // Record the topup
            $topup = WalletTopup::create([
                'user_id' => $userId,
                'payment_provider' => $provider,
                'payment_intent' => $paymentIntent,
                'amount' => $amount,
                'currency' => 'USD',
                'payment_status' => $status,
                'description' => $description,
            ]);

            // Only credit wallet if payment succeeded
            if ($status === 'completed') {
                $wallet->balance = (float) round(($wallet->balance) + ($amount), 4);
                $wallet->lifetime_purchased = (float) round(($wallet->lifetime_purchased) + ($amount), 4);
                $wallet->save();

                $tx = WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'topup',
                    'amount' => $amount,
                    'currency' => 'USD',
                    'reference_type' => 'wallet_topup',
                    'reference_id' => $topup->id,
                    'description' => $description ?? "Wallet top-up via {$provider}",
                    'status' => 'completed',
                ]);

                Log::info("Wallet topup completed", [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'provider' => $provider,
                    'payment_intent' => $paymentIntent,
                ]);

                return $tx;
            }

            return null;
        });
    }

    /**
     * Reserve amount for an AI call (pre-authorization).
     * Returns transaction if successful, null if insufficient funds.
     */
    public function reserve(int $userId, float $amount, string $description, string $requestId): ?WalletTransaction
    {
        return DB::transaction(function () use ($userId, $amount, $description, $requestId) {
            $wallet = $this->getOrCreateWallet($userId);

            // Check available balance
            $available = (float) round(($wallet->balance) - ($wallet->reserved_balance), 4);
            if ($available < $amount) {
                Log::warning("Wallet reserve failed: insufficient funds", [
                    'user_id' => $userId,
                    'required' => $amount,
                    'available' => $available,
                ]);
                return null;
            }

            // Reserve the amount
            $wallet->reserved_balance = (float) round(($wallet->reserved_balance) + ($amount), 4);
            $wallet->save();

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'reservation',
                'amount' => -$amount, // negative = debit
                'currency' => 'USD',
                'reference_type' => 'ai_usage',
                'reference_id' => null,
                'description' => $description,
                'status' => 'reserved',
                'metadata' => ['request_id' => $requestId, 'reserved_amount' => $amount],
            ]);

            return $tx;
        });
    }

    /**
     * Confirm AI usage — deduct from reserved, record as spent.
     * Call after successful AI response.
     */
    public function confirmUsage(int $userId, float $actualCost, string $provider, string $model, string $feature, int $inputTokens, int $outputTokens, WalletTransaction $reservedTx, AIUsage $usage): WalletTransaction
    {
        return DB::transaction(function () use ($userId, $actualCost, $provider, $model, $feature, $inputTokens, $outputTokens, $reservedTx, $usage) {
            $wallet = $this->getOrCreateWallet($userId);

            $reservedAmount = (float) ($reservedTx->metadata['reserved_amount'] ?? $actualCost);
            $refundAmount = (float) round(($reservedAmount) - ($actualCost), 4); // unused portion to refund

            // Release reserved amount
            $wallet->reserved_balance = (float) round(($wallet->reserved_balance) - ($reservedAmount), 4);
            // Deduct actual cost from balance
            $wallet->balance = (float) round(($wallet->balance) - ($actualCost), 4);
            $wallet->lifetime_spent = (float) round(($wallet->lifetime_spent) + ($actualCost), 4);
            $wallet->save();

            // Mark reservation as completed
            $reservedTx->update(['status' => 'completed']);

            // Create actual usage transaction
            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'ai_usage',
                'amount' => -$actualCost,
                'currency' => 'USD',
                'reference_type' => 'ai_usage',
                'reference_id' => $usage->id,
                'description' => "{$feature} • {$model} ({$provider})",
                'status' => 'completed',
                'metadata' => [
                    'provider' => $provider,
                    'model' => $model,
                    'feature' => $feature,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'request_id' => $usage->request_id,
                ],
            ]);

            // Refund unused reservation
            if ($refundAmount > 0.0001) {
                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'refund',
                    'amount' => $refundAmount,
                    'currency' => 'USD',
                    'reference_type' => 'ai_usage',
                    'reference_id' => $usage->id,
                    'description' => "Unused reservation refund",
                    'status' => 'completed',
                    'metadata' => [
                        'original_reserved' => $reservedAmount,
                        'actual_cost' => $actualCost,
                    ],
                ]);
            }

            Log::info("Wallet AI usage confirmed", [
                'user_id' => $userId,
                'actual_cost' => $actualCost,
                'refund' => $refundAmount,
                'provider' => $provider,
                'model' => $model,
            ]);

            return $tx;
        });
    }

    /**
     * Release reserved amount (e.g. AI call failed).
     */
    public function releaseReservation(int $userId, WalletTransaction $reservedTx, string $reason): void
    {
        DB::transaction(function () use ($userId, $reservedTx, $reason) {
            $wallet = $this->getOrCreateWallet($userId);
            $reservedAmount = (float) ($reservedTx->metadata['reserved_amount'] ?? 0);

            if ($reservedAmount > 0) {
                $wallet->reserved_balance = (float) round(($wallet->reserved_balance) - ($reservedAmount), 4);
                $wallet->save();

                $reservedTx->update(['status' => 'released']);

                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'refund',
                    'amount' => $reservedAmount,
                    'currency' => 'USD',
                    'reference_type' => 'ai_usage',
                    'reference_id' => null,
                    'description' => "Reservation released: {$reason}",
                    'status' => 'completed',
                    'metadata' => ['reason' => $reason],
                ]);
            }
        });
    }

    /**
     * Direct debit without reservation (for simple operations).
     */
    public function debit(int $userId, float $amount, string $type, string $description, ?string $referenceType = null, ?int $referenceId = null, ?array $metadata = null): ?WalletTransaction
    {
        return DB::transaction(function () use ($userId, $amount, $type, $description, $referenceType, $referenceId, $metadata) {
            $wallet = $this->getOrCreateWallet($userId);

            if ($wallet->availableBalance() < $amount) {
                return null;
            }

            $wallet->balance = (float) round(($wallet->balance) - ($amount), 4);
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => -$amount,
                'currency' => 'USD',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'status' => 'completed',
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Admin credit / bonus / referral credit.
     */
    public function credit(int $userId, float $amount, string $type, string $description, ?array $metadata = null): WalletTransaction
    {
        return DB::transaction(function () use ($userId, $amount, $type, $description, $metadata) {
            $wallet = $this->getOrCreateWallet($userId);

            $wallet->balance = (float) round(($wallet->balance) + ($amount), 4);
            if ($type === 'bonus' || $type === 'referral') {
                $wallet->lifetime_purchased = (float) round(($wallet->lifetime_purchased) + ($amount), 4);
            }
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => $amount,
                'currency' => 'USD',
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $description,
                'status' => 'completed',
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Get paginated transaction history.
     */
    public function getHistory(int $userId, int $page = 1, int $perPage = 20, ?string $type = null): array
    {
        $wallet = $this->getOrCreateWallet($userId);

        $query = WalletTransaction::where('wallet_id', $wallet->id)
            ->orderByDesc('created_at');

        if ($type) {
            $query->where('type', $type);
        }

        $total = $query->count();
        $transactions = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [
            'transactions' => $transactions->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'currency' => $t->currency,
                'description' => $t->description,
                'status' => $t->status,
                'reference_type' => $t->reference_type,
                'reference_id' => $t->reference_id,
                'metadata' => $t->metadata,
                'created_at' => $t->created_at->toIso8601String(),
            ]),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Get auto-recharge setting for user.
     */
    public function getAutoRechargeSetting(int $userId): ?array
    {
        $setting = AutoRechargeSetting::where('user_id', $userId)->first();
        if (!$setting) {
            return null;
        }
        return [
            'enabled' => $setting->enabled,
            'threshold' => (float) $setting->threshold,
            'recharge_amount' => (float) $setting->recharge_amount,
            'payment_method_id' => $setting->payment_method_id,
        ];
    }

    /**
     * Update auto-recharge settings.
     */
    public function updateAutoRecharge(int $userId, array $data): AutoRechargeSetting
    {
        return AutoRechargeSetting::updateOrCreate(
            ['user_id' => $userId],
            [
                'enabled' => $data['enabled'] ?? false,
                'threshold' => $data['threshold'] ?? 5.00,
                'recharge_amount' => $data['recharge_amount'] ?? 20.00,
                'payment_method_id' => $data['payment_method_id'] ?? null,
            ]
        );
    }

    /**
     * Check and trigger auto-recharge if needed.
     */
    public function checkAutoRecharge(int $userId): bool
    {
        $setting = AutoRechargeSetting::where('user_id', $userId)
            ->where('enabled', true)
            ->first();

        if (!$setting) {
            return false;
        }

        $wallet = $this->getOrCreateWallet($userId);
        if ($wallet->availableBalance() >= (float) $setting->threshold) {
            return false;
        }

        // TODO: Trigger auto-recharge via saved payment method
        // This would create a Stripe charge and call topup()
        Log::info("Auto-recharge triggered", [
            'user_id' => $userId,
            'balance' => $wallet->availableBalance(),
            'threshold' => (float) $setting->threshold,
            'recharge_amount' => (float) $setting->recharge_amount,
        ]);

        return true;
    }
}
