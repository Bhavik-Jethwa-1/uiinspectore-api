<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'user_id', 'currency', 'balance', 'reserved_balance',
        'status', 'lifetime_purchased', 'lifetime_spent', 'lifetime_refunded',
    ];

    protected $casts = [
        'balance' => 'decimal:4',
        'reserved_balance' => 'decimal:4',
        'lifetime_purchased' => 'decimal:4',
        'lifetime_spent' => 'decimal:4',
        'lifetime_refunded' => 'decimal:4',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function topups(): HasMany
    {
        return $this->hasMany(WalletTopup::class);
    }

    /** Available = balance - reserved */
    public function availableBalance(): float
    {
        return round((float) $this->balance - (float) $this->reserved_balance, 4);
    }

    public function isLowBalance(float $threshold = 2.00): bool
    {
        return $this->availableBalance() < $threshold;
    }

    public function canAfford(float $amount): bool
    {
        return $this->availableBalance() >= $amount;
    }
}
