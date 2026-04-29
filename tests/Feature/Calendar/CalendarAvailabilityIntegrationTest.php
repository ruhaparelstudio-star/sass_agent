<?php

namespace Tests\Feature\Calendar;

use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Modules\Calendar\Services\CalendarAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarAvailabilityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_and_error_fallback_never_produce_grounded_availability_context(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+6281000000001',
            'status' => 'open',
        ]);

        ConversationState::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'current_stage' => 'new',
            'active_goal' => 'booking',
            'agent_mode' => 'assistant',
            'memory_mode' => 'short',
            'retention_policy' => 'standard',
        ]);

        $tenant->calendarConnections()->create([
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

        $calendar = app(CalendarAvailabilityService::class)->check($tenant, ['event_date_iso' => '2026-06-01']);

        $grounding = [
            'calendar' => [
                'is_grounded' => $calendar['checked'] === true && $calendar['available'] === true,
                'reason' => $calendar['reason'],
                'source' => $calendar['source'],
            ],
        ];

        $this->assertFalse($grounding['calendar']['is_grounded']);
        $this->assertSame('calendar_integration_disabled', $grounding['calendar']['reason']);

        $this->assertDatabaseHas('calendar_availability_checks', [
            'tenant_id' => $tenant->id,
            'status' => 'blocked',
            'reason' => 'calendar_integration_disabled',
        ]);
    }
}
