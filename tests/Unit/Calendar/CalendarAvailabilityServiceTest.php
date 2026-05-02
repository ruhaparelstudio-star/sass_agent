<?php

namespace Tests\Unit\Calendar;

use App\Models\Tenant;
use App\Modules\Calendar\Contracts\CalendarAvailabilityProvider;
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

    private function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => strtoupper($slug),
            'slug' => $slug,
            'is_active' => true,
        ]);
    }
}
