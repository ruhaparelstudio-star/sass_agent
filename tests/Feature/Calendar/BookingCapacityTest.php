<?php

namespace Tests\Feature\Calendar;

use App\Models\BookingDateCapacity;
use App\Models\CalendarConnection;
use App\Models\CalendarSetting;
use App\Models\Tenant;
use App\Modules\Calendar\Services\CalendarAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name'      => strtoupper($slug),
            'slug'      => $slug,
            'is_active' => true,
        ]);
    }

    private function connectTenant(Tenant $tenant, int $maxEvents = 1): CalendarSetting
    {
        $tenant->calendarConnections()->create([
            'provider'   => 'google',
            'status'     => 'connected',
            'is_enabled' => true,
            'config'     => ['access_token' => 'tok', 'calendar_id' => 'primary'],
        ]);

        return $tenant->calendarSettings()->create([
            'timezone'     => 'Asia/Jakarta',
            'slot_minutes' => 60,
            'is_active'    => true,
            'rules'        => ['google_calendar' => ['max_events_per_date' => $maxEvents]],
        ]);
    }

    public function test_available_when_no_bookings_recorded(): void
    {
        $tenant = $this->createTenant('cap-a');
        $this->connectTenant($tenant, maxEvents: 2);

        $result = app(CalendarAvailabilityService::class)->check($tenant, [
            'event_date_iso' => '2026-08-01',
        ]);

        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['available']);
        $this->assertTrue($result['checked']);
        $this->assertSame('booking_capacity', $result['source']);
        $this->assertSame(0, $result['meta']['used_count']);
        $this->assertSame(2, $result['meta']['max_events']);
    }

    public function test_available_below_capacity(): void
    {
        $tenant = $this->createTenant('cap-b');
        $this->connectTenant($tenant, maxEvents: 3);

        BookingDateCapacity::query()->create([
            'tenant_id'    => $tenant->id,
            'booking_date' => '2026-08-10',
            'used_count'   => 2,
        ]);

        $result = app(CalendarAvailabilityService::class)->check($tenant, [
            'event_date_iso' => '2026-08-10',
        ]);

        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['meta']['used_count']);
        $this->assertSame(3, $result['meta']['max_events']);
    }

    public function test_unavailable_at_capacity(): void
    {
        $tenant = $this->createTenant('cap-c');
        $this->connectTenant($tenant, maxEvents: 2);

        BookingDateCapacity::query()->create([
            'tenant_id'    => $tenant->id,
            'booking_date' => '2026-08-15',
            'used_count'   => 2,
        ]);

        $result = app(CalendarAvailabilityService::class)->check($tenant, [
            'event_date_iso' => '2026-08-15',
        ]);

        $this->assertSame('unavailable', $result['status']);
        $this->assertFalse($result['available']);
        $this->assertTrue($result['checked']);
        $this->assertSame('date_at_capacity', $result['reason']);
        $this->assertSame(2, $result['meta']['used_count']);
    }

    public function test_google_calendar_events_do_not_block_date(): void
    {
        $tenant = $this->createTenant('cap-d');
        $this->connectTenant($tenant, maxEvents: 3);

        // No entries in booking_date_capacity — Google Calendar events are irrelevant.
        $result = app(CalendarAvailabilityService::class)->check($tenant, [
            'event_date_iso' => '2026-09-01',
        ]);

        $this->assertTrue($result['available']);
        $this->assertSame(0, $result['meta']['used_count']);
    }

    public function test_increment_for_tenant_date_tracks_capacity(): void
    {
        $tenant = $this->createTenant('cap-e');

        BookingDateCapacity::incrementForTenantDate($tenant->id, '2026-10-05');
        $this->assertSame(1, BookingDateCapacity::getUsedCount($tenant->id, '2026-10-05'));

        BookingDateCapacity::incrementForTenantDate($tenant->id, '2026-10-05');
        $this->assertSame(2, BookingDateCapacity::getUsedCount($tenant->id, '2026-10-05'));

        // Different date unaffected.
        $this->assertSame(0, BookingDateCapacity::getUsedCount($tenant->id, '2026-10-06'));
    }

    public function test_tenant_isolation_in_capacity(): void
    {
        $tenantA = $this->createTenant('cap-f');
        $tenantB = $this->createTenant('cap-g');

        BookingDateCapacity::incrementForTenantDate($tenantA->id, '2026-11-01');
        BookingDateCapacity::incrementForTenantDate($tenantA->id, '2026-11-01');

        // Tenant B has no bookings for same date.
        $this->assertSame(0, BookingDateCapacity::getUsedCount($tenantB->id, '2026-11-01'));

        $this->connectTenant($tenantB, maxEvents: 1);
        $result = app(CalendarAvailabilityService::class)->check($tenantB, [
            'event_date_iso' => '2026-11-01',
        ]);

        $this->assertTrue($result['available']);
    }

    public function test_no_date_in_request_returns_checked_available(): void
    {
        $tenant = $this->createTenant('cap-h');
        $this->connectTenant($tenant);

        $result = app(CalendarAvailabilityService::class)->check($tenant, [
            'message_hint' => 'I want to book soon',
        ]);

        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['checked']);
        $this->assertTrue($result['available']);
    }

    public function test_disabled_connection_returns_blocked_regardless_of_capacity(): void
    {
        $tenant = $this->createTenant('cap-i');

        $tenant->calendarConnections()->create([
            'provider'   => 'google',
            'status'     => 'disconnected',
            'is_enabled' => false,
            'config'     => [],
        ]);
        $tenant->calendarSettings()->create([
            'timezone'     => 'Asia/Jakarta',
            'slot_minutes' => 60,
            'is_active'    => true,
            'rules'        => ['google_calendar' => ['max_events_per_date' => 5]],
        ]);

        // Even with plenty of capacity, disabled connection → blocked.
        $result = app(CalendarAvailabilityService::class)->check($tenant, [
            'event_date_iso' => '2026-12-01',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['available']);
        $this->assertSame('calendar_integration_disabled', $result['reason']);
    }
}
