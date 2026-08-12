<?php

namespace App\Providers;

use App\Http\Middleware\DisableCsrfForPublicAuth;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Replace Laravel's default VerifyCsrfToken with our custom one
        // that excludes all api/* routes from CSRF protection
        $this->app->singleton(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, function () {
            return new DisableCsrfForPublicAuth();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
