<?php

namespace Tests\Unit\WhatsApp;

use App\Jobs\DispatchNotificationJob;
use App\Models\Handoff;
use App\Models\Notification;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
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
            'content' => 'Minta nama lengkap pelanggan terlebih dahulu sebelum melanjutkan.',
        ]);

        $outbound = \App\Models\Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($trace->id, $outbound->decision_trace_id);
        $this->assertIsArray($outbound->grounding_refs);
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
}
