<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'user_id', 'plan_id', 'status', 'billing_cycle',
        'amount', 'currency', 'current_period_start', 'current_period_end',
        'trial_ends_at', 'cancel_at_period_end', 'cancelled_at',
        'provider', 'provider_subscription_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'trial_ends_at'        => 'datetime',
        'cancelled_at'         => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' || $this->status === 'trialing';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing' && $this->trial_ends_at?->isFuture();
    }

    public function periodEnded(): bool
    {
        return $this->current_period_end?->isPast();
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    }

    public function getPlanSlug(): string
    {
        return $this->plan?->slug ?? 'free';
    }

    public function getFeature(string $key): bool
    {
        return $this->plan?->hasFeature($key) ?? false;
    }

    public function getLimit(string $key, mixed $default = null): mixed
    {
        return $this->plan?->getLimit($key, $default) ?? $default;
    }
}
