<?php

namespace App\Models\Inspector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UiAnnotation extends Model
{
    protected $table = 'ui_annotations';

    protected $fillable = [
        'ui_review_id', 'number', 'type', 'severity', 'x', 'y', 'width', 'height',
        'title', 'description', 'suggested_fix', 'expected_improvement', 'difficulty', 'component_type',
    ];

    protected $casts = [
        'number' => 'integer',
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(UiReview::class, 'ui_review_id');
    }
}
