<?php

namespace App\Modules\Operation\IT;

use App\Base\AI\Contracts\Tool;
use App\Modules\Core\AI\Contracts\AgentTaskContextContributor;
use App\Modules\Operation\IT\Services\TicketContextContributor;
use App\Modules\Operation\IT\Tools\TicketUpdateTool;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/it.php', 'it');

        $this->app->singleton(TicketUpdateTool::class);
        $this->app->tag(TicketUpdateTool::class, Tool::CONTAINER_TAG);

        $this->app->singleton(TicketContextContributor::class);
        $this->app->tag(TicketContextContributor::class, AgentTaskContextContributor::CONTAINER_TAG);
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'operation-it');
    }
}
