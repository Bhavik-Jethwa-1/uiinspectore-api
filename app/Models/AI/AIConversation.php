<?php

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIConversation extends Model
{
    use SoftDeletes;

    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'title',
        'provider',
        'model',
        'system_prompt',
        'temperature',
        'max_tokens',
        'folder',
        'is_pinned',
        'is_archived',
        'is_favorite',
        'metadata',
    ];

    protected $casts = [
        'temperature'   => 'float',
        'max_tokens'    => 'integer',
        'is_pinned'     => 'boolean',
        'is_archived'   => 'boolean',
        'is_favorite'   => 'boolean',
        'metadata'      => 'array',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'deleted_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AIMessage::class, 'conversation_id')->orderBy('created_at', 'asc');
    }

    public function latestMessage(): HasMany
    {
        return $this->hasMany(AIMessage::class, 'conversation_id')->latest('created_at')->limit(1);
    }
}