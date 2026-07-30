<?php

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Model;

class AIProviderSetting extends Model
{
    protected $table = 'ai_provider_settings';

    protected $fillable = [
        'user_id',
        'chat_provider',
        'chat_model',
        'image_provider',
        'image_model',
    ];
}
