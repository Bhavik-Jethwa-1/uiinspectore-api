<?php

namespace App\Services\AI;

use App\Services\AI\Providers\PollinationsProvider;
use App\Services\AI\Providers\HuggingFaceProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FreeImageService — unified free image generation with automatic provider fallback.
 *
 * Tries providers in order until one succeeds:
 *   1. Pollinations AI (always free, no API key)
 *   2. HuggingFace Inference (free tier, img2img capable)
 *
 * Never blocks on billing. Never requires OpenAI/Groq/MiniMax.
 */
class FreeImageService
{
    private ?PollinationsProvider $pollinations = null;
    private ?HuggingFaceProvider $huggingface = null;

    // Standard redesign prompt template
    public const REDESIGN_PROMPT = <<<'TEMPLATE'
Redesign this exact UI screenshot.

Preserve the complete layout.
Preserve sidebar.
Preserve navigation.
Preserve branding.
Preserve buttons.
Preserve cards.
Preserve tables.
Preserve forms.

Improve spacing.
Improve typography.
Improve colors.
Improve shadows.
Improve visual hierarchy.

Modern SaaS design.

Do NOT generate another dashboard.
Do NOT change the layout.
Return only the improved version of this exact UI.
TEMPLATE;

    public function __construct()
    {
        $this->pollinations = new PollinationsProvider();
        $this->huggingface = new HuggingFaceProvider();
    }

    /**
     * Generate an improved UI image from an original screenshot.
     * Provider auto-falls back on failure.
     *
     * @param string|null $originalImagePath Absolute path to original screenshot
     * @param string|null $customPrompt     Custom prompt override
     * @param array       $options          width, height, model, style, strength
     * @return array{
     *   success: bool,
     *   imagePath?: string,
     *   provider?: string,
     *   model?: string,
     *   generationTimeMs?: int,
     *   error?: string,
     *   errorCode?: string,
     *   warning?: string  // if provider doesn't support img2img
     * }
     */
    public function generateUIImage(
        ?string $originalImagePath,
        ?string $customPrompt = null,
        array $options = []
    ): array {
        $prompt = $customPrompt ?? self::REDESIGN_PROMPT;
        $start = microtime(true);

        // Try Pollinations first (always free, always available)
        $result = $this->pollinations->generate($originalImagePath, $prompt, $options);
        if ($result['success']) {
            $result['provider'] = 'pollinations';
            $result['generationTimeMs'] = (int)((microtime(true) - $start) * 1000);
            // Warn: Pollinations is text-to-image only
            if ($originalImagePath && file_exists($originalImagePath)) {
                $result['warning'] = 'This provider does not support true UI redesign. Results may vary — it generates a new image based on the prompt, not an edit of the original.';
            }
            return $result;
        }

        // Fall back to HuggingFace (free img2img)
        $hfOptions = array_merge($options, [
            'strength' => $options['strength'] ?? 0.7,
        ]);
        $result = $this->huggingface->generate($originalImagePath, $prompt, $hfOptions);
        if ($result['success']) {
            $result['provider'] = 'huggingface';
            $result['generationTimeMs'] = (int)((microtime(true) - $start) * 1000);
            return $result;
        }

        // Both failed
        return [
            'success' => false,
            'error' => 'All free providers failed. ' . ($result['error'] ?? 'Unknown error.'),
            'errorCode' => 'ALL_PROVIDERS_FAILED',
            'generationTimeMs' => (int)((microtime(true) - $start) * 1000),
            'providers_tried' => ['pollinations', 'huggingface'],
        ];
    }

    /**
     * Generate an image from just a text prompt (no original).
     */
    public function generateFromPrompt(string $prompt, array $options = []): array
    {
        return $this->generateUIImage(null, $prompt, $options);
    }

    /**
     * Get status of all free providers.
     */
    public function providerStatus(): array
    {
        return [
            'pollinations' => $this->pollinations->availability(),
            'huggingface'  => $this->huggingface->availability(),
        ];
    }

    /**
     * Get the best available provider that supports img2Img.
     */
    public function bestImg2ImgProvider(): string
    {
        $hf = $this->huggingface->availability();
        if ($hf['available']) {
            return 'huggingface';
        }
        return 'pollinations';
    }
}
