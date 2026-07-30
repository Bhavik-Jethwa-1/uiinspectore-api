<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Issue extends Model
{
    protected $fillable = [
        'project_id',
        'screenshot_id',
        'analysis_id',
        'user_id',
        'type',
        'severity',
        'category',
        'title',
        'description',
        'problem',
        'reason',
        'business_impact',
        'recommendation',
        'expected_result',
        'status',
        'x',
        'y',
        'width',
        'height',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'x' => 'integer',
        'y' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function screenshot(): BelongsTo
    {
        return $this->belongsTo(Screenshot::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
