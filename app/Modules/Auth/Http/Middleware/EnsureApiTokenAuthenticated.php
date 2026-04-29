<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Shared\Services\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenAuthenticated
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->currentAccessToken() === null) {
            $this->auditLogger->logDenied(
                eventKey: 'auth.api_token.denied',
                statusCode: 401,
                reason: 'missing_or_invalid_access_token',
                tenantId: null,
                actorUserId: $user?->id,
                endpoint: $request->path(),
                context: $this->auditLogger->buildMinimalRequestContext($request)
            );

            return new JsonResponse([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
