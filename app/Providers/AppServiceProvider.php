<?php

namespace App\Providers;

use App\Modules\WhatsApp\Contracts\WaGatewayClient;
use App\Modules\WhatsApp\Services\HttpWaGatewayClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WaGatewayClient::class, HttpWaGatewayClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
