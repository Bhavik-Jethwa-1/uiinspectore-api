<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'user_id', 'subscription_id', 'payment_id', 'invoice_number',
        'status', 'subtotal', 'tax', 'total', 'currency',
        'pdf_url', 'provider_invoice_id', 'due_date', 'paid_at',
    ];
    protected $casts = ['subtotal' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'due_date' => 'datetime', 'paid_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(\App\Models\User::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }

    public static function generateNumber(): string
    {
        $prefix = 'INV-' . date('Y') . '-';
        $last = static::where('invoice_number', 'like', $prefix . '%')
            ->orderBy('invoice_number', 'desc')->first();
        $seq = $last ? (int) substr($last->invoice_number, -5) + 1 : 1;
        return $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }
}
