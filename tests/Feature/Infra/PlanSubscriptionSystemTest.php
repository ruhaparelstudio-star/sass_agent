<?php

namespace Tests\Feature\Infra;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSubscriptionSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_manage_plan_features_and_assign_subscription(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $superadmin->email,
            'password' => 'password',
        ])->json('token');

        $plan = $this->withToken($token)->postJson('/api/plans', [
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);

        $plan->assertCreated()->assertJsonPath('data.slug', 'starter');
        $planId = $plan->json('data.id');

        $feature = $this->withToken($token)->postJson("/api/plans/{$planId}/features", [
            'code' => 'monthly_conversations',
            'name' => 'Monthly Conversations',
            'value_int' => 500,
        ]);

        $feature->assertCreated()
            ->assertJsonPath('data.code', 'monthly_conversations')
            ->assertJsonPath('data.value_int', 500);

        $this->withToken($token)->postJson('/api/tenant-subscriptions/assign', [
            'tenant_id' => $tenant->id,
            'plan_id' => $planId,
            'status' => 'active',
            'starts_at' => now()->toISOString(),
        ])->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.plan_id', $planId)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $planId,
            'status' => 'active',
        ]);
    }

    public function test_tenant_admin_is_forbidden_from_plan_and_subscription_mutation_routes(): void
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

        $this->withToken($token)->postJson('/api/plans', [
            'name' => 'Forbidden',
            'slug' => 'forbidden',
            'is_active' => true,
        ])->assertForbidden();

        $this->withToken($token)->postJson('/api/tenant-subscriptions/assign', [
            'tenant_id' => $tenant->id,
            'plan_id' => 1,
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_validation_and_transition_safety_rules_are_enforced(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
            'password' => 'password',
        ]);

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

        $planOne = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);
        $planTwo = Plan::query()->create([
            'name' => 'Growth',
            'slug' => 'growth',
            'is_active' => true,
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $superadmin->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)->postJson('/api/tenant-subscriptions/assign', [
            'tenant_id' => $tenantOne->id,
            'plan_id' => $planOne->id,
            'status' => 'active',
            'starts_at' => now()->addDay()->toISOString(),
            'ends_at' => now()->toISOString(),
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/plans/'.$planOne->id.'/features', [
            'code' => 'broken',
            'name' => 'Broken',
            'value_int' => 10,
            'value_bool' => true,
        ])->assertStatus(422);

        $first = $this->withToken($token)->postJson('/api/tenant-subscriptions/assign', [
            'tenant_id' => $tenantOne->id,
            'plan_id' => $planOne->id,
            'status' => 'active',
        ]);
        $first->assertOk();

        $second = $this->withToken($token)->postJson('/api/tenant-subscriptions/assign', [
            'tenant_id' => $tenantOne->id,
            'plan_id' => $planTwo->id,
            'status' => 'trial',
        ]);
        $second->assertOk()->assertJsonPath('data.plan_id', $planTwo->id);

        $firstSubscriptionId = $first->json('data.id');
        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $firstSubscriptionId,
            'status' => 'cancelled',
        ]);

        $tenantTwoSubscription = TenantSubscription::query()->create([
            'tenant_id' => $tenantTwo->id,
            'plan_id' => $planOne->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/tenant-subscriptions/unassign', [
            'tenant_id' => $tenantOne->id,
        ])->assertOk();

        $tenantTwoSubscription->refresh();
        $this->assertSame('active', $tenantTwoSubscription->status->value);
    }
}

