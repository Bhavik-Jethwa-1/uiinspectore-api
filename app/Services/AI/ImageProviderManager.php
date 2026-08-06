<?php

namespace App\Services\AI;

use App\Services\AI\Providers\GPTImageProvider;
use App\Services\AI\Providers\PollinationsProvider;
use App\Services\AI\Providers\FalImageProvider;

/**
 * Manages all image generation providers.
 * Selects the best available provider based on settings or availability order.
 */
class ImageProviderManager
{
    /** @var array<string, ImageProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->providers = [
            'openai'       => new GPTImageProvider(),
            'pollinations' => new PollinationsProvider(),
            'fal'          => new FalImageProvider(),
        ];
    }

    /**
     * Get a specific provider by ID.
     */
    public function getProvider(string $id): ?ImageProviderInterface
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * Get all registered providers.
     * @return array<string, ImageProviderInterface>
     */
    public function getAllProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get provider status for all providers.
     * @return array<array{id: string, name: string, available: bool, reason?: string, suggestedFix?: string, models: array}>
     */
    public function getAllStatus(): array
    {
        $status = [];
        foreach ($this->providers as $id => $provider) {
            $avail = $provider->availability();
            $status[] = [
                'id' => $id,
                'name' => $provider->getName(),
                'available' => $avail['available'],
                'reason' => $avail['reason'] ?? null,
                'suggestedFix' => $avail['suggestedFix'] ?? null,
                'models' => $provider->getModels(),
            ];
        }
        return $status;
    }

    /**
     * Get the best available provider for img2img (image editing).
     * Prefers: fal > openai > pollinations
     */
    public function getBestForImg2Img(): ?ImageProviderInterface
    {
        $order = ['fal', 'openai', 'pollinations'];
        foreach ($order as $id) {
            $p = $this->providers[$id] ?? null;
            if ($p && $p->availability()['available']) {
                // Check if it has img2img support
                foreach ($p->getModels() as $model) {
                    if ($model['supportsImg2Img']) return $p;
                }
            }
        }
        // Fallback: return first available that has img2img
        foreach ($this->providers as $p) {
            if ($p->availability()['available']) {
                foreach ($p->getModels() as $model) {
                    if ($model['supportsImg2Img']) return $p;
                }
            }
        }
        // Last resort: any available provider
        foreach ($this->providers as $p) {
            if ($p->availability()['available']) return $p;
        }
        return null;
    }

    /**
     * Get the default provider (user's setting or first available).
     */
    public function getDefaultProvider(?string $preferredId = null): ?ImageProviderInterface
    {
        if ($preferredId && isset($this->providers[$preferredId])) {
            $p = $this->providers[$preferredId];
            if ($p->availability()['available']) return $p;
        }
        // Try in order of preference
        foreach (['openai', 'pollinations', 'fal'] as $id) {
            $p = $this->providers[$id] ?? null;
            if ($p && $p->availability()['available']) return $p;
        }
        return null;
    }
}
