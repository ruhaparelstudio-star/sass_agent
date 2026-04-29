<?php

namespace App\Modules\WhatsApp\Http\Middleware;

use App\Modules\Shared\Services\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalSecret
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('whatsapp.internal_secret', '');
        $appEnv = (string) config('app.env', 'production');
        $isTesting = $appEnv === 'testing';

        if ($secret === '') {
            if (! $isTesting) {
                $this->auditLogger->logDenied(
                    eventKey: 'whatsapp.internal_secret.denied',
                    statusCode: 403,
                    reason: 'internal_secret_not_configured',
                    tenantId: is_numeric($request->input('tenant_id')) ? (int) $request->input('tenant_id') : null,
                    actorUserId: $request->user()?->id,
                    endpoint: $request->path(),
                    context: $this->auditLogger->buildMinimalRequestContext($request)
                );

                return new JsonResponse(['message' => 'Forbidden'], 403);
            }

            return $next($request);
        }

        $provided = (string) $request->header('X-Internal-Secret', '');

        if (! hash_equals($secret, $provided)) {
            $this->auditLogger->logDenied(
                eventKey: 'whatsapp.internal_secret.denied',
                statusCode: 403,
                reason: 'invalid_internal_secret',
                tenantId: is_numeric($request->input('tenant_id')) ? (int) $request->input('tenant_id') : null,
                actorUserId: $request->user()?->id,
                endpoint: $request->path(),
                context: $this->auditLogger->buildMinimalRequestContext($request)
            );

            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
