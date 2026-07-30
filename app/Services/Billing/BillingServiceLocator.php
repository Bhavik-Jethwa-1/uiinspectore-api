<?php

namespace App\Services\Billing;

/**
 * Billing Service Locator — singleton instances for wallet and usage services
 * to avoid repeated instantiation overhead on every request.
 */
class BillingServiceLocator
{
    private static ?WalletService $wallet = null;
    private static ?AIUsageService $aiUsage = null;
    private static ?UsageService $usage = null;
    private static ?BillingService $billing = null;

    public static function wallet(): WalletService
    {
        if (self::$wallet === null) {
            self::$wallet = new WalletService();
        }
        return self::$wallet;
    }

    public static function aiUsage(): AIUsageService
    {
        if (self::$aiUsage === null) {
            self::$aiUsage = new AIUsageService(self::wallet());
        }
        return self::$aiUsage;
    }

    public static function usage(): UsageService
    {
        if (self::$usage === null) {
            self::$usage = new UsageService();
        }
        return self::$usage;
    }

    public static function billing(): BillingService
    {
        if (self::$billing === null) {
            self::$billing = new BillingService(self::usage());
        }
        return self::$billing;
    }

    public static function reset(): void
    {
        self::$wallet = null;
        self::$aiUsage = null;
        self::$usage = null;
        self::$billing = null;
    }
}
