<?php

namespace App\Models\Inspector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UiProject extends Model
{
    protected $table = 'ui_projects';

    protected $fillable = [
        'user_id', 'name', 'description', 'product_type', 'platform', 'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(UiScreenshot::class, 'ui_project_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(UiReview::class, 'ui_project_id');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(UiReview::class, 'ui_project_id')->latestOfMany()->with(['annotations', 'suggestions']);
    }

    public function redesigns(): HasMany
    {
        return $this->hasMany(UiRedesign::class, 'ui_project_id');
    }

    public function latestRedesign(): HasOne
    {
        return $this->hasOne(UiRedesign::class, 'ui_project_id')->latestOfMany();
    }
}
