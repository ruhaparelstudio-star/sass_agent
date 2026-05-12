<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Modules\CoreEngine\Services\TurnPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carry-forward regression: durable entities and pending_action persist across turns,
 * and the orchestrator-style context surface (buildPersistedEntityContext via $context['entities'])
 * exposes every durable column so the next turn's interpreter and composer have full visibility.
 */
class ConversationStateCarryForwardTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_durable_columns_carry_forward_across_turns(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmSequence([
            // Turn 1: name only
            '{"intent":"provide_name","confidence":0.93,"entities":{"customer_name":"Aris"}}',
            // Turn 2: event_type + location
            '{"intent":"provide_event_type","confidence":0.9,"entities":{"event_type":"wedding","location":"Bandung"}}',
            // Turn 3: budget range
            '{"intent":"provide_budget","confidence":0.88,"entities":{"budget_min":40000000,"budget_max":50000000}}',
            // Turn 4: user only repeats raw date — must NOT erase previously known entities
            '{"intent":"provide_date","confidence":0.91,"entities":{"event_date":"2026-06-05","event_date_iso":"2026-06-05"}}',
        ]);

        $pipeline = app(TurnPipelineService::class);

        $pipeline->handle($tenant, $conversation, 'saya aris', 'extract intent');
        $state = $this->refreshState($conversation);
        $this->assertSame('Aris', $state->customer_name, 'Turn 1: customer_name persisted');

        $pipeline->handle($tenant, $conversation, 'wedding di Bandung', 'extract intent', [
            // Simulate orchestrator-built context: previous durable entities surfaced for the next turn.
            'entities' => $this->buildEntitiesFromState($state),
        ]);
        $state = $this->refreshState($conversation);
        $this->assertSame('Aris', $state->customer_name, 'Turn 2: customer_name preserved');
        $this->assertSame('wedding', $state->event_type, 'Turn 2: event_type stored');
        $this->assertSame('wedding', $state->service_interest, 'Turn 2: service_interest mirrored from event_type');
        $this->assertSame('bandung', mb_strtolower((string) $state->location), 'Turn 2: location stored');

        $pipeline->handle($tenant, $conversation, 'budgetnya 40-50jt', 'extract intent', [
            'entities' => $this->buildEntitiesFromState($state),
        ]);
        $state = $this->refreshState($conversation);
        $this->assertSame('Aris', $state->customer_name, 'Turn 3: customer_name preserved');
        $this->assertSame('wedding', $state->event_type, 'Turn 3: event_type preserved');
        $this->assertSame('bandung', mb_strtolower((string) $state->location), 'Turn 3: location preserved');
        $this->assertSame('40000000.00', (string) $state->budget_min, 'Turn 3: budget_min stored');
        $this->assertSame('50000000.00', (string) $state->budget_max, 'Turn 3: budget_max stored');

        $pipeline->handle($tenant, $conversation, '5 juni 2026', 'extract intent', [
            'entities' => $this->buildEntitiesFromState($state),
        ]);
        $state = $this->refreshState($conversation);
        $this->assertSame('Aris', $state->customer_name, 'Turn 4: customer_name preserved');
        $this->assertSame('wedding', $state->event_type, 'Turn 4: event_type preserved');
        $this->assertSame('bandung', mb_strtolower((string) $state->location), 'Turn 4: location preserved');
        $this->assertSame('40000000.00', (string) $state->budget_min, 'Turn 4: budget_min preserved');
        $this->assertSame('2026-06-05', $state->event_date_iso, 'Turn 4: event_date_iso stored');
    }

    public function test_pricelist_blocked_persists_structured_pending_action_for_resume(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmSequence([
            '{"intent":"ask_pricelist","confidence":0.95,"entities":{}}',
            '{"intent":"provide_name","confidence":0.94,"entities":{"customer_name":"Aris"}}',
        ]);

        $pipeline = app(TurnPipelineService::class);

        $pipeline->handle($tenant, $conversation, 'minta pricelist', 'extract intent');
        $state = $this->refreshState($conversation);
        $this->assertIsArray($state->pending_action, 'pending_action persisted as structured array');
        $this->assertSame('send_file', $state->pending_action['action'] ?? null);
        $this->assertSame('missing_name', $state->pending_action['reason'] ?? null);
        $this->assertNotEmpty($state->pending_action['captured_at'] ?? null);

        $pipeline->handle($tenant, $conversation, 'saya aris', 'extract intent', [
            'entities' => $this->buildEntitiesFromState($state),
        ]);
        $state = $this->refreshState($conversation);
        // Name captured on resume — durable column persisted regardless of which stage label is set.
        $this->assertSame('Aris', $state->customer_name, 'name captured on resume');
    }

    public function test_handoff_preserves_full_qualification_state_after_resume(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->update([
                'customer_name' => 'Budi',
                'event_type' => 'wedding',
                'service_interest' => 'wedding',
                'event_date_iso' => '2026-06-05',
                'event_date_raw' => '5 juni 2026',
                'location' => 'Bandung',
                'budget_min' => 40000000,
                'budget_max' => 50000000,
                'agent_mode' => 'handoff',
            ]);

        // Simulate admin resuming AI: only flips agent_mode; durable qualification must remain.
        $handoff = \App\Models\Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'request_handoff',
            'priority' => 'high',
            'status' => 'resolved',
            'note' => null,
        ]);
        app(\App\Modules\AdminUi\Services\TenantHandoffResolutionService::class)
            ->resumeAi($tenant->id, $conversation->id, $handoff->id);

        $state = $this->refreshState($conversation);
        $this->assertSame('assistant', $state->agent_mode);
        $this->assertSame('Budi', $state->customer_name);
        $this->assertSame('wedding', $state->event_type);
        $this->assertSame('Bandung', $state->location);
        $this->assertSame('5 juni 2026', $state->event_date_raw);
        $this->assertSame('40000000.00', (string) $state->budget_min);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildEntitiesFromState(ConversationState $state): array
    {
        return [
            'customer_name' => $state->customer_name,
            'name' => $state->customer_name,
            'event_type' => $state->event_type,
            'service_interest' => $state->service_interest,
            'package_interest' => $state->package_interest,
            'package_query' => $state->package_interest,
            'resolved_package_name' => $state->selected_package,
            'selected_package' => $state->selected_package,
            'event_date_iso' => $state->event_date_iso,
            'event_date_raw' => $state->event_date_raw,
            'location' => $state->location,
            'budget_min' => $state->budget_min !== null ? (float) $state->budget_min : null,
            'budget_max' => $state->budget_max !== null ? (float) $state->budget_max : null,
            'pending_action' => $state->pending_action,
        ];
    }

    private function refreshState(Conversation $conversation): ConversationState
    {
        return ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();
    }

    private function createConversation(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Carry Forward Tenant',
            'slug' => 'carry-forward-tenant',
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+628111000777',
            'status' => 'open',
        ]);

        ConversationState::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'current_stage' => 'new',
            'active_goal' => null,
            'agent_mode' => 'assistant',
            'memory_mode' => 'short',
            'retention_policy' => 'standard',
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $conversation->customer_phone,
            'full_name' => null,
        ]);

        return [$tenant, $conversation];
    }

    /**
     * @param  list<string>  $jsonResponses
     */
    private function bindLlmSequence(array $jsonResponses): void
    {
        $this->app->instance(LlmClientContract::class, new class($jsonResponses) implements LlmClientContract {
            /** @var list<string> */
            private array $responses;

            /** @param list<string> $responses */
            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                $content = array_shift($this->responses) ?? '{"intent":"unknown","confidence":0.0,"entities":{}}';

                return new LlmResponse(
                    content: $content,
                    model: 'fake-model',
                    totalTokens: 10,
                    raw: ['ok' => true],
                );
            }
        });
    }
}
