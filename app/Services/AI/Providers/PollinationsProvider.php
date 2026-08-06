<?php

namespace App\Services\AI\Providers;

use App\Services\AI\ImageProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pollinations.ai provider - completely free, no API key required.
 * Supports text-to-image. Can use image URL as style reference (not true img2img).
 * URL: https://image.pollinations.ai/prompt/[encoded_prompt]?width=1024&height=1024&model=flux&seed=42
 */
class PollinationsProvider implements ImageProviderInterface
{
    public function getName(): string { return 'Pollinations AI'; }
    public function getId(): string { return 'pollinations'; }

    public function getModels(): array
    {
        return [
            [
                'id' => 'flux',
                'name' => 'FLUX.1 Schnell',
                'supportsImg2Img' => false, // Not true img2img, but can use image URL as seed
                'costPerCall' => 0,
            ],
            [
                'id' => 'flux-dev',
                'name' => 'FLUX.1 Dev',
                'supportsImg2Img' => false,
                'costPerCall' => 0,
            ],
            [
                'id' => 'turbo',
                'name' => 'Turbo',
                'supportsImg2Img' => false,
                'costPerCall' => 0,
            ],
        ];
    }

    public function availability(): array
    {
        // Always available - no API key needed
        return ['available' => true];
    }

    public function supportsImg2Img(): bool
    {
        return false; // Pollinations uses image URL as style ref, not true img2img
    }

    public function generate(?string $inputImagePath, string $prompt, array $options = []): array
    {
        $start = microtime(true);
        $model = $options['model'] ?? 'flux';
        $width = (int)($options['width'] ?? 1024);
        $height = (int)($options['height'] ?? 1024);
        $seed = $options['seed'] ?? random_int(1, 999999);
        $style = $options['style'] ?? 'natural';

        // Pollinations prompt format: encode the prompt and build URL
        $stylePrefix = $this->getStylePrefix($style);
        $fullPrompt = $stylePrefix . ' ' . $prompt;

        // If we have an input image, append a style reference to the prompt
        // Note: Pollinations doesn't support true img2img, but we can reference the image
        if ($inputImagePath && file_exists($inputImagePath)) {
            // Generate a URL for the input image from our storage
            $inputUrl = url(Storage::url($inputImagePath));
            $fullPrompt .= " [style reference: {$inputUrl}]";
        }

        $encodedPrompt = rawurlencode($fullPrompt);
        $url = "https://image.pollinations.ai/prompt/{$encodedPrompt}?width={$width}&height={$height}&model={$model}&seed={$seed}&n=1";

        try {
            // Download the image
            $res = Http::timeout(60)->get($url);

            if ($res->status() !== 200) {
                return [
                    'success' => false,
                    'error' => "Pollinations returned HTTP {$res->status()}",
                    'errorCode' => 'HTTP_' . $res->status(),
                    'generationTimeMs' => (int)((microtime(true) - $start) * 1000),
                ];
            }

            $contentType = $res->header('Content-Type', '');
            if (str_contains($contentType, 'text') || $res->body() === '') {
                return [
                    'success' => false,
                    'error' => 'Pollinations returned an error page instead of an image',
                    'errorCode' => 'ERROR_PAGE',
                    'generationTimeMs' => (int)((microtime(true) - $start) * 1000),
                ];
            }

            // Save to storage
            $filename = 'ai-images/' . Str::uuid() . '.png';
            $savePath = storage_path("app/public/{$filename}");
            $dir = dirname($savePath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            file_put_contents($savePath, $res->body());

            if (!file_exists($savePath) || filesize($savePath) < 1000) {
                return [
                    'success' => false,
                    'error' => 'Failed to save image or image too small',
                    'errorCode' => 'SAVE_FAILED',
                    'generationTimeMs' => (int)((microtime(true) - $start) * 1000),
                ];
            }

            return [
                'success' => true,
                'imagePath' => $filename,
                'revisedPrompt' => null,
                'model' => $model,
                'generationTimeMs' => (int)((microtime(true) - $start) * 1000),
                'costUsd' => 0,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'errorCode' => 'EXCEPTION',
                'generationTimeMs' => (int)((microtime(true) - $start) * 1000),
            ];
        }
    }

    private function getStylePrefix(string $style): string
    {
        return match ($style) {
            'vivid' => 'vivid colors, vibrant, highly saturated, professional photography',
            'natural' => 'natural, realistic, professional photography',
            'anime' => 'anime style, cel shaded, japanese animation',
            'digital_art' => 'digital art, concept art, detailed illustration',
            'cinematic' => 'cinematic, film still, dramatic lighting, movie scene',
            '3d' => '3D render, octane render, cinema 4D, detailed',
            default => 'professional, high quality, detailed',
        };
    }
}
