<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewIssue extends Model
{
    protected $fillable = [
        'review_id', 'title', 'severity', 'category', 'description', 'why_it_matters', 'recommendation'
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(ReviewAnnotation::class);
    }
}
