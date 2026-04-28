<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenAuthenticated
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->currentAccessToken() === null) {
            return new JsonResponse([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
