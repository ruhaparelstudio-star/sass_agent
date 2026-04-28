<?php

namespace App\Modules\WhatsApp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalSecret
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('whatsapp.internal_secret', '');

        if ($secret === '') {
            return $next($request);
        }

        $provided = (string) $request->header('X-Internal-Secret', '');

        if (! hash_equals($secret, $provided)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
