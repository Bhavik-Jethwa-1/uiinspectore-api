<?php

namespace App\Services\AI;

/**
 * ImageGenerationService — dedicated service for AI image generation.
 *
 * IMPORTANT: This service ONLY uses dedicated image generation endpoints.
 * It NEVER routes through the chat completion endpoint.
 * It NEVER searches Google, Bing, Unsplash, or any web search.
 * It NEVER returns stock images.
 */
class ImageGenerationService
{
    private \App\Services\AI\Providers\ProviderManager $manager;

    public function __construct(?\App\Services\AI\Providers\ProviderManager $manager = null)
    {
        $this->manager = $manager ?? new \App\Services\AI\Providers\ProviderManager();
    }

    public function generate(string $prompt, array $opts = []): array
    {
        $result = $this->manager->image($prompt, $opts);

        if (!empty($result['error']) && ($result['status'] ?? 0) === 503) {
            $result['error'] = 'Image generation is unavailable for the selected provider. '
                . 'Please configure an AI provider with image generation support in Admin Settings.';
        }

        $result['capability'] = 'image';
        return $result;
    }

    public function variations(string $prompt, array $opts = []): array
    {
        return $this->generate($prompt, array_merge($opts, ['n' => 4]));
    }

    public function generateHD(string $prompt, array $opts = []): array
    {
        return $this->generate($prompt, array_merge($opts, ['size' => 'hd', 'quality' => 'hd']));
    }

    public function generateLandscape(string $prompt, array $opts = []): array
    {
        return $this->generate($prompt, array_merge($opts, ['size' => 'landscape', 'aspect' => '16:9']));
    }

    public function generatePortrait(string $prompt, array $opts = []): array
    {
        return $this->generate($prompt, array_merge($opts, ['size' => 'portrait', 'aspect' => '9:16']));
    }

    public function generateSquare(string $prompt, array $opts = []): array
    {
        return $this->generate($prompt, array_merge($opts, ['size' => 'square', 'aspect' => '1:1']));
    }

    public function isAvailable(): bool
    {
        foreach ($this->manager->listProviders() as $info) {
            if ($info['available'] && in_array('image', $info['capabilities'] ?? [], true)) {
                return true;
            }
        }
        return false;
    }
}
