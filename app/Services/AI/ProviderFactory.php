<?php

namespace App\Services\AI;

use App\Services\AI\Providers\PollinationsProvider;
use App\Services\AI\Providers\HuggingFaceProvider;
use App\Services\AI\Providers\GPTImageProvider;
use App\Services\AI\Providers\FalImageProvider;

/**
 * Factory for creating image generation provider instances.
 * New providers can be added here without changing any other code.
 */
class ProviderFactory
{
    /** @var array<string, class-string<ImageProviderInterface>> */
    private const PROVIDER_CLASSES = [
        'pollinations' => PollinationsProvider::class,
        'huggingface' => HuggingFaceProvider::class,
        'openai'       => GPTImageProvider::class,
        'fal'          => FalImageProvider::class,
    ];

    /** @var array<string, ImageProviderInterface> */
    private static array $instances = [];

    /**
     * Create (or get cached) provider instance by ID.
     */
    public static function make(string $id): ?ImageProviderInterface
    {
        $id = strtolower(trim($id));

        // Return cached if exists
        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }

        // Always available: Pollinations (no key needed)
        if ($id === 'pollinations') {
            return self::$instances[$id] = new PollinationsProvider();
        }

        // HuggingFace (no key needed for basic free tier)
        if ($id === 'huggingface') {
            return self::$instances[$id] = new HuggingFaceProvider();
        }

        // OpenAI GPT Image (requires billing — wrapped)
        if ($id === 'openai') {
            return self::$instances[$id] = new GPTImageProvider();
        }

        // Fal.ai (free tier, img2img capable)
        if ($id === 'fal') {
            return self::$instances[$id] = new FalImageProvider();
        }

        return null;
    }

    /**
     * Get all available provider instances (only those that are configured/available).
     * @return array<ImageProviderInterface>
     */
    public static function allAvailable(): array
    {
        $available = [];
        foreach (self::PROVIDER_CLASSES as $id => $class) {
            $instance = self::make($id);
            if ($instance && $instance->availability()['available']) {
                $available[$id] = $instance;
            }
        }
        return $available;
    }

    /**
     * Get all providers with their status (avail or not).
     * @return array<array{id: string, name: string, provider: ImageProviderInterface, status: array}>
     */
    public static function allWithStatus(): array
    {
        $result = [];
        foreach (self::PROVIDER_CLASSES as $id => $class) {
            $instance = self::make($id);
            $status = $instance ? $instance->availability() : ['available' => false, 'reason' => 'Unknown provider'];
            $result[] = [
                'id' => $id,
                'name' => $instance?->getName() ?? $id,
                'models' => $instance?->getModels() ?? [],
                'status' => $status,
            ];
        }
        return $result;
    }

    /**
     * Get the best free provider (Pollinations by default, fallback to HuggingFace).
     */
    public static function bestFree(): ?ImageProviderInterface
    {
        $p = self::make('pollinations');
        if ($p && $p->availability()['available']) {
            return $p;
        }
        $p = self::make('huggingface');
        if ($p && $p->availability()['available']) {
            return $p;
        }
        return null;
    }
}
