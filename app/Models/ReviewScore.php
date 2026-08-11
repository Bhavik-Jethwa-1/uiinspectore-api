<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewScore extends Model
{
    protected $fillable = [
        'review_id', 'visual_hierarchy', 'clarity', 'accessibility', 'consistency',
        'layout', 'typography', 'ux', 'overall', 'summary', 'strengths'
    ];

    protected $casts = [
        'strengths' => 'array',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
