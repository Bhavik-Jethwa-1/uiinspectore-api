<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureUsage extends Model
{
    protected $table = 'feature_usage';
    protected $fillable = ['user_id', 'feature', 'used', 'limit', 'period_start', 'period_end'];
    protected $casts = ['period_start' => 'datetime', 'period_end' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isUnlimited(): bool { return $this->limit === -1; }

    public function remaining(): int
    {
        if ($this->isUnlimited()) return PHP_INT_MAX;
        return max(0, ($this->limit ?? 0) - $this->used);
    }

    public function exceeded(): bool
    {
        if ($this->isUnlimited()) return false;
        return $this->used >= ($this->limit ?? 0);
    }

    public function percentUsed(): float
    {
        if ($this->isUnlimited() || $this->limit == 0) return 0;
        return min(100, ($this->used / $this->limit) * 100);
    }
}
