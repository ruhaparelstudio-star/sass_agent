<?php

namespace Tests\Unit\WhatsApp;

use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Modules\WhatsApp\Services\WaInboundTurnOrchestratorService;
use App\Models\DecisionTrace;
use App\Models\Tenant;
use App\Models\WaAccount;
use App\Models\WaInboundMessage;
use App\Models\WaSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame('ask_pricelist', $decision['intent'] ?? null);
        $this->assertArrayHasKey('confidence', $decision);
        $this->assertArrayHasKey('decision', $decision);
        $this->assertArrayHasKey('allowed_actions', $decision);
        $this->assertArrayHasKey('blocked_actions', $decision);
        $this->assertArrayHasKey('handoff_required', $decision);
        $this->assertArrayHasKey('notification_required', $decision);
        $this->assertArrayHasKey('grounding_refs', $decision);
        $this->assertArrayHasKey('reply_strategy', $decision);

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

