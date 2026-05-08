<?php

namespace Tests\Unit\Calendar;

use App\Models\Tenant;
use App\Modules\Calendar\Contracts\CalendarAvailabilityProvider;
use App\Modules\Calendar\Services\BookingCapacityProvider;
use App\Modules\Calendar\Services\CalendarAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_integration_returns_blocked_fallback_and_logs_check(): void
    {
        $tenant = $this->createTenant('tenant-a');

        $connection = $tenant->calendarConnections()->create([
            'provider' => 'fake',
            'status' => 'disconnected',
            'is_enabled' => false,
            'config' => ['mode' => 'off'],
        ]);

        $tenant->calendarSettings()->create([
            'timezone' => 'Asia/Jakarta',
            'slot_minutes' => 60,
            'is_active' => true,
        ]);

        $result = app(CalendarAvailabilityService::class)->check($tenant, [
            'event_date_iso' => '2026-05-05',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['available']);
        $this->assertFalse($result['checked']);
        $this->assertSame('calendar_integration_disabled', $result['reason']);
        $this->assertSame('disabled_fallback', $result['source']);

        $this->assertDatabaseHas('calendar_availability_checks', [
            'tenant_id' => $tenant->id,
            'calendar_connection_id' => $connection->id,
            'status' => 'blocked',
            'available' => false,
            'reason' => 'calendar_integration_disabled',
            'source' => 'disabled_fallback',
        ]);
    }

    public function test_provider_error_returns_blocked_fallback_and_logs_check(): void
    {
        $tenant = $this->createTenant('tenant-b');

        $connection = $tenant->calendarConnections()->create([
            'provider' => 'fake',
            'status' => 'connected',
            'is_enabled' => true,
            'config' => ['mode' => 'on'],
        ]);

        $tenant->calendarSettings()->create([
            'timezone' => 'Asia/Jakarta',
            'slot_minutes' => 60,
            'is_active' => true,
        ]);

        $this->app->bind(CalendarAvailabilityProvider::class, fn () => new class implements CalendarAvailabilityProvider {
            public function checkAvailability(int $tenantId, array $request): array
            {
                throw new \RuntimeException('provider exploded');
            }
        });

        $result = app(CalendarAvailabilityService::class)->check($tenant, [
            'event_date_iso' => '2026-05-06',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['available']);
        $this->assertFalse($result['checked']);
        $this->assertSame('calendar_provider_error', $result['reason']);
        $this->assertSame('error_fallback', $result['source']);

        $this->assertDatabaseHas('calendar_availability_checks', [
            'tenant_id' => $tenant->id,
            'calendar_connection_id' => $connection->id,
            'status' => 'blocked',
            'available' => false,
            'reason' => 'calendar_provider_error',
            'source' => 'error_fallback',
        ]);
    }

    public function test_tenant_isolation_uses_only_current_tenant_connection_and_settings(): void
    {
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');

        $tenantA->calendarConnections()->create([
            'provider' => 'fake',
            'status' => 'disconnected',
            'is_enabled' => false,
            'config' => ['mode' => 'off'],
        ]);
        $tenantA->calendarSettings()->create([
            'timezone' => 'Asia/Jakarta',
            'slot_minutes' => 60,
            'is_active' => true,
        ]);

        $connectionB = $tenantB->calendarConnections()->create([
            'provider' => 'fake',
            'status' => 'connected',
            'is_enabled' => true,
            'config' => ['mode' => 'on'],
        ]);
        $tenantB->calendarSettings()->create([
            'timezone' => 'Asia/Jakarta',
            'slot_minutes' => 60,
            'is_active' => true,
        ]);

        $this->app->bind(CalendarAvailabilityProvider::class, BookingCapacityProvider::class);

        $result = app(CalendarAvailabilityService::class)->check($tenantB, [
            'event_date_iso' => '2026-05-07',
        ]);

        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['available']);
        $this->assertTrue($result['checked']);
        $this->assertSame('booking_capacity', $result['source']);

        $this->assertDatabaseHas('calendar_availability_checks', [
            'tenant_id' => $tenantB->id,
            'calendar_connection_id' => $connectionB->id,
            'status' => 'available',
            'available' => true,
            'source' => 'booking_capacity',
        ]);

        $this->assertDatabaseMissing('calendar_availability_checks', [
            'tenant_id' => $tenantA->id,
            'status' => 'available',
        ]);
    }

    public function test_successful_result_is_cached_for_60s_provider_called_only_once(): void
    {
        $tenant = $this->createTenant('tenant-cache');

        $tenant->calendarConnections()->create([
            'provider' => 'fake',
            'status' => 'connected',
            'is_enabled' => true,
            'config' => ['mode' => 'on'],
        ]);
        $tenant->calendarSettings()->create([
            'timezone' => 'Asia/Jakarta',
            'slot_minutes' => 60,
            'is_active' => true,
        ]);

        $callCount = 0;
        $this->app->bind(CalendarAvailabilityProvider::class, function () use (&$callCount) {
            return new class($callCount) implements CalendarAvailabilityProvider {
                public function __construct(public int &$count) {}
                public function checkAvailability(int $tenantId, array $request): array
                {
                    $this->count++;
                    return [
                        'status' => 'available',
                        'checked' => true,
                        'available' => true,
                        'reason' => null,
                        'source' => 'fake_provider',
                    ];
                }
            };
        });

        $service = app(CalendarAvailabilityService::class);

        $r1 = $service->check($tenant, ['event_date_iso' => '2026-09-09']);
        $r2 = $service->check($tenant, ['event_date_iso' => '2026-09-09']);

        $this->assertTrue($r1['available']);
        $this->assertTrue($r2['available']);
        $this->assertSame(1, $callCount, 'provider should be invoked only once for cached success');

        // Audit trail row created on every call (cache hit too).
        $this->assertSame(2, \DB::table('calendar_availability_checks')
            ->where('tenant_id', $tenant->id)
            ->count());
    }

    public function test_provider_error_is_not_cached_and_retried_each_call(): void
    {
        $tenant = $this->createTenant('tenant-cache-err');

        $tenant->calendarConnections()->create([
            'provider' => 'fake',
            'status' => 'connected',
            'is_enabled' => true,
            'config' => ['mode' => 'on'],
        ]);
        $tenant->calendarSettings()->create([
            'timezone' => 'Asia/Jakarta',
            'slot_minutes' => 60,
            'is_active' => true,
        ]);

        $callCount = 0;
        $this->app->bind(CalendarAvailabilityProvider::class, function () use (&$callCount) {
            return new class($callCount) implements CalendarAvailabilityProvider {
                public function __construct(public int &$count) {}
                public function checkAvailability(int $tenantId, array $request): array
                {
                    $this->count++;
                    throw new \RuntimeException('boom');
                }
            };
        });

        $service = app(CalendarAvailabilityService::class);

        $service->check($tenant, ['event_date_iso' => '2026-10-10']);
        $service->check($tenant, ['event_date_iso' => '2026-10-10']);

        $this->assertSame(2, $callCount, 'provider error must not be cached');
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => strtoupper($slug),
            'slug' => $slug,
            'is_active' => true,
        ]);
    }
}
