<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTemplate extends Model
{
    protected $table = 'project_templates';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'screenshots',
        'categories',
        'is_public',
    ];

    protected $casts = [
        'screenshots' => 'array',
        'categories' => 'array',
        'is_public' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
