<?php

namespace App\Services\AI;

use App\Services\AI\Providers\ProviderManager;

/**
 * AI Service Locator — maintains singleton instances of AI services
 * to avoid repeated instantiation overhead on every request.
 *
 * All AI services should be accessed through this locator.
 */
class AIServiceLocator
{
    private static ?AIEngine $engine = null;
    private static ?AIService $service = null;
    private static ?ProviderManager $manager = null;

    public static function engine(): AIEngine
    {
        if (self::$engine === null) {
            self::$engine = new AIEngine();
        }
        return self::$engine;
    }

    public static function service(): AIService
    {
        if (self::$service === null) {
            self::$service = new AIService();
        }
        return self::$service;
    }

    public static function manager(): ProviderManager
    {
        if (self::$manager === null) {
            self::$manager = ProviderManager::getInstance();
        }
        return self::$manager;
    }

    /**
     * Reset all singletons (useful for testing)
     */
    public static function reset(): void
    {
        self::$engine = null;
        self::$service = null;
        self::$manager = null;
    }
}
