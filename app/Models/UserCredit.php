<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCredit extends Model
{
    protected $fillable = ['user_id', 'credits_remaining', 'total_purchased'];
    protected $casts    = ['credits_remaining' => 'integer', 'total_purchased' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
