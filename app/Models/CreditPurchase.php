<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPurchase extends Model
{
    protected $fillable = [
        'user_id', 'credit_pack_id', 'stripe_session_id',
        'stripe_payment_intent', 'credits_purchased', 'amount_cents', 'status',
    ];
    protected $casts = ['credits_purchased' => 'integer', 'amount_cents' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creditPack()
    {
        return $this->belongsTo(CreditPack::class, 'credit_pack_id');
    }
}
