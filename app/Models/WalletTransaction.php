<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id', 'type', 'amount', 'currency', 'reference_type',
        'reference_id', 'description', 'status', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reference(): Model|null
    {
        if (!$this->reference_type || !$this->reference_id) {
            return null;
        }
        return match ($this->reference_type) {
            'wallet_topup' => WalletTopup::find($this->reference_id),
            'ai_usage' => AIUsage::find($this->reference_id),
            default => null,
        };
    }
}
