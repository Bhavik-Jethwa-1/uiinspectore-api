<?php

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Model;

class AIProvider extends Model
{
    protected $table = 'ai_providers';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'api_key',
        'base_url',
        'models',
        'config',
        'enabled',
        'priority',
        'health_status',
        'health_checked_at',
    ];

    protected $casts = [
        'models'    => 'array',
        'config'    => 'array',
        'enabled'   => 'boolean',
        'priority'  => 'integer',
        'health_checked_at' => 'datetime',
    ];

    protected $hidden = ['api_key'];

    public function isHealthy(): bool
    {
        return $this->health_status === 'healthy';
    }
}
