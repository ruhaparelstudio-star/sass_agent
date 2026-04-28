<?php

namespace Tests\Unit\WhatsApp;

use App\Enums\WaOutboundMessageStatus;
use App\Jobs\DispatchWaOutboundMessageJob;
use App\Models\Tenant;
use App\Models\WaAccount;
use App\Models\WaOutboundMessage;
use App\Modules\WhatsApp\Contracts\WaGatewayClient;
use App\Modules\WhatsApp\Services\WaOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaOutboundFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_transition_rules_are_enforced(): void
    {
        $service = app(WaOutboundService::class);

        $this->assertTrue($service->canTransitionOutboundStatus(WaOutboundMessageStatus::Pending, WaOutboundMessageStatus::Sent));
        $this->assertTrue($service->canTransitionOutboundStatus(WaOutboundMessageStatus::Pending, WaOutboundMessageStatus::Failed));
        $this->assertTrue($service->canTransitionOutboundStatus(WaOutboundMessageStatus::Pending, WaOutboundMessageStatus::Cancelled));
        $this->assertFalse($service->canTransitionOutboundStatus(WaOutboundMessageStatus::Sent, WaOutboundMessageStatus::Pending));
    }

    public function test_job_exits_when_message_not_pending(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+6281',
            'last_payload' => ['event' => 'connected'],
        ]);

        $message = WaOutboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_message_id' => 'out-001',
            'to' => '+6282',
            'message_type' => 'text',
            'status' => WaOutboundMessageStatus::Sent,
            'payload' => ['text' => 'hello'],
            'queued_at' => now(),
        ]);

        $this->app->bind(WaGatewayClient::class, fn () => new class implements WaGatewayClient
        {
            public function sendOutbound(array $payload): array
            {
                return ['provider_message_id' => 'provider-001'];
            }
        });

        (new DispatchWaOutboundMessageJob($tenant->id, $message->id))->handle(
            app(WaGatewayClient::class),
            app(WaOutboundService::class),
        );

        $this->assertDatabaseCount('wa_message_delivery_logs', 0);
    }

    public function test_job_marks_sent_and_writes_delivery_log_on_success(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+6281',
            'last_payload' => ['event' => 'connected'],
        ]);

        $message = WaOutboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_message_id' => 'out-001',
            'to' => '+6282',
            'message_type' => 'text',
            'status' => WaOutboundMessageStatus::Pending,
            'payload' => ['text' => 'hello'],
            'queued_at' => now(),
        ]);

        $this->app->bind(WaGatewayClient::class, fn () => new class implements WaGatewayClient
        {
            public function sendOutbound(array $payload): array
            {
                return [
                    'ok' => true,
                    'provider_message_id' => 'provider-001',
                ];
            }
        });

        (new DispatchWaOutboundMessageJob($tenant->id, $message->id))->handle(
            app(WaGatewayClient::class),
            app(WaOutboundService::class),
        );

        $this->assertDatabaseHas('wa_outbound_messages', [
            'id' => $message->id,
            'status' => 'sent',
            'provider_message_id' => 'provider-001',
        ]);
        $this->assertDatabaseHas('wa_message_delivery_logs', [
            'tenant_id' => $tenant->id,
            'wa_outbound_message_id' => $message->id,
            'attempt_number' => 1,
            'status' => 'sent',
        ]);
    }

    public function test_job_marks_failed_and_writes_delivery_log_on_exception(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+6281',
            'last_payload' => ['event' => 'connected'],
        ]);

        $message = WaOutboundMessage::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_message_id' => 'out-001',
            'to' => '+6282',
            'message_type' => 'text',
            'status' => WaOutboundMessageStatus::Pending,
            'payload' => ['text' => 'hello'],
            'queued_at' => now(),
        ]);

        $this->app->bind(WaGatewayClient::class, fn () => new class implements WaGatewayClient
        {
            public function sendOutbound(array $payload): array
            {
                throw new \RuntimeException('Gateway failed');
            }
        });

        (new DispatchWaOutboundMessageJob($tenant->id, $message->id))->handle(
            app(WaGatewayClient::class),
            app(WaOutboundService::class),
        );

        $this->assertDatabaseHas('wa_outbound_messages', [
            'id' => $message->id,
            'status' => 'failed',
            'failure_reason' => 'Gateway failed',
        ]);
        $this->assertDatabaseHas('wa_message_delivery_logs', [
            'tenant_id' => $tenant->id,
            'wa_outbound_message_id' => $message->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'error_message' => 'Gateway failed',
        ]);
    }
}
