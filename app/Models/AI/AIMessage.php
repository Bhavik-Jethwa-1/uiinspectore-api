<?php

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIMessage extends Model
{
    protected $table = 'ai_messages';

    const UPDATED_AT = null; // no updated_at column

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'content',
        'attachments',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'attachments'   => 'array',
        'metadata'      => 'array',
        'created_at'    => 'datetime',
    ];

    public $timestamps = false;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AIConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }
}
