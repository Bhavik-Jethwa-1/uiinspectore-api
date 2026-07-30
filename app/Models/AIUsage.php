<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIUsage extends Model
{
    protected $table = 'ai_usage';

    protected $fillable = [
        'user_id', 'provider', 'model', 'feature',
        'input_tokens', 'output_tokens', 'cost',
        'wallet_transaction_type', 'wallet_transaction_id',
        'wallet_transaction_ref_id', 'status', 'request_id',
        'error_message', 'metadata',
    ];

    protected $casts = [
        'cost' => 'decimal:6',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
