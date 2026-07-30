<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIPricing extends Model
{
    protected $table = 'ai_pricing';
    protected $fillable = [
        'provider', 'model', 'feature',
        'price_per_1k_input', 'price_per_1k_output',
        'flat_call_fee', 'is_active',
    ];

    protected $casts = [
        'price_per_1k_input' => 'decimal:6',
        'price_per_1k_output' => 'decimal:6',
        'flat_call_fee' => 'decimal:6',
    ];

    /** Calculate cost for given token counts */
    public function calculateCost(int $inputTokens, int $outputTokens = 0): float
    {
        $inputCost = ($inputTokens / 1000) * (float) $this->price_per_1k_input;
        $outputCost = ($outputTokens / 1000) * (float) $this->price_per_1k_output;
        return round($inputCost + $outputCost + (float) $this->flat_call_fee, 6);
    }

    public static function getCost(string $provider, string $model, string $feature, int $inputTokens = 0, int $outputTokens = 0): float
    {
        $pricing = self::where('provider', $provider)
            ->where('model', $model)
            ->where('feature', $feature)
            ->where('is_active', true)
            ->first();

        if (!$pricing) {
            // Default fallback pricing (free)
            return 0.0;
        }

        return $pricing->calculateCost($inputTokens, $outputTokens);
    }
}
