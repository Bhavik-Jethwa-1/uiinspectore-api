<?php

namespace App\Models\Inspector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UiReview extends Model
{
    protected $table = 'ui_reviews';

    protected $fillable = [
        'ui_project_id', 'ui_screenshot_id', 'status', 'scores', 'summary', 'review_data', 'error_message',
    ];

    protected $casts = [
        'scores' => 'array',
        'summary' => 'array',
        'review_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(UiProject::class, 'ui_project_id');
    }

    public function screenshot(): BelongsTo
    {
        return $this->belongsTo(UiScreenshot::class, 'ui_screenshot_id');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(UiAnnotation::class, 'ui_review_id')->orderBy('number');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(UiSuggestion::class, 'ui_review_id');
    }
}
