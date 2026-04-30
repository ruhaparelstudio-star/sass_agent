<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\DispatchWaOutboundMessageJob;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WaInternalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_secret_is_required_when_configured(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $payload = [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'disconnected',
            'phone' => '+628111111111',
            'payload' => ['event' => 'created'],
        ];

        $this->withToken($token)
            ->postJson('/api/internal/whatsapp/accounts/upsert', $payload)
            ->assertForbidden();

        $this->withToken($token)
            ->withHeader('X-Internal-Secret', 'wrong')
            ->postJson('/api/internal/whatsapp/accounts/upsert', $payload)
            ->assertForbidden();

        $this->withToken($token)
            ->withHeader('X-Internal-Secret', 'wa-internal-secret')
            ->postJson('/api/internal/whatsapp/accounts/upsert', $payload)
            ->assertOk();
    }

    public function test_internal_secret_missing_config_is_blocked_in_non_testing_environment(): void
    {
        Config::set('app.env', 'production');
        Config::set('whatsapp.internal_secret', '');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $payload = [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'disconnected',
            'phone' => '+628111111111',
            'payload' => ['event' => 'created'],
        ];

        $this->withToken($token)
            ->postJson('/api/internal/whatsapp/accounts/upsert', $payload)
            ->assertForbidden();
    }

    public function test_tenant_scope_and_duplicate_protection_are_enforced_for_account_upsert(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenantOne->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantTwo->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-foreign',
            'status' => 'disconnected',
            'phone' => '+628122222222',
            'payload' => ['event' => 'created'],
        ])->assertForbidden();

        $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'disconnected',
            'phone' => '+628133333333',
            'payload' => ['event' => 'created'],
        ])->assertOk();

        $second = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'disconnected',
            'phone' => '+628144444444',
            'payload' => ['event' => 'updated'],
        ])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('wa_accounts', 1);
        $this->assertDatabaseHas('wa_accounts', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+628144444444',
        ]);
    }

    public function test_status_transition_and_payload_contract_are_enforced(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'disconnected',
            'phone' => '+628155555555',
            'payload' => 'bad-payload',
        ])->assertStatus(422);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'disconnected',
            'phone' => '+628155555555',
            'payload' => ['event' => 'created'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connecting',
            'phone' => '+628155555555',
            'payload' => ['event' => 'connecting'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'disconnected',
            'phone' => '+628155555555',
            'payload' => ['event' => 'disconnect'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+628155555555',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connecting',
            'phone' => '+628155555555',
            'payload' => ['event' => 'illegal'],
        ])->assertOk();
    }

    public function test_session_upsert_is_tenant_scoped_and_deduplicated(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+628166666666',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/sessions/upsert', [
            'tenant_id' => $tenant->id,
            'wa_account_provider_ref' => 'acct-001',
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'pending',
            'payload' => ['event' => 'pending'],
        ])->assertOk();

        $second = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/sessions/upsert', [
            'tenant_id' => $tenant->id,
            'wa_account_provider_ref' => 'acct-001',
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'payload' => ['event' => 'active'],
        ])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('wa_sessions', 1);
        $this->assertDatabaseHas('wa_sessions', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
        ]);
    }

    public function test_inbound_message_is_tenant_scoped_deduplicated_and_stored(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenantOne->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+628166666666',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/sessions/upsert', [
            'tenant_id' => $tenantOne->id,
            'wa_account_provider_ref' => 'acct-001',
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'payload' => ['event' => 'active'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            'tenant_id' => $tenantTwo->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-foreign',
            'wa_account_provider_ref' => 'acct-001',
            'wa_session_provider_ref' => 'sess-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => '2026-04-28T10:00:00+00:00',
            'payload' => ['text' => 'hello'],
        ])->assertForbidden();

        $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-001',
            'wa_account_provider_ref' => 'acct-001',
            'wa_session_provider_ref' => 'sess-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => '2026-04-28T10:00:00+00:00',
            'payload' => ['text' => 'hello'],
        ])->assertOk();

        $second = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-001',
            'wa_account_provider_ref' => 'acct-001',
            'wa_session_provider_ref' => 'sess-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => 1714298400,
            'payload' => ['text' => 'hello-updated'],
            'meta' => ['source' => 'gateway'],
        ])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('wa_inbound_messages', 1);
        $this->assertDatabaseHas('wa_inbound_messages', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-001',
            'message_type' => 'text',
            'from' => '+628111',
            'to' => '+628222',
        ]);

        $stored = \App\Models\WaInboundMessage::query()
            ->where('tenant_id', $tenantOne->id)
            ->where('provider_message_id', 'msg-001')
            ->firstOrFail();

        $this->assertSame('hello', $stored->payload['text'] ?? null);
    }

    public function test_duplicate_inbound_replay_does_not_duplicate_outbound_or_action_logs(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $this->app->bind(LlmClientContract::class, fn () => new class implements LlmClientContract
        {
            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                return new LlmResponse(
                    content: '{"intent":"ask_package","confidence":0.92,"entities":{}}',
                    model: 'feature-test-model',
                    totalTokens: 12,
                    raw: ['tenant_id' => $tenantId, 'message' => $userMessage]
                );
            }
        });

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        [$token, $headers] = $this->issueInternalApiTokenForTenant($tenant);
        $this->provisionInboundReferences($token, $headers, $tenant->id);

        $payload = [
            ...$this->baseInboundPayload($tenant->id, '+628111'),
            'provider_message_id' => 'msg-replay-001',
            'payload' => [
                'message' => [
                    'conversation' => 'paketnya apa saja?',
                ],
            ],
        ];

        $first = $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/internal/whatsapp/inbound-messages', $payload)
            ->assertOk();

        $inboundId = $first->json('data.id');
        $this->assertNotNull($inboundId);

        $actionCount = \App\Models\ActionLog::query()->count();
        $outboundCount = \App\Models\WaOutboundMessage::query()->count();
        $inboundTurnTraceCount = \App\Models\DecisionTrace::query()->where('trace_key', 'inbound_turn')->count();
        $actionTraceCount = \App\Models\DecisionTrace::query()->where('trace_key', 'action_dispatch')->count();

        $second = $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/internal/whatsapp/inbound-messages', [
                ...$payload,
                'payload' => [
                    'message' => [
                        'conversation' => 'REPLAY MESSAGE SHOULD NOT REPROCESS',
                    ],
                ],
                'meta' => ['source' => 'replay'],
            ])
            ->assertOk();

        $this->assertSame($inboundId, $second->json('data.id'));
        $this->assertSame($actionCount, \App\Models\ActionLog::query()->count());
        $this->assertSame($outboundCount, \App\Models\WaOutboundMessage::query()->count());
        $this->assertSame($inboundTurnTraceCount, \App\Models\DecisionTrace::query()->where('trace_key', 'inbound_turn')->count());
        $this->assertSame($actionTraceCount, \App\Models\DecisionTrace::query()->where('trace_key', 'action_dispatch')->count());
    }

    public function test_inbound_message_payload_and_reference_contracts_are_enforced(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-001',
            'wa_account_provider_ref' => 'acct-missing',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => '2026-04-28T10:00:00+00:00',
            'payload' => ['text' => 'hello'],
        ])->assertStatus(422);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+628166666666',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-002',
            'wa_account_provider_ref' => 'acct-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => 'invalid-time',
            'payload' => ['text' => 'hello'],
        ])->assertStatus(422);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_message_id' => 'msg-003',
            'wa_account_provider_ref' => 'acct-001',
            'from' => '+628111',
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => '2026-04-28T10:00:00+00:00',
            'payload' => 'bad-payload',
        ])->assertStatus(422);
    }

    public function test_inbound_message_rate_limit_blocks_spam_burst_per_tenant_and_phone(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');
        Config::set('whatsapp.inbound_rate_limit.max_attempts', 30);
        Config::set('whatsapp.inbound_rate_limit.decay_seconds', 60);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        [$token, $headers] = $this->issueInternalApiTokenForTenant($tenant);
        $this->provisionInboundReferences($token, $headers, $tenant->id);

        for ($index = 1; $index <= 30; $index++) {
            $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
                ...$this->baseInboundPayload($tenant->id, '+628111'),
                'provider_message_id' => 'msg-'.$index,
            ])->assertOk();
        }

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenant->id, '+628111'),
            'provider_message_id' => 'msg-31',
        ])->assertStatus(429);
    }

    public function test_inbound_message_rate_limit_isolated_between_tenants(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');
        Config::set('whatsapp.inbound_rate_limit.max_attempts', 1);
        Config::set('whatsapp.inbound_rate_limit.decay_seconds', 60);

        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach([$tenantOne->id, $tenantTwo->id]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');
        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->provisionInboundReferences($token, $headers, $tenantOne->id);
        $this->provisionInboundReferences($token, $headers, $tenantTwo->id);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenantOne->id, '+628111'),
            'provider_message_id' => 'tenant-1-msg-1',
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenantOne->id, '+628111'),
            'provider_message_id' => 'tenant-1-msg-2',
        ])->assertStatus(429);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenantTwo->id, '+628111'),
            'provider_message_id' => 'tenant-2-msg-1',
        ])->assertOk();
    }

    public function test_inbound_message_rate_limit_isolated_between_phones_same_tenant(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');
        Config::set('whatsapp.inbound_rate_limit.max_attempts', 1);
        Config::set('whatsapp.inbound_rate_limit.decay_seconds', 60);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        [$token, $headers] = $this->issueInternalApiTokenForTenant($tenant);
        $this->provisionInboundReferences($token, $headers, $tenant->id);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenant->id, '+628111'),
            'provider_message_id' => 'phone-a-msg-1',
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenant->id, '+628111'),
            'provider_message_id' => 'phone-a-msg-2',
        ])->assertStatus(429);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenant->id, '+628222'),
            'provider_message_id' => 'phone-b-msg-1',
        ])->assertOk();
    }

    public function test_inbound_message_rate_limit_returns_sanitized_429_payload(): void
    {
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');
        Config::set('whatsapp.inbound_rate_limit.max_attempts', 1);
        Config::set('whatsapp.inbound_rate_limit.decay_seconds', 60);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        [$token, $headers] = $this->issueInternalApiTokenForTenant($tenant);
        $this->provisionInboundReferences($token, $headers, $tenant->id);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenant->id, '+628111'),
            'provider_message_id' => 'sanitized-msg-1',
        ])->assertOk();

        $response = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/inbound-messages', [
            ...$this->baseInboundPayload($tenant->id, '+628111'),
            'provider_message_id' => 'sanitized-msg-2',
            'meta' => ['secret' => 'do-not-leak'],
        ]);

        $response
            ->assertStatus(429)
            ->assertExactJson(['message' => 'Too Many Requests']);
    }

    public function test_outbound_message_requires_internal_secret_when_configured(): void
    {
        Queue::fake();
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)->postJson('/api/internal/whatsapp/outbound-messages', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'wa_account_provider_ref' => 'acct-001',
            'to' => '+6281234567890',
            'message_type' => 'text',
            'payload' => ['text' => 'hello'],
        ])->assertForbidden();
    }

    public function test_outbound_message_is_tenant_scoped_deduplicated_and_queued(): void
    {
        Queue::fake();
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenantOne->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+628166666666',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/sessions/upsert', [
            'tenant_id' => $tenantOne->id,
            'wa_account_provider_ref' => 'acct-001',
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'payload' => ['event' => 'active'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/outbound-messages', [
            'tenant_id' => $tenantTwo->id,
            'provider' => 'meta',
            'wa_account_provider_ref' => 'acct-001',
            'wa_session_provider_ref' => 'sess-001',
            'provider_message_id' => 'out-foreign',
            'to' => '+628111',
            'message_type' => 'text',
            'payload' => ['text' => 'hello'],
        ])->assertForbidden();

        $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/outbound-messages', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'wa_account_provider_ref' => 'acct-001',
            'wa_session_provider_ref' => 'sess-001',
            'provider_message_id' => 'out-001',
            'to' => '+628111',
            'message_type' => 'text',
            'payload' => ['text' => 'hello'],
        ])->assertOk();

        $second = $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/outbound-messages', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'wa_account_provider_ref' => 'acct-001',
            'wa_session_provider_ref' => 'sess-001',
            'provider_message_id' => 'out-001',
            'to' => '+628111',
            'message_type' => 'text',
            'payload' => ['text' => 'hello-updated'],
            'meta' => ['source' => 'api'],
        ])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('wa_outbound_messages', 1);
        $this->assertDatabaseHas('wa_outbound_messages', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_message_id' => 'out-001',
            'to' => '+628111',
            'message_type' => 'text',
            'status' => 'pending',
        ]);
        Queue::assertPushed(DispatchWaOutboundMessageJob::class, 1);
    }

    public function test_outbound_message_payload_and_reference_contracts_are_enforced(): void
    {
        Queue::fake();
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/outbound-messages', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'wa_account_provider_ref' => 'acct-missing',
            'to' => '+628111',
            'message_type' => 'text',
            'payload' => ['text' => 'hello'],
        ])->assertStatus(422);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+628166666666',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/outbound-messages', [
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'wa_account_provider_ref' => 'acct-001',
            'to' => '+628111',
            'message_type' => 'text',
            'payload' => 'bad-payload',
        ])->assertStatus(422);
    }

    public function test_outbound_message_with_foreign_session_reference_is_rejected_without_side_effects(): void
    {
        Queue::fake();
        Config::set('whatsapp.internal_secret', 'wa-internal-secret');

        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenantOne->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        $headers = ['X-Internal-Secret' => 'wa-internal-secret'];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+628166666666',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantTwo->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-002',
            'status' => 'connected',
            'phone' => '+628177777777',
            'payload' => ['event' => 'connected'],
        ])->assertForbidden();

        User::query()->whereKey($admin->id)->firstOrFail()->tenants()->sync([$tenantOne->id, $tenantTwo->id]);

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantTwo->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-002',
            'status' => 'connected',
            'phone' => '+628177777777',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/sessions/upsert', [
            'tenant_id' => $tenantTwo->id,
            'wa_account_provider_ref' => 'acct-002',
            'provider' => 'meta',
            'provider_ref' => 'sess-foreign',
            'status' => 'active',
            'payload' => ['event' => 'active'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/outbound-messages', [
            'tenant_id' => $tenantOne->id,
            'provider' => 'meta',
            'wa_account_provider_ref' => 'acct-001',
            'wa_session_provider_ref' => 'sess-foreign',
            'provider_message_id' => 'out-foreign-session',
            'to' => '+628111',
            'message_type' => 'text',
            'payload' => ['text' => 'hello'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('wa_outbound_messages', 0);
        Queue::assertNothingPushed();
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function issueInternalApiTokenForTenant(Tenant $tenant): array
    {
        $admin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $admin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('token');

        return [$token, ['X-Internal-Secret' => 'wa-internal-secret']];
    }

    private function provisionInboundReferences(string $token, array $headers, int $tenantId): void
    {
        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/accounts/upsert', [
            'tenant_id' => $tenantId,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'status' => 'connected',
            'phone' => '+628166666666',
            'payload' => ['event' => 'connected'],
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->postJson('/api/internal/whatsapp/sessions/upsert', [
            'tenant_id' => $tenantId,
            'wa_account_provider_ref' => 'acct-001',
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'payload' => ['event' => 'active'],
        ])->assertOk();
    }

    /**
     * @return array<string,mixed>
     */
    private function baseInboundPayload(int $tenantId, string $from): array
    {
        return [
            'tenant_id' => $tenantId,
            'provider' => 'meta',
            'provider_message_id' => 'msg-default',
            'wa_account_provider_ref' => 'acct-001',
            'wa_session_provider_ref' => 'sess-001',
            'from' => $from,
            'to' => '+628222',
            'message_type' => 'text',
            'message_timestamp' => '2026-04-28T10:00:00+00:00',
            'payload' => ['text' => 'hello'],
        ];
    }
}
