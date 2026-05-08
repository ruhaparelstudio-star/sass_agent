<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Handoff;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Modules\AdminUi\Services\TenantHandoffResolutionService;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Modules\Calendar\Contracts\CalendarAvailabilityProvider;
use App\Modules\CoreEngine\Services\TurnPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationGoalDrivenPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_persist_durable_writes_state_after_each_turn(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlm('{"intent":"provide_name","confidence":0.91,"entities":{"customer_name":"Aris"}}');

        app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'saya aris',
            'extract intent'
        );

        $state = ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();
        $this->assertSame('Aris', $state->customer_name);
        $this->assertSame('qualification', $state->active_goal);
    }

    public function test_pricelist_blocked_writes_pending_action_json(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlm('{"intent":"ask_pricelist","confidence":0.95,"entities":{}}');

        app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'minta pricelist',
            'extract intent'
        );

        $state = ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $this->assertIsArray($state->pending_action, 'pending_action must be a JSON array');
        $this->assertSame('send_file', $state->pending_action['action']);
        $this->assertSame('missing_name', $state->pending_action['reason']);
        $this->assertNotEmpty($state->pending_action['captured_at']);
    }

    public function test_resume_ai_preserves_active_goal_event_date_and_customer_name(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->update([
                'agent_mode' => 'handoff',
                'active_goal' => 'booking',
                'event_date_iso' => '2026-06-05',
                'customer_name' => 'Budi',
                'event_type' => 'wedding',
            ]);

        $handoff = Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'request_handoff',
            'priority' => 'high',
            'status' => 'resolved',
            'note' => null,
        ]);

        app(TenantHandoffResolutionService::class)
            ->resumeAi($tenant->id, $conversation->id, $handoff->id);

        $state = ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $this->assertSame('assistant', $state->agent_mode);
        $this->assertSame('booking', $state->active_goal, 'active_goal must be preserved');
        $this->assertSame('2026-06-05', $state->event_date_iso, 'event_date_iso must be preserved');
        $this->assertSame('Budi', $state->customer_name, 'customer_name must be preserved');
        $this->assertSame('wedding', $state->event_type);
    }

    public function test_calendar_provider_error_triggers_handoff_and_no_booking_link_dispatched(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        // Seed entities so booking has full info except availability.
        ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->update([
                'active_goal' => 'booking',
                'customer_name' => 'Citra',
                'event_type' => 'wedding',
                'event_date_iso' => '2026-08-15',
                'package_interest' => 'GOLD',
            ]);

        LeadProfile::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', $conversation->customer_phone)
            ->update(['full_name' => 'Citra']);

        $this->bindLlm('{"intent":"booking_intent","confidence":0.95,"entities":{"package_query":"gold"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'mau booking ya',
            'extract intent',
            [
                'calendar_check' => [
                    'status' => 'blocked',
                    'checked' => false,
                    'available' => false,
                    'reason' => 'calendar_provider_error',
                    'source' => 'error_fallback',
                ],
                'grounding' => [
                    'calendar' => [
                        'is_grounded' => false,
                        'reason' => 'calendar_provider_error',
                        'source' => 'error_fallback',
                    ],
                ],
            ]
        );

        $this->assertTrue($result['handoff_required']);
        $this->assertSame('calendar_provider_error', $result['handoff_reason_code']);

        $allowed = $result['action_candidates']['allowed'] ?? [];
        $allowedActions = array_map(fn ($c) => $c['action'] ?? null, $allowed);
        $this->assertNotContains('send_booking_link', $allowedActions, 'booking link must NOT be dispatched on calendar provider error');
    }

    public function test_state_is_source_of_truth_when_llm_omits_known_entities(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        // Pre-populate state with prior known entities.
        ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->update([
                'customer_name' => 'Dani',
                'event_type' => 'wedding',
                'event_date_iso' => '2026-07-07',
            ]);

        // Even if LLM returns null for known entities, state must remain intact.
        $this->bindLlm('{"intent":"provide_preference","confidence":0.7,"entities":{"customer_name":null,"event_type":null,"event_date":null}}');

        app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'mau yang outdoor aja',
            'extract intent',
            [
                'entities' => [
                    'customer_name' => 'Dani',
                    'event_type' => 'wedding',
                    'event_date_iso' => '2026-07-07',
                ],
            ]
        );

        $state = ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $this->assertSame('Dani', $state->customer_name);
        $this->assertSame('wedding', $state->event_type);
        $this->assertSame('2026-07-07', $state->event_date_iso);
    }

    public function test_ask_package_with_catalog_renders_packages_list_in_reply(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlm('{"intent":"ask_package","confidence":0.85,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'ada paket apa aja kak?',
            'extract intent',
            [
                'catalog' => [[
                    'id' => 1,
                    'name' => 'Wedding',
                    'products' => [[
                        'id' => 1,
                        'name' => 'Photo+Video',
                        'packages' => [
                            ['id' => 11, 'name' => 'Silver Wedding', 'items' => [], 'aliases' => []],
                            ['id' => 12, 'name' => 'Gold Wedding', 'items' => [], 'aliases' => []],
                        ],
                    ]],
                ]],
            ]
        );

        $this->assertSame('package_explanation', $result['active_goal']);
        $reply = (string) ($result['response_plan']['message'] ?? '');
        $this->assertStringContainsString('Silver Wedding', $reply);
        $this->assertStringContainsString('Gold Wedding', $reply);

        $allowed = $result['action_candidates']['allowed'] ?? [];
        $this->assertNotEmpty($allowed, 'package_explanation candidate must be allowed when catalog present');
        $this->assertSame('reply_safe_text', $allowed[0]['action']);
    }

    public function test_ask_availability_with_grounded_calendar_confirms_specific_date(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->update(['event_date_iso' => '2026-09-12']);

        $this->bindLlm('{"intent":"ask_availability","confidence":0.92,"entities":{"event_date_iso":"2026-09-12"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'tgl 12 september 2026 ada slot ga?',
            'extract intent',
            [
                'entities' => ['event_date_iso' => '2026-09-12'],
                'calendar_check' => [
                    'status' => 'allowed',
                    'checked' => true,
                    'available' => true,
                    'reason' => null,
                    'source' => 'google_calendar',
                ],
            ]
        );

        $this->assertSame('availability', $result['active_goal']);
        $reply = (string) ($result['response_plan']['message'] ?? '');
        $this->assertStringContainsString('2026-09-12', $reply);
        $this->assertStringContainsString('tersedia', mb_strtolower($reply));
    }

    public function test_ask_faq_uses_grounded_faq_match_answer(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlm('{"intent":"ask_faq","confidence":0.81,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'kalau hujan gimana?',
            'extract intent',
            [
                'grounding' => [
                    'faq_match' => [
                        'is_grounded' => true,
                        'source' => 'knowledge_faq',
                        'answer' => 'Tidak masalah kak, kami tetap shoot dengan setup indoor cadangan.',
                        'question' => 'kalau hujan gimana',
                    ],
                ],
            ]
        );

        $this->assertSame('faq', $result['active_goal']);
        $reply = (string) ($result['response_plan']['message'] ?? '');
        $this->assertStringContainsString('setup indoor cadangan', $reply);
    }

    public function test_objection_price_renders_value_framing_not_generic(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlm('{"intent":"objection_price","confidence":0.83,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'kemahalan kak',
            'extract intent'
        );

        $this->assertSame('objection_handling', $result['active_goal']);
        $reply = (string) ($result['response_plan']['message'] ?? '');
        $this->assertStringContainsString('value paket', $reply);
    }

    public function test_unclear_intent_uses_clarification_template(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlm('{"intent":"unclear_message","confidence":0.3,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'asdfgh???',
            'extract intent'
        );

        $this->assertSame('clarification', $result['active_goal']);
        $reply = (string) ($result['response_plan']['message'] ?? '');
        $this->assertStringContainsString('dijelaskan ulang', mb_strtolower($reply));
    }

    private function createConversation(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Studio Goal',
            'slug' => 'studio-goal',
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+628111777222',
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

    private function bindLlm(string $json): void
    {
        $this->app->bind(LlmClientContract::class, fn () => new class($json) implements LlmClientContract {
            public function __construct(private readonly string $json) {}
            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                return new LlmResponse(
                    content: $this->json,
                    model: 'fake-model',
                    totalTokens: 10,
                    raw: ['ok' => true],
                );
            }
        });
    }
}
