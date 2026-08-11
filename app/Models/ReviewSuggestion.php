<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewSuggestion extends Model
{
    protected $fillable = [
        'review_id', 'title', 'priority', 'category', 'problem', 'recommendation', 'expected_impact'
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
