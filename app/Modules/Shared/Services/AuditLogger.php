<?php

namespace App\Modules\Shared\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function logDenied(
        string $eventKey,
        int $statusCode,
        string $reason,
        ?int $tenantId,
        ?int $actorUserId,
        string $endpoint,
        array $context = []
    ): void {
        AuditLog::query()->create([
            'event_key' => $eventKey,
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'reason' => $reason,
            'context' => $this->sanitizeContext($context),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildMinimalRequestContext(Request $request): array
    {
        return [
            'method' => $request->method(),
            'route' => $request->path(),
            'ip' => (string) $request->ip(),
            'tenant_hint' => $request->input('tenant_id'),
            'headers' => [
                'authorization' => (string) $request->header('Authorization', ''),
                'x-internal-secret' => (string) $request->header('X-Internal-Secret', ''),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function sanitizeContext(array $context): array
    {
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

        return $sanitize($context);
    }
}
