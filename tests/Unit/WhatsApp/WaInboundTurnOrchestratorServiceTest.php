<?php

namespace Tests\Unit\WhatsApp;

use App\Jobs\DispatchNotificationJob;
use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\ConversationState;
use App\Models\Handoff;
use App\Models\KnowledgeVersion;
use App\Models\LeadProfile;
use App\Models\Notification;
use App\Models\ServiceCatalog;
use App\Models\TenantAsset;
use App\Models\WaOutboundMessage;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Modules\Validation\Contracts\PolicyValidator;
use App\Modules\WhatsApp\Services\WaInboundTurnOrchestratorService;
use App\Models\DecisionTrace;
use App\Models\Tenant;
use App\Models\WaAccount;
use App\Models\WaInboundMessage;
use App\Models\WaSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WaInboundTurnOrchestratorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_turn_orchestrator_runs_pipeline_and_persists_trace_contract(): void
    {
        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.91,"entities":{"package_query":"gold"}}');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+628111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $inbound = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => now(),
            'payload' => [
                'message' => [
                    'conversation' => 'boleh kirim pricelist paket gold?',
                ],
            ],
            'meta' => ['source' => 'test'],
        ]);

        app(WaInboundTurnOrchestratorService::class)->process($tenant, $inbound);

        $trace = DecisionTrace::query()
            ->where('tenant_id', $tenant->id)
            ->where('trace_key', 'inbound_turn')
            ->firstOrFail();

        $decision = $trace->meta['decision'] ?? [];
        $inboundMessage = \App\Models\Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'inbound')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($inboundMessage->id, $trace->message_id);
        $this->assertSame($decision, $trace->decision_json);
        $this->assertSame($decision['blocked_actions'] ?? [], $trace->blocked_actions_json);
        $this->assertSame($decision['grounding_refs'] ?? [], $trace->grounding_refs_json);
        $this->assertSame($decision['trace']['validator_order'] ?? [], $trace->validators_json['order'] ?? []);
        $this->assertSame($decision['trace']['fallback_reason'] ?? null, $trace->validators_json['fallback_reason'] ?? null);
        $this->assertNotNull($trace->final_reply);
        $this->assertSame('ask_pricelist', $decision['intent'] ?? null);
        $this->assertArrayHasKey('confidence', $decision);
        $this->assertArrayHasKey('decision', $decision);
        $this->assertArrayHasKey('allowed_actions', $decision);
        $this->assertArrayHasKey('blocked_actions', $decision);
        $this->assertArrayHasKey('handoff_required', $decision);
        $this->assertArrayHasKey('notification_required', $decision);
        $this->assertArrayHasKey('grounding_refs', $decision);
        $this->assertArrayHasKey('reply_strategy', $decision);
        $this->assertSame([
            'receive_inbound',
            'deduplicate',
            'load_tenant_and_wa_account',
            'check_tenant_status_and_plan',
            'load_conversation_and_state',
            'interpret_and_extract_entities',
            'retrieve_knowledge',
            'build_and_validate_decision',
            'compose_response',
            'send_reply_action',
            'store_trace',
        ], $trace->meta['pipeline_steps'] ?? []);

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'direction' => 'inbound',
            'content' => 'boleh kirim pricelist paket gold?',
        ]);

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
            'content' => 'Sebelum kita lanjut, aku boleh tahu nama kakak?',
        ]);

        $outbound = \App\Models\Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($trace->id, $outbound->decision_trace_id);
        $this->assertIsArray($outbound->grounding_refs);
        $this->assertDatabaseHas('lead_profiles', [
            'tenant_id' => $tenant->id,
            'customer_phone' => '628111',
        ]);

        $context = ConversationContext::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $inboundMessage->conversation_id)
            ->latest('id')
            ->first();
        $this->assertNotNull($context);
        $this->assertNotNull($context?->summary);
        $this->assertNotNull($context?->reason);
        $this->assertSame('pricing', $context?->recommended_next_action);
    }

    public function test_inbound_turn_orchestrator_resumes_pricelist_send_after_name_is_provided(): void
    {
        $this->bindLlmJsonSequence([
            '{"intent":"ask_pricelist","confidence":0.91,"entities":{"package_query":"gold"}}',
            '{"intent":"provide_name","confidence":0.95,"entities":{"customer_name":"Aris Egi Saputra"}}',
            '{"intent":"provide_event_type","confidence":0.95,"entities":{"event_type":"wedding"}}',
        ]);
        $this->app->bind(PolicyValidator::class, fn () => new class implements PolicyValidator
        {
            public function validate(array $candidate, array $context): ?string
            {
                return null;
            }
        });

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $asset = TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Pricelist April',
            'original_filename' => 'pricelist-april.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/1/pricelist-april.pdf',
            'uploaded_by_user_id' => null,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        KnowledgeVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'v1',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_until' => now()->addDay(),
        ]);

        ServiceCatalog::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'wedding',
            'name' => 'Wedding Catalog',
            'description' => 'Catalog for test grounding.',
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+628111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $inboundOne = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => now(),
            'payload' => [
                'message' => [
                    'conversation' => 'boleh minta pricelistnya',
                ],
            ],
            'meta' => ['source' => 'test'],
        ]);

        $inboundTwo = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-002',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => now()->addSecond(),
            'payload' => [
                'message' => [
                    'conversation' => 'nama saya aris egi saputra',
                ],
            ],
            'meta' => ['source' => 'test'],
        ]);

        $inboundThree = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-003',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => now()->addSeconds(2),
            'payload' => [
                'message' => [
                    'conversation' => 'untuk wedding ka',
                ],
            ],
            'meta' => ['source' => 'test'],
        ]);

        $service = app(WaInboundTurnOrchestratorService::class);
        $service->process($tenant, $inboundOne);
        $service->process($tenant, $inboundTwo);
        $service->process($tenant, $inboundThree);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'send_file',
            'status' => 'executed',
        ]);

        $fileOutbound = WaOutboundMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('message_type', 'file')
            ->latest('id')
            ->first();

        $this->assertNotNull($fileOutbound);

        $outboundMessages = \App\Models\Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->orderBy('id')
            ->get(['content'])
            ->pluck('content')
            ->all();

        $this->assertCount(3, $outboundMessages);
        $this->assertSame('Sebelum kita lanjut, aku boleh tahu nama kakak?', $outboundMessages[0]);
        $this->assertSame('Siap kak, kakak lagi cari layanan untuk acara apa ya?', $outboundMessages[1]);

        $outboundText = \App\Models\Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Ini pricelist terbaru kami ya kak, kalau ada yang kurang jelas boleh langsung ditanyakan.', $outboundText->content);

        $this->assertDatabaseHas('conversation_contexts', [
            'tenant_id' => $tenant->id,
            'reason' => 'send_file:missing_event_type',
        ]);

        $this->assertDatabaseHas('lead_profiles', [
            'tenant_id' => $tenant->id,
            'customer_phone' => '628111',
            'full_name' => 'Aris Egi Saputra',
        ]);
    }

    public function test_inbound_turn_orchestrator_is_idempotent_per_inbound_message(): void
    {
        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.91,"entities":{"package_query":"gold"}}');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+628111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $inbound = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => now(),
            'payload' => [
                'message' => [
                    'conversation' => 'test dedupe',
                ],
            ],
            'meta' => ['source' => 'test'],
        ]);

        $service = app(WaInboundTurnOrchestratorService::class);
        $service->process($tenant, $inbound);
        $service->process($tenant, $inbound);

        $this->assertSame(1, DecisionTrace::query()
            ->where('tenant_id', $tenant->id)
            ->where('trace_key', 'inbound_turn')
            ->count());
    }

    public function test_inbound_turn_orchestrator_creates_handoff_and_notification_when_handoff_required(): void
    {
        Queue::fake();
        $this->bindLlmJson('{"intent":"complaint","confidence":0.95,"entities":{}}');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+628111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $inbound = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-complaint-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => now(),
            'payload' => [
                'message' => [
                    'conversation' => 'Saya komplain dan mau bicara admin.',
                ],
            ],
            'meta' => ['source' => 'test'],
        ]);

        app(WaInboundTurnOrchestratorService::class)->process($tenant, $inbound);

        $handoff = Handoff::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $notification = Notification::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame('complaint_detected', $handoff->reason_code);
        $this->assertSame('pending', $handoff->status);
        $this->assertSame('high', $handoff->context['priority'] ?? null);
        $this->assertSame('inbound_turn_pipeline', $handoff->context['source'] ?? null);
        $this->assertSame($inbound->id, $handoff->context['inbound_message_id'] ?? null);

        $this->assertSame($handoff->id, $notification->handoff_id);
        $this->assertSame('handoff_created', $notification->type);
        $this->assertSame('queued', $notification->status);
        $this->assertSame('complaint_detected', $notification->payload['reason_code'] ?? null);
        $this->assertSame('high', $notification->payload['context']['priority'] ?? null);

        Queue::assertPushed(DispatchNotificationJob::class, function (DispatchNotificationJob $job) use ($tenant, $notification): bool {
            return $job->tenantId === $tenant->id && $job->notificationId === $notification->id;
        });
    }

    public function test_inbound_turn_orchestrator_never_sends_internal_placeholder_reply_to_user(): void
    {
        $this->bindLlmJson('{"intent":"first_contact","confidence":0.9,"entities":{"customer_name":"Aris"}}');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+628111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $inbound = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => now(),
            'payload' => [
                'message' => [
                    'conversation' => 'Saya aris',
                ],
            ],
            'meta' => ['source' => 'test'],
        ]);

        app(WaInboundTurnOrchestratorService::class)->process($tenant, $inbound);

        $outbound = \App\Models\Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->firstOrFail();

        $this->assertStringNotContainsString('allowed candidate', mb_strtolower($outbound->content));

        $trace = DecisionTrace::query()
            ->where('tenant_id', $tenant->id)
            ->where('trace_key', 'inbound_turn')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($outbound->content, $trace->final_reply);
    }

    public function test_inbound_turn_orchestrator_does_not_send_auto_reply_when_state_is_handoff_mode(): void
    {
        Queue::fake();
        $this->bindLlmJson('{"intent":"unknown","confidence":0.1,"entities":{}}');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+628111',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'customer_phone' => '628111',
            'status' => 'open',
            'current_stage' => 'qualification',
            'active_goal' => 'pricing',
            'agent_mode' => 'handoff',
            'memory_mode' => 'short',
        ]);

        ConversationState::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'current_stage' => 'qualification',
            'active_goal' => 'pricing',
            'agent_mode' => 'handoff',
            'memory_mode' => 'short',
            'retention_policy' => 'standard',
        ]);

        $inbound = WaInboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'wa_session_id' => $session->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-handoff-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => now(),
            'payload' => [
                'message' => [
                    'conversation' => 'Halo admin?',
                ],
            ],
            'meta' => ['source' => 'test'],
        ]);

        app(WaInboundTurnOrchestratorService::class)->process($tenant, $inbound);

        $this->assertDatabaseMissing('messages', [
            'tenant_id' => $tenant->id,
            'direction' => 'outbound',
        ]);

        $this->assertDatabaseHas('handoffs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'mode_handoff',
            'status' => 'pending',
        ]);

        $trace = DecisionTrace::query()
            ->where('tenant_id', $tenant->id)
            ->where('trace_key', 'inbound_turn')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('skipped_mode_no_auto_reply', $trace->meta['reply_dispatch']['reason'] ?? null);
    }

    private function bindLlmJson(string $json): void
    {
        $this->app->bind(LlmClientContract::class, fn () => new class($json) implements LlmClientContract
        {
            public function __construct(private readonly string $json) {}

            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                return new LlmResponse(
                    content: $this->json,
                    model: 'unit-test-model',
                    totalTokens: 42,
                    raw: ['tenant_id' => $tenantId, 'message' => $userMessage]
                );
            }
        });
    }

    /**
     * @param  list<string>  $jsonResponses
     */
    private function bindLlmJsonSequence(array $jsonResponses): void
    {
        $this->app->instance(LlmClientContract::class, new class($jsonResponses) implements LlmClientContract
        {
            /**
             * @var list<string>
             */
            private array $responses;

            /**
             * @param  list<string>  $responses
             */
            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                $content = array_shift($this->responses) ?? '{"intent":"unknown","confidence":0.0,"entities":{}}';

                return new LlmResponse(
                    content: $content,
                    model: 'unit-test-model',
                    totalTokens: 42,
                    raw: ['tenant_id' => $tenantId, 'message' => $userMessage]
                );
            }
        });
    }
}
