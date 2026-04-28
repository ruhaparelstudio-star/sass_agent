<?php

namespace Tests\Feature\Infra;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTenantCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_login_create_and_list_tenants(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $superadmin->email,
            'password' => 'password',
        ]);

        $login->assertOk();
        $token = $login->json('token');

        $create = $this->withToken($token)->postJson('/api/tenants', [
            'name' => 'Acme Wedding',
            'slug' => 'acme-wedding',
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('data.slug', 'acme-wedding');

        $this->withToken($token)
            ->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_tenant_admin_can_only_resolve_own_tenant_context(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);

        $tenantAdmin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $tenantAdmin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/me/context')
            ->assertOk()
            ->assertJsonPath('data.role', 'tenant_admin')
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.feature_gate.wa_agent_limit', 0)
            ->assertJsonPath('data.feature_gate.lead_limit', 0)
            ->assertJsonPath('data.feature_gate.calendar_access', false)
            ->assertJsonPath('data.feature_gate.automation_enabled', false);
    }

    public function test_tenant_admin_context_includes_feature_gate_from_active_subscription(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $plan = Plan::query()->create([
            'name' => 'Growth',
            'slug' => 'growth',
            'is_active' => true,
        ]);

        $plan->features()->createMany([
            ['code' => 'wa_agent_limit', 'name' => 'WA Agent Limit', 'value_int' => 3],
            ['code' => 'lead_limit', 'name' => 'Lead Limit', 'value_int' => 1500],
            ['code' => 'calendar_access', 'name' => 'Calendar Access', 'value_bool' => true],
            ['code' => 'automation_enabled', 'name' => 'Automation Enabled', 'value_bool' => true],
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_marker' => 1,
        ]);

        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);
        $tenantAdmin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $tenantAdmin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/me/context')
            ->assertOk()
            ->assertJsonPath('data.feature_gate.wa_agent_limit', 3)
            ->assertJsonPath('data.feature_gate.lead_limit', 1500)
            ->assertJsonPath('data.feature_gate.calendar_access', true)
            ->assertJsonPath('data.feature_gate.automation_enabled', true);
    }

    public function test_superadmin_context_has_null_feature_gate(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $superadmin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/me/context')
            ->assertOk()
            ->assertJsonPath('data.role', 'superadmin')
            ->assertJsonPath('data.tenant_id', null)
            ->assertJsonPath('data.feature_gate', null);
    }

    public function test_tenant_admin_is_forbidden_from_superadmin_tenant_routes(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);

        $tenantAdmin->tenants()->attach($tenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $tenantAdmin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->postJson('/api/tenants', [
                'name' => 'Forbidden Tenant',
                'slug' => 'forbidden-tenant',
            ])
            ->assertForbidden();
    }

    public function test_cross_tenant_context_access_is_forbidden(): void
    {
        $ownedTenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $otherTenant = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
            'password' => 'password',
        ]);

        $tenantAdmin->tenants()->attach($ownedTenant->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $tenantAdmin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/tenants/'.$otherTenant->id)
            ->assertForbidden();
    }

    public function test_invalid_credentials_and_revoked_token_are_denied(): void
    {
        $user = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_token_is_denied(): void
    {
        $this->withToken('invalid-token')
            ->getJson('/api/me/context')
            ->assertUnauthorized();
    }
}
