<?php

namespace App\Domains\Operation\Quality;

use App\Domains\Operation\Quality\Contracts\NumberingService;
use App\Domains\Operation\Quality\Services\DefaultNumberingService;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/Config/quality.php',
            'quality'
        );

        $this->app->bind(
            NumberingService::class,
            DefaultNumberingService::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'operation-quality');
    }
}
