<?php

namespace Tests\Unit\Conversation;

use App\Models\ConversationState;
use App\Models\Tenant;
use App\Modules\Conversation\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationServicePersistDurableTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug = 'tenant-pd'): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Tenant PD',
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    public function test_persist_durable_writes_non_null_entities_and_state_update(): void
    {
        $tenant = $this->makeTenant();
        $service = app(ConversationService::class);
        $conversation = $service->findOrCreateActiveConversation($tenant, '+628111000001');

        $service->persistDurable(
            $conversation,
            $tenant,
            [
                'customer_name' => 'Aris',
                'event_type' => 'wedding',
                'event_date_iso' => '2026-06-05',
                'location' => 'Jakarta',
                'budget_min' => '50000000',
                'budget_max' => '75000000',
                'package_interest' => 'GOLD',
            ],
            [
                'current_stage' => 'qualifying',
                'active_goal' => 'qualification',
                'pending_action' => ['action' => 'send_pricelist_file', 'reason' => 'missing_event_date', 'captured_at' => '2026-05-08T12:00:00Z'],
            ],
        );

        $state = ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $this->assertSame('Aris', $state->customer_name);
        $this->assertSame('wedding', $state->event_type);
        $this->assertSame('2026-06-05', $state->event_date_iso);
        $this->assertSame('Jakarta', $state->location);
        $this->assertSame('50000000.00', (string) $state->budget_min);
        $this->assertSame('75000000.00', (string) $state->budget_max);
        $this->assertSame('GOLD', $state->package_interest);
        $this->assertSame('qualifying', $state->current_stage);
        $this->assertSame('qualification', $state->active_goal);
        $this->assertIsArray($state->pending_action);
        $this->assertSame('send_pricelist_file', $state->pending_action['action']);
    }

    public function test_persist_durable_skips_null_and_empty_values(): void
    {
        $tenant = $this->makeTenant('tenant-pd-2');
        $service = app(ConversationService::class);
        $conversation = $service->findOrCreateActiveConversation($tenant, '+628111000002');

        $service->persistDurable($conversation, $tenant, [
            'customer_name' => 'Budi',
            'event_type' => 'wedding',
        ]);

        $service->persistDurable($conversation, $tenant, [
            'customer_name' => null,
            'event_type' => '',
            'location' => 'Bandung',
        ]);

        $state = ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $this->assertSame('Budi', $state->customer_name, 'null should not overwrite');
        $this->assertSame('wedding', $state->event_type, 'empty string should not overwrite');
        $this->assertSame('Bandung', $state->location);
    }

    public function test_persist_durable_clears_pending_action_when_empty_array_or_null(): void
    {
        $tenant = $this->makeTenant('tenant-pd-3');
        $service = app(ConversationService::class);
        $conversation = $service->findOrCreateActiveConversation($tenant, '+628111000003');

        $service->persistDurable($conversation, $tenant, [], [
            'pending_action' => ['action' => 'send_file', 'reason' => 'missing_name', 'captured_at' => '2026-05-08T12:00:00Z'],
        ]);

        $state = ConversationState::query()->where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertNotNull($state->pending_action);

        $service->persistDurable($conversation, $tenant, [], ['pending_action' => null]);
        $state->refresh();
        $this->assertNull($state->pending_action);
    }

    public function test_persist_durable_rejects_foreign_tenant(): void
    {
        $tenantA = $this->makeTenant('tenant-pd-a');
        $tenantB = $this->makeTenant('tenant-pd-b');
        $service = app(ConversationService::class);
        $conversation = $service->findOrCreateActiveConversation($tenantA, '+628111000004');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->persistDurable($conversation, $tenantB, ['customer_name' => 'Hack']);
    }
}
