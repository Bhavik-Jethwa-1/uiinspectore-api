<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'price_monthly', 'price_yearly',
        'stripe_monthly_price_id', 'stripe_yearly_price_id',
        'description', 'limits', 'features', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'limits'   => 'array',
        'features' => 'array',
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function featurePermissions(): HasMany
    {
        return $this->hasMany(FeaturePermission::class);
    }

    public function isFree(): bool
    {
        return $this->slug === 'free';
    }

    public function getLimit(string $key, mixed $default = null): mixed
    {
        return $this->limits[$key] ?? $default;
    }

    public function hasFeature(string $key): bool
    {
        $features = $this->features;

        // Handle array format: ["ai_chat", "ai_ui_review", ...]
        if (is_array($features) && !array_key_exists($key, $features)) {
            return in_array($key, $features, true);
        }

        // Handle object/associative format: {"ai_chat": true, "ai_ui_review": false}
        return (bool) ($features[$key] ?? false);
    }

    public function getFeatureLimit(string $key): int
    {
        $perm = $this->featurePermissions()->where('feature', $key)->first();
        return $perm?->limit ?? $this->getLimit($key, -1);
    }
}
