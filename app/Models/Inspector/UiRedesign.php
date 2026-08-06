<?php

namespace App\Models\Inspector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UiRedesign extends Model
{
    protected $table = 'ui_redesigns';

    protected $fillable = [
        'ui_project_id', 'ui_screenshot_id', 'design_style', 'status',
        'image_path', 'original_image_path', 'provider', 'model',
        'improved_items', 'regressed_items', 'unchanged_items',
        'score_comparison', 'error_message', 'vision_analysis',
    ];

    protected $casts = [
        'improved_items' => 'array',
        'regressed_items' => 'array',
        'unchanged_items' => 'array',
        'score_comparison' => 'array',
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

    public function generatedCodes(): HasMany
    {
        return $this->hasMany(UiGeneratedCode::class, 'ui_redesign_id');
    }
}
