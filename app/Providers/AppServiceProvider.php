<?php

namespace App\Providers;

use App\Modules\AiLayer\Contracts\IntentClassifierContract;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\Services\DeterministicIntentClassifier;
use App\Modules\AiLayer\Services\OpenAiLlmClient;
use App\Modules\Calendar\Contracts\CalendarAvailabilityProvider;
use App\Modules\Calendar\Services\FakeCalendarAvailabilityProvider;
use App\Modules\Calendar\Services\GoogleCalendarProvider;
use App\Modules\Validation\Contracts\ActionPermissionValidator;
use App\Modules\Validation\Contracts\GroundingValidator;
use App\Modules\Validation\Contracts\ModeValidator;
use App\Modules\Validation\Contracts\PolicyValidator;
use App\Modules\Validation\Services\ActionPermissionValidatorService;
use App\Modules\Validation\Services\GroundingValidatorService;
use App\Modules\Validation\Services\ModeValidatorService;
use App\Modules\Validation\Services\PolicyValidatorService;
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
        $this->app->bind(IntentClassifierContract::class, DeterministicIntentClassifier::class);
        $this->app->bind(PolicyValidator::class, PolicyValidatorService::class);
        $this->app->bind(GroundingValidator::class, GroundingValidatorService::class);
        $this->app->bind(ActionPermissionValidator::class, ActionPermissionValidatorService::class);
        $this->app->bind(ModeValidator::class, ModeValidatorService::class);
        $this->app->bind(CalendarAvailabilityProvider::class, function () {
            $clientId = (string) config('services.google.calendar.client_id', '');
            if ($clientId !== '') {
                return app(GoogleCalendarProvider::class);
            }

            return app(FakeCalendarAvailabilityProvider::class);
        });
        $this->app->bind(LlmClientContract::class, function () {
            $provider = (string) config('ai.provider', 'openai');

            return match ($provider) {
                'openai' => app(OpenAiLlmClient::class),
                default => throw new \RuntimeException('Unsupported AI provider: '.$provider),
            };
        });
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
