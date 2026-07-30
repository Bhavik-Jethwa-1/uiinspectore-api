<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoRechargeSetting extends Model
{
    protected $fillable = [
        'user_id', 'enabled', 'threshold', 'recharge_amount', 'payment_method_id',
    ];

    protected $casts = [
        'threshold' => 'decimal:2',
        'recharge_amount' => 'decimal:2',
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
