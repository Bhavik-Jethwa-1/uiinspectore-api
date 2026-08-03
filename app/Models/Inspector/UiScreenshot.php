<?php

namespace App\Models\Inspector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UiScreenshot extends Model
{
    protected $table = 'ui_screenshots';

    protected $fillable = [
        'ui_project_id', 'file_path', 'file_name', 'file_size', 'variant', 'page_goal', 'persona',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(UiProject::class, 'ui_project_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(UiReview::class, 'ui_screenshot_id');
    }
}
