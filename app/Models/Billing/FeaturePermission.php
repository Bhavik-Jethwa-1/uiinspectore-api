<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeaturePermission extends Model
{
    protected $fillable = ['plan_id', 'feature', 'enabled', 'limit', 'period', 'description'];
    protected $casts = ['enabled' => 'boolean', 'limit' => 'integer'];

    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
}
