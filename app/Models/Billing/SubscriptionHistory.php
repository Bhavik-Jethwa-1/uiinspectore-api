<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionHistory extends Model
{
    protected $table = 'subscription_history';

    protected $fillable = [
        'user_id', 'subscription_id', 'action', 'from_plan',
        'to_plan', 'amount', 'metadata',
    ];
    protected $casts = ['metadata' => 'array', 'amount' => 'decimal:2'];
    public function user(): BelongsTo { return $this->belongsTo(\App\Models\User::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
}
