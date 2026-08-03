<?php

namespace App\Models\Inspector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UiGeneratedCode extends Model
{
    protected $table = 'ui_generated_codes';

    protected $fillable = [
        'ui_redesign_id', 'framework', 'code', 'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function redesign(): BelongsTo
    {
        return $this->belongsTo(UiRedesign::class, 'ui_redesign_id');
    }
}
