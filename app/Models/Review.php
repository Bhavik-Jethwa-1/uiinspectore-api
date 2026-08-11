<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Review extends Model
{
    protected $fillable = [
        'project_id', 'screenshot_id', 'status', 'persona', 'page_goal', 'ai_response'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function screenshot(): BelongsTo
    {
        return $this->belongsTo(Screenshot::class);
    }

    public function score(): HasOne
    {
        return $this->hasOne(ReviewScore::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ReviewIssue::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(ReviewAnnotation::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(ReviewSuggestion::class);
    }
}
