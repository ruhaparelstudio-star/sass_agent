<?php

namespace App\Modules\WhatsApp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnsureInboundRateLimit
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (int) $request->input('tenant_id');
        $from = $this->normalizePhone((string) $request->input('from', ''));

        $maxAttempts = max(1, (int) config('whatsapp.inbound_rate_limit.max_attempts', 30));
        $decaySeconds = max(1, (int) config('whatsapp.inbound_rate_limit.decay_seconds', 60));

        $key = sprintf('tenant:%d:from:%s', $tenantId, $from);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return new JsonResponse(['message' => 'Too Many Requests'], 429);
        }

        RateLimiter::hit($key, $decaySeconds);

        return $next($request);
    }

    private function normalizePhone(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'unknown';
        }

        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value);
        if (! is_string($digits) || $digits === '') {
            return 'unknown';
        }

        return $hasPlus ? '+'.$digits : $digits;
    }
}
