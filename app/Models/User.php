<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function reviews()
    {
        return $this->hasManyThrough(Review::class, Project::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class)->orderByDesc('created_at');
    }

    public function settings()
    {
        return $this->hasMany(UserSetting::class);
    }
}
