<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'subscription_id', 'type', 'amount', 'currency',
        'status', 'provider', 'provider_payment_id', 'provider_refund_id',
        'failure_reason', 'metadata',
    ];
    protected $casts = ['amount' => 'decimal:2', 'metadata' => 'array'];
    public function user(): BelongsTo { return $this->belongsTo(\App\Models\User::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function invoice() { return $this->hasOne(Invoice::class); }
}
