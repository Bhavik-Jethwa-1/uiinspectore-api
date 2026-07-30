<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AIPricing;

class AIPricingSeeder extends Seeder
{
    public function run(): void
    {
        $pricing = [
            // MiniMax Chat (per 1M tokens = per 1000 tokens / 1000)
            ['provider' => 'minimax', 'model' => 'MiniMax-M3', 'feature' => 'chat', 'price_per_1k_input' => 0.001, 'price_per_1k_output' => 0.003, 'flat_call_fee' => 0],
            ['provider' => 'minimax', 'model' => 'MiniMax-M3', 'feature' => 'vision', 'price_per_1k_input' => 0.005, 'price_per_1k_output' => 0.003, 'flat_call_fee' => 0],
            ['provider' => 'minimax', 'model' => 'MiniMax-VL-01', 'feature' => 'vision', 'price_per_1k_input' => 0.003, 'price_per_1k_output' => 0.001, 'flat_call_fee' => 0],
            ['provider' => 'minimax', 'model' => 'image-01', 'feature' => 'image_generation', 'price_per_1k_input' => 0, 'price_per_1k_output' => 0.02, 'flat_call_fee' => 0],

            // Groq / OpenRouter (free tier compatible)
            ['provider' => 'groq', 'model' => 'llama-3.3-70b', 'feature' => 'chat', 'price_per_1k_input' => 0, 'price_per_1k_output' => 0, 'flat_call_fee' => 0],
            ['provider' => 'groq', 'model' => 'mixtral-8x7b', 'feature' => 'chat', 'price_per_1k_input' => 0, 'price_per_1k_output' => 0, 'flat_call_fee' => 0],

            // OpenAI
            ['provider' => 'openai', 'model' => 'gpt-4o', 'feature' => 'chat', 'price_per_1k_input' => 0.0025, 'price_per_1k_output' => 0.01, 'flat_call_fee' => 0],
            ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'feature' => 'chat', 'price_per_1k_input' => 0.00015, 'price_per_1k_output' => 0.0006, 'flat_call_fee' => 0],
            ['provider' => 'openai', 'model' => 'gpt-4o', 'feature' => 'vision', 'price_per_1k_input' => 0.0025, 'price_per_1k_output' => 0.01, 'flat_call_fee' => 0],
            ['provider' => 'openai', 'model' => 'dall-e-3', 'feature' => 'image_generation', 'price_per_1k_input' => 0, 'price_per_1k_output' => 0.04, 'flat_call_fee' => 0.12],

            // OpenRouter (aggregate)
            ['provider' => 'openrouter', 'model' => 'anthropic/claude-3.5-sonnet', 'feature' => 'chat', 'price_per_1k_input' => 0.003, 'price_per_1k_output' => 0.015, 'flat_call_fee' => 0],
            ['provider' => 'openrouter', 'model' => 'google/gemini-pro-1.5', 'feature' => 'chat', 'price_per_1k_input' => 0.00125, 'price_per_1k_output' => 0.005, 'flat_call_fee' => 0],

            // Generic features (fallback)
            ['provider' => 'minimax', 'model' => 'MiniMax-M3', 'feature' => 'code_generation', 'price_per_1k_input' => 0.001, 'price_per_1k_output' => 0.003, 'flat_call_fee' => 0],
            ['provider' => 'minimax', 'model' => 'MiniMax-M3', 'feature' => 'research', 'price_per_1k_input' => 0.002, 'price_per_1k_output' => 0.005, 'flat_call_fee' => 0],
            ['provider' => 'minimax', 'model' => 'MiniMax-M3', 'feature' => 'redesign', 'price_per_1k_input' => 0.002, 'price_per_1k_output' => 0.005, 'flat_call_fee' => 0],
        ];

        foreach ($pricing as $row) {
            AIPricing::updateOrCreate(
                ['provider' => $row['provider'], 'model' => $row['model'], 'feature' => $row['feature']],
                $row
            );
        }

        $this->command->info("AI Pricing seeded: " . count($pricing) . " entries");
    }
}
