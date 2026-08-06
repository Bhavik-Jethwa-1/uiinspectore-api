<?php

namespace App\Services\AI;

use App\Services\AI\Providers\HuggingFaceImageProvider;
use App\Services\AI\Providers\OpenAIImageProvider;
use Illuminate\Support\Facades\Log;

/**
 * Factory for creating Image Generation Providers
 * 
 * HuggingFace is the primary provider (free, no billing issues).
 * OpenAI is secondary (high quality but requires credits).
 */
class ImageProviderFactory
{
    /**
     * Get the appropriate image generation provider
     * 
     * Priority: HuggingFace (free) → OpenAI (paid)
     */
    public function create(?string $env = null): ImageProviderInterface
    {
        $env = $env ?? env('APP_ENV', 'production');

        // Try HuggingFace first (free, no billing issues)
        $hfProvider = new HuggingFaceImageProvider();
        $hfStatus = $hfProvider->availability();

        if ($hfStatus['available'] ?? false) {
            Log::info("ImageProviderFactory: Using HuggingFace (primary provider)", [
                'environment' => $env,
            ]);
            return $hfProvider;
        }

        // Fallback to OpenAI if HuggingFace unavailable
        $openaiProvider = new OpenAIImageProvider();
        $openaiStatus = $openaiProvider->availability();

        if ($openaiStatus['available'] ?? false) {
            Log::info("ImageProviderFactory: Using OpenAI (fallback provider)", [
                'environment' => $env,
                'reason' => 'HuggingFace unavailable: ' . ($hfStatus['error'] ?? 'unknown'),
            ]);
            return $openaiProvider;
        }

        // Neither available
        throw new \RuntimeException(
            'No AI image generation provider available. ' .
            'HuggingFace: ' . ($hfStatus['error'] ?? 'unavailable') . '. ' .
            'OpenAI: ' . ($openaiStatus['error'] ?? 'unavailable') . '.'
        );
    }

    /**
     * Get status of all providers
     */
    public function getAllProviders(): array
    {
        $hfProvider = new HuggingFaceImageProvider();
        $openaiProvider = new OpenAIImageProvider();

        return [
            'huggingface' => $hfProvider->availability(),
            'openai' => $openaiProvider->availability(),
        ];
    }

    /**
     * Get active provider info
     */
    public function getActiveProviderInfo(): array
    {
        $hfProvider = new HuggingFaceImageProvider();
        $hfStatus = $hfProvider->availability();

        if ($hfStatus['available'] ?? false) {
            return [
                'environment' => env('APP_ENV', 'production'),
                'active_provider' => 'huggingface',
                'active_provider_name' => 'Hugging Face',
                'available' => true,
            ];
        }

        $openaiProvider = new OpenAIImageProvider();
        $openaiStatus = $openaiProvider->availability();

        if ($openaiStatus['available'] ?? false) {
            return [
                'environment' => env('APP_ENV', 'production'),
                'active_provider' => 'openai',
                'active_provider_name' => 'OpenAI GPT Image',
                'available' => true,
            ];
        }

        return [
            'environment' => env('APP_ENV', 'production'),
            'active_provider' => null,
            'active_provider_name' => null,
            'available' => false,
            'error' => 'No providers available',
        ];
    }

    /**
     * Get provider priority list
     */
    public function getProviderPriority(): array
    {
        return [
            ['provider' => 'huggingface', 'priority' => 1, 'reason' => 'Free, no billing issues'],
            ['provider' => 'openai', 'priority' => 2, 'reason' => 'High quality (needs credits)'],
        ];
    }
}
