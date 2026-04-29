<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Auth\Http\Middleware\EnsureApiTokenAuthenticated;
use App\Modules\WhatsApp\Http\Middleware\EnsureInboundRateLimit;
use App\Modules\WhatsApp\Http\Middleware\EnsureInternalSecret;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'api.token' => EnsureApiTokenAuthenticated::class,
            'wa.internal.secret' => EnsureInternalSecret::class,
            'wa.inbound.rate_limit' => EnsureInboundRateLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (SymfonyResponse $response): SymfonyResponse {
            if ($response->getStatusCode() < 400) {
                return $response;
            }

            $content = $response->getContent();
            if (! is_string($content) || $content === '') {
                return $response;
            }

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return $response;
            }

            $sensitiveKeys = [
                'authorization',
                'password',
                'password_confirmation',
                'token',
                'api_key',
                'secret',
                'x-internal-secret',
                'wa_internal_secret',
            ];

            $sanitize = function (array $payload) use (&$sanitize, $sensitiveKeys): array {
                foreach ($payload as $key => $value) {
                    if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                        $payload[$key] = '[REDACTED]';
                        continue;
                    }

                    if (is_array($value)) {
                        $payload[$key] = $sanitize($value);
                    }
                }

                return $payload;
            };

            $redacted = $sanitize($decoded);

            if (isset($redacted['errors']) && is_array($redacted['errors'])) {
                foreach (array_keys($redacted['errors']) as $errorKey) {
                    if (is_string($errorKey) && in_array(strtolower($errorKey), $sensitiveKeys, true)) {
                        if (isset($redacted['message']) && is_string($redacted['message'])) {
                            $redacted['message'] = 'The given data was invalid.';
                        }
                        break;
                    }
                }
            }

            $json = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $response->setContent($json);
            }

            return $response;
        });
    })->create();
