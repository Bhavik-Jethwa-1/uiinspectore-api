<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden   = ['password', 'remember_token'];
    protected $casts    = ['email_verified_at' => 'datetime', 'password' => 'hashed'];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(\App\Models\Billing\Subscription::class);
    }

    public function featureUsage(): HasMany
    {
        return $this->hasMany(\App\Models\Billing\FeatureUsage::class);
    }
}
