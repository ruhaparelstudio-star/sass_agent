<?php

namespace App\Modules\Calendar\Services;

use App\Models\CalendarAvailabilityCheck;
use App\Models\CalendarConnection;
use App\Models\CalendarSetting;
use App\Models\Tenant;
use App\Modules\Calendar\Contracts\CalendarAvailabilityProvider;
use Illuminate\Support\Facades\Cache;

class CalendarAvailabilityService
{
    private const SUCCESS_CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly CalendarAvailabilityProvider $provider)
    {
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array{status:string,checked:bool,available:bool,reason:?string,source:string,meta?:array<string,mixed>}
     */
    public function check(Tenant $tenant, array $request): array
    {
        $connection = CalendarConnection::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_enabled')
            ->orderByDesc('id')
            ->first();

        $setting = CalendarSetting::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->first();

        if (! $connection || ! $setting || ! $connection->is_enabled || $connection->status !== 'connected') {
            $result = [
                'status' => 'blocked',
                'checked' => false,
                'available' => false,
                'reason' => 'calendar_integration_disabled',
                'source' => 'disabled_fallback',
            ];

            $this->persistCheck($tenant, $connection, $request, $result);

            return $result;
        }

        $cacheKey = $this->successCacheKey($tenant->id, $request);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            // Persist a fresh audit row even on cache hit so the trail stays complete.
            $this->persistCheck($tenant, $connection, $request, $cached);
            return $cached;
        }

        try {
            $result = $this->provider->checkAvailability($tenant->id, $request);
        } catch (\Throwable) {
            $result = [
                'status' => 'blocked',
                'checked' => false,
                'available' => false,
                'reason' => 'calendar_provider_error',
                'source' => 'error_fallback',
            ];

            $this->persistCheck($tenant, $connection, $request, $result);

            return $result;
        }

        $normalized = [
            'status'    => (string) ($result['status'] ?? 'blocked'),
            'checked'   => ($result['checked'] ?? false) === true,
            'available' => ($result['available'] ?? false) === true,
            'reason'    => isset($result['reason']) && is_string($result['reason']) ? $result['reason'] : null,
            'source'    => is_string($result['source'] ?? null) && trim((string) $result['source']) !== ''
                ? (string) $result['source']
                : 'unknown',
            'meta'      => is_array($result['meta'] ?? null) ? $result['meta'] : null,
        ];

        $this->persistCheck($tenant, $connection, $request, $normalized);

        // Cache only confirmed-available successes — errors and disabled states must not be cached.
        if ($normalized['checked'] === true && $normalized['available'] === true) {
            Cache::put($cacheKey, $normalized, self::SUCCESS_CACHE_TTL_SECONDS);
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $request
     */
    private function successCacheKey(int $tenantId, array $request): string
    {
        return 'calendar_avail:'.$tenantId.':'.md5((string) json_encode($request));
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $result
     */
    private function persistCheck(Tenant $tenant, ?CalendarConnection $connection, array $request, array $result): void
    {
        CalendarAvailabilityCheck::query()->create([
            'tenant_id' => $tenant->id,
            'calendar_connection_id' => $connection?->id,
            'status' => (string) ($result['status'] ?? 'blocked'),
            'checked' => ($result['checked'] ?? false) === true,
            'available' => ($result['available'] ?? false) === true,
            'reason' => is_string($result['reason'] ?? null) ? $result['reason'] : null,
            'source' => is_string($result['source'] ?? null) ? $result['source'] : 'unknown',
            'request_payload' => $request,
            'response_payload' => is_array($result) ? $result : ['result' => $result],
            'checked_at' => now(),
        ]);
    }
}
