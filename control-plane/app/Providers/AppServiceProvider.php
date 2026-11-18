<?php

namespace App\Providers;

use App\Services\DataPlane\DataPlaneClient;
use App\Services\Media\ViewerToken;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Built per resolve so config changes (and tests' config()) apply.
        $this->app->bind(DataPlaneClient::class, fn () => DataPlaneClient::fromConfig());
        $this->app->bind(ViewerToken::class, fn () => ViewerToken::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
