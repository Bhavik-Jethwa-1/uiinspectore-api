<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewAnnotation extends Model
{
    protected $fillable = ['review_id', 'review_issue_id', 'x', 'y', 'width', 'height'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(ReviewIssue::class, 'review_issue_id');
    }
}
