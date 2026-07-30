<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIProvider extends Model
{
    protected $table = 'ai_agents';

    protected $fillable = [
        'user_id', 'name', 'provider', 'api_key', 'base_url',
        'default_model', 'models', 'capabilities', 'is_enabled', 'is_default', 'sort_order',
    ];

    protected $casts = [
        'models'       => 'array',
        'capabilities' => 'array',
        'is_enabled'   => 'boolean',
        'is_default'   => 'boolean',
        'sort_order'   => 'integer',
    ];

    protected $hidden = ['api_key'];

    public function getMaskedApiKeyAttribute(): string
    {
        $key = $this->api_key;
        if (strlen($key) <= 8) return str_repeat('*', strlen($key));
        return substr($key, 0, 4) . str_repeat('*', strlen($key) - 8) . substr($key, -4);
    }
}
