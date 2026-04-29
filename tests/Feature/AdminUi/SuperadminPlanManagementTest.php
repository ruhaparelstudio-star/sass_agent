<?php

namespace Tests\Feature\AdminUi;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperadminPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_access_plan_management_pages(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $plan = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)->get('/superadmin/plans')->assertOk();
        $this->actingAs($superadmin)->get('/superadmin/plans/create')->assertOk();
        $this->actingAs($superadmin)->get('/superadmin/plans/'.$plan->id.'/edit')->assertOk();
    }

    public function test_tenant_admin_is_forbidden_from_plan_management_routes(): void
    {
        $tenantAdmin = User::factory()->create(['role' => 'tenant_admin']);
        $plan = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);

        $this->actingAs($tenantAdmin)->get('/superadmin/plans')->assertForbidden();
        $this->actingAs($tenantAdmin)->get('/superadmin/plans/create')->assertForbidden();
        $this->actingAs($tenantAdmin)->get('/superadmin/plans/'.$plan->id.'/edit')->assertForbidden();
        $this->actingAs($tenantAdmin)->post('/superadmin/subscriptions/assign', [
            'tenant_id' => 1,
            'plan_id' => $plan->id,
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_guest_is_redirected_for_plan_management_routes(): void
    {
        $this->get('/superadmin/plans')->assertRedirect('/login');
        $this->get('/superadmin/plans/create')->assertRedirect('/login');
    }

    public function test_superadmin_can_create_and_update_plan_via_web_ui(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $createResponse = $this->actingAs($superadmin)->post('/superadmin/plans', [
            'name' => 'Growth',
            'slug' => 'growth',
            'is_active' => '1',
        ]);

        $createResponse->assertRedirect('/superadmin/plans/1/edit');
        $this->assertDatabaseHas('plans', [
            'id' => 1,
            'name' => 'Growth',
            'slug' => 'growth',
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)->put('/superadmin/plans/1', [
            'name' => 'Growth Updated',
            'slug' => 'growth-updated',
            'is_active' => '0',
        ])->assertRedirect('/superadmin/plans/1/edit');

        $this->assertDatabaseHas('plans', [
            'id' => 1,
            'name' => 'Growth Updated',
            'slug' => 'growth-updated',
            'is_active' => false,
        ]);
    }

    public function test_superadmin_can_manage_plan_features_via_web_ui(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $plan = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)->post('/superadmin/plans/'.$plan->id.'/features', [
            'code' => 'monthly_conversations',
            'name' => 'Monthly Conversations',
            'value_int' => '500',
        ])->assertRedirect('/superadmin/plans/'.$plan->id.'/edit');

        $feature = PlanFeature::query()->firstOrFail();

        $this->assertDatabaseHas('plan_features', [
            'id' => $feature->id,
            'plan_id' => $plan->id,
            'code' => 'monthly_conversations',
            'value_int' => 500,
        ]);

        $this->actingAs($superadmin)->put('/superadmin/plans/'.$plan->id.'/features/'.$feature->id, [
            'code' => 'monthly_conversations',
            'name' => 'Monthly Conversations Updated',
            'value_int' => '750',
        ])->assertRedirect('/superadmin/plans/'.$plan->id.'/edit');

        $this->assertDatabaseHas('plan_features', [
            'id' => $feature->id,
            'name' => 'Monthly Conversations Updated',
            'value_int' => 750,
        ]);

        $this->actingAs($superadmin)
            ->delete('/superadmin/plans/'.$plan->id.'/features/'.$feature->id)
            ->assertRedirect('/superadmin/plans/'.$plan->id.'/edit');

        $this->assertDatabaseCount('plan_features', 0);
    }

    public function test_superadmin_can_assign_and_reassign_subscription_single_active_invariant(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
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

        $this->actingAs($superadmin)->post('/superadmin/subscriptions/assign', [
            'tenant_id' => $tenant->id,
            'plan_id' => $planOne->id,
            'status' => 'active',
        ])->assertRedirect('/superadmin/plans')
            ->assertSessionHas('success', 'Langganan berhasil ditetapkan.');

        $firstSubscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->where('plan_id', $planOne->id)->firstOrFail();

        $this->actingAs($superadmin)->post('/superadmin/subscriptions/assign', [
            'tenant_id' => $tenant->id,
            'plan_id' => $planTwo->id,
            'status' => 'trial',
        ])->assertRedirect('/superadmin/plans');

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $firstSubscription->id,
            'status' => 'cancelled',
            'current_marker' => null,
        ]);

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $planTwo->id,
            'status' => 'trial',
            'current_marker' => 1,
        ]);
    }

    public function test_superadmin_can_unassign_subscription_without_affecting_other_tenant(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

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

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenantOne->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_marker' => 1,
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenantTwo->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_marker' => 1,
        ]);

        $this->actingAs($superadmin)
            ->post('/superadmin/subscriptions/unassign', ['tenant_id' => $tenantOne->id])
            ->assertRedirect('/superadmin/plans')
            ->assertSessionHas('success', 'Langganan berhasil dilepas.');

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenantOne->id,
            'status' => 'cancelled',
            'current_marker' => null,
        ]);

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenantTwo->id,
            'status' => 'active',
            'current_marker' => 1,
        ]);
    }

    public function test_validation_failures_are_returned_to_form_with_errors(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)
            ->from('/superadmin/plans/create')
            ->post('/superadmin/plans', [
                'name' => 'Starter Two',
                'slug' => 'starter',
                'is_active' => '1',
            ])
            ->assertRedirect('/superadmin/plans/create')
            ->assertSessionHasErrors('slug');

        $this->actingAs($superadmin)
            ->from('/superadmin/plans/'.$plan->id.'/edit')
            ->post('/superadmin/plans/'.$plan->id.'/features', [
                'code' => 'mixed_value',
                'name' => 'Mixed Value',
                'value_int' => '1',
                'value_bool' => '1',
            ])
            ->assertRedirect('/superadmin/plans/'.$plan->id.'/edit')
            ->assertSessionHasErrors('operation');

        $this->actingAs($superadmin)
            ->from('/superadmin/plans')
            ->post('/superadmin/subscriptions/assign', [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now()->addDay()->toISOString(),
                'ends_at' => now()->toISOString(),
            ])
            ->assertRedirect('/superadmin/plans')
            ->assertSessionHasErrors('operation');
    }
}
