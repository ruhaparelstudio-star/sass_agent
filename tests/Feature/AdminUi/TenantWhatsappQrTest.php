<?php

namespace Tests\Feature\AdminUi;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WaAccount;
use App\Models\WaSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantWhatsappQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_page_marks_dummy_payload_as_unavailable(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $this->assignWaAgentLimit($tenant, 2);

        Config::set('whatsapp.gateway_qr_url', 'http://wa-gateway:3001/qr');
        Http::fake([
            'http://wa-gateway:3001/qr*' => Http::response([
                'tenant_id' => $tenant->id,
                'provider' => 'meta',
                'qr_code' => 'dummy-wa-qr-v1',
                'expires_in_seconds' => 60,
                'generated_at' => '2026-04-29T12:00:00Z',
            ], 200),
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/tenant/whatsapp/qr');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/WhatsappQr', false)
                ->where('tenantId', $tenant->id)
                ->where('qr.status', 'unavailable')
                ->where('agent.limit', 2)
                ->where('agent.used', 0)
                ->where('agent.remaining', 2)
                ->where('agent.canAdd', true)
                ->where('qr.provider', null)
                ->where('qr.code', null)
            );
    }

    public function test_qr_page_marks_real_payload_as_available(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $this->assignWaAgentLimit($tenant, 2);

        Config::set('whatsapp.gateway_qr_url', 'http://wa-gateway:3001/qr');
        Http::fake([
            'http://wa-gateway:3001/qr*' => Http::response([
                'tenant_id' => $tenant->id,
                'provider' => 'meta',
                'qr_code' => 'REAL-QR-PAYLOAD-123',
                'expires_in_seconds' => 60,
                'generated_at' => '2026-04-29T12:00:00Z',
            ], 200),
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/tenant/whatsapp/qr');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/WhatsappQr', false)
                ->where('tenantId', $tenant->id)
                ->where('qr.status', 'available')
                ->where('qr.provider', 'meta')
                ->where('qr.code', 'REAL-QR-PAYLOAD-123')
                ->where('agent.limit', 2)
                ->where('qr.expiresInSeconds', 60)
            );
    }

    public function test_unauthenticated_user_is_redirected_from_whatsapp_qr_page(): void
    {
        $this->get('/tenant/whatsapp/qr')->assertRedirect('/login');
    }

    public function test_non_tenant_admin_cannot_access_whatsapp_qr_page(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)->get('/tenant/whatsapp/qr')->assertForbidden();
    }

    public function test_qr_page_shows_unavailable_when_gateway_response_fails(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $this->assignWaAgentLimit($tenant, 1);

        Config::set('whatsapp.gateway_qr_url', 'http://wa-gateway:3001/qr');
        Http::fake([
            'http://wa-gateway:3001/qr*' => Http::response(['status' => 'error'], 500),
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/tenant/whatsapp/qr');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/WhatsappQr', false)
                ->where('qr.status', 'unavailable')
            );
    }

    public function test_connect_endpoint_calls_gateway_when_subscription_has_remaining_slot(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $this->assignWaAgentLimit($tenant, 2);

        Config::set('whatsapp.gateway_connect_url', 'http://wa-gateway:3001/sessions/connect');

        Http::fake([
            'http://wa-gateway:3001/sessions/connect' => Http::response(['status' => 'ok', 'started' => true], 200),
        ]);

        $response = $this->actingAs($tenantAdmin)->post('/tenant/whatsapp/qr/connect');

        $response->assertRedirect('/tenant/whatsapp/qr');

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === 'http://wa-gateway:3001/sessions/connect'
            && $request['tenant_id'] === $tenant->id
            && $request['provider'] === 'meta');
    }

    public function test_connect_endpoint_is_blocked_when_subscription_limit_reached(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $this->assignWaAgentLimit($tenant, 1);

        WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-used-1',
            'status' => 'connected',
            'meta' => null,
            'last_payload' => ['source' => 'test'],
        ]);

        Config::set('whatsapp.gateway_connect_url', 'http://wa-gateway:3001/sessions/connect');
        Http::fake();

        $response = $this->actingAs($tenantAdmin)->from('/tenant/whatsapp/qr')->post('/tenant/whatsapp/qr/connect');

        $response
            ->assertRedirect('/tenant/whatsapp/qr')
            ->assertSessionHasErrors(['operation']);

        Http::assertNothingSent();
    }

    public function test_disconnect_updates_account_status_and_frees_slot(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $this->assignWaAgentLimit($tenant, 1);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-active-1',
            'status' => 'connected',
            'meta' => null,
            'last_payload' => ['source' => 'test'],
        ]);

        $session = WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-active-1',
            'status' => 'active',
            'meta' => null,
            'last_payload' => ['source' => 'test'],
        ]);

        Config::set('whatsapp.gateway_disconnect_url', 'http://wa-gateway:3001/sessions/disconnect');
        Http::fake([
            'http://wa-gateway:3001/sessions/disconnect' => Http::response(['status' => 'ok', 'disconnected' => 1], 200),
            'http://wa-gateway:3001/qr*' => Http::response(['status' => 'unavailable'], 404),
        ]);

        $response = $this->actingAs($tenantAdmin)->post('/tenant/whatsapp/agents/'.$account->id.'/disconnect');
        $response->assertRedirect('/tenant/whatsapp/qr');

        $this->assertDatabaseHas('wa_accounts', [
            'id' => $account->id,
            'status' => 'disconnected',
        ]);
        $this->assertDatabaseHas('wa_sessions', [
            'id' => $session->id,
            'status' => 'closed',
        ]);

        $page = $this->actingAs($tenantAdmin)->get('/tenant/whatsapp/qr');
        $page->assertInertia(fn (Assert $inertia) => $inertia
            ->where('agent.used', 0)
            ->where('agent.remaining', 1)
            ->where('agent.canAdd', true)
        );
    }

    public function test_reconnect_uses_same_account_reference(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $this->assignWaAgentLimit($tenant, 1);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-disconnected-1',
            'status' => 'disconnected',
            'meta' => null,
            'last_payload' => ['source' => 'test'],
        ]);

        Config::set('whatsapp.gateway_connect_url', 'http://wa-gateway:3001/sessions/connect');
        Config::set('whatsapp.gateway_disconnect_url', 'http://wa-gateway:3001/sessions/disconnect');
        Http::fake([
            'http://wa-gateway:3001/sessions/connect' => Http::response(['status' => 'ok', 'started' => true], 200),
            'http://wa-gateway:3001/sessions/disconnect' => Http::response(['status' => 'ok', 'disconnected' => 1], 200),
        ]);

        $response = $this->actingAs($tenantAdmin)->post('/tenant/whatsapp/agents/'.$account->id.'/reconnect');
        $response->assertRedirect('/tenant/whatsapp/qr');

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === 'http://wa-gateway:3001/sessions/connect'
            && $request['tenant_id'] === $tenant->id
            && $request['account_provider_ref'] === 'acct-disconnected-1');
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === 'http://wa-gateway:3001/sessions/disconnect'
            && $request['tenant_id'] === $tenant->id
            && $request['account_provider_ref'] === 'acct-disconnected-1');

        $this->assertDatabaseHas('wa_accounts', [
            'id' => $account->id,
            'status' => 'connecting',
        ]);
    }

    private function createTenantAdmin(string $slug): array
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_active' => true,
        ]);

        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
        ]);

        $tenantAdmin->tenants()->attach($tenant->id);

        return [$tenant, $tenantAdmin];
    }

    private function assignWaAgentLimit(Tenant $tenant, int $limit): void
    {
        $plan = Plan::query()->create([
            'name' => 'Starter '.$tenant->slug,
            'slug' => 'starter-'.$tenant->slug,
            'is_active' => true,
        ]);

        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'code' => 'wa_agent_limit',
            'name' => 'WA Agent Limit',
            'value_int' => $limit,
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'current_marker' => 1,
        ]);
    }
}
