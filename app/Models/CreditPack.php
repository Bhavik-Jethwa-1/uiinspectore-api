<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPack extends Model
{
    protected $fillable = ['name', 'credits', 'price_cents', 'stripe_price_id', 'is_active', 'sort_order'];
    protected $casts    = ['credits' => 'integer', 'price_cents' => 'integer', 'is_active' => 'boolean'];
}
