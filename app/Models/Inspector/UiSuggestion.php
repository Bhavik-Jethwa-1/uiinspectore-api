<?php

namespace App\Models\Inspector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UiSuggestion extends Model
{
    protected $table = 'ui_suggestions';

    protected $fillable = [
        'ui_review_id', 'category', 'title', 'description', 'suggested_fix',
        'expected_improvement', 'difficulty', 'priority', 'implemented',
    ];

    protected $casts = [
        'implemented' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(UiReview::class, 'ui_review_id');
    }
}
