<?php

namespace Tests\Feature\AdminUi;

use App\Models\ActionLog;
use App\Models\Conversation;
use App\Models\Handoff;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuperadminTenantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_access_dashboard_and_tenant_pages(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)->get('/superadmin/dashboard')
            ->assertOk();

        $this->actingAs($superadmin)->get('/superadmin/tenants')
            ->assertOk()
            ->assertDontSeeText('Conversation Inbox');

        $this->actingAs($superadmin)->get('/superadmin/tenants/create')
            ->assertOk();

        $this->actingAs($superadmin)->get('/superadmin/tenants/'.$tenant->id)
            ->assertOk();

        $this->actingAs($superadmin)->get('/superadmin/tenants/'.$tenant->id.'/edit')
            ->assertOk();
    }

    public function test_superadmin_can_create_tenant(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'name' => 'Acme Wedding',
            'slug' => 'acme-wedding',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/superadmin/tenants/1');

        $this->assertDatabaseHas('tenants', [
            'name' => 'Acme Wedding',
            'slug' => 'acme-wedding',
            'is_active' => true,
        ]);
    }

    public function test_superadmin_create_tenant_fails_when_slug_is_duplicate(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)
            ->from('/superadmin/tenants/create')
            ->post('/superadmin/tenants', [
                'name' => 'Tenant Two',
                'slug' => 'tenant-one',
                'is_active' => '1',
            ])
            ->assertRedirect('/superadmin/tenants/create')
            ->assertSessionHasErrors('slug');
    }

    public function test_superadmin_can_update_tenant(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)->put('/superadmin/tenants/'.$tenant->id, [
            'name' => 'Tenant One Updated',
            'slug' => 'tenant-one-updated',
            'is_active' => '0',
        ])->assertRedirect('/superadmin/tenants/'.$tenant->id);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Tenant One Updated',
            'slug' => 'tenant-one-updated',
            'is_active' => false,
        ]);
    }

    public function test_superadmin_can_deactivate_tenant_without_deleting_row(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)
            ->post('/superadmin/tenants/'.$tenant->id.'/deactivate')
            ->assertRedirect('/superadmin/tenants/'.$tenant->id);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_tenant_admin_is_forbidden_from_superadmin_pages(): void
    {
        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
        ]);

        $this->actingAs($tenantAdmin)->get('/superadmin/dashboard')->assertForbidden();
        $this->actingAs($tenantAdmin)->get('/superadmin/tenants')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_for_superadmin_pages(): void
    {
        $this->get('/superadmin/dashboard')->assertRedirect('/login');
        $this->get('/superadmin/tenants')->assertRedirect('/login');
    }

    public function test_superadmin_dashboard_renders_an1_aggregate_metrics(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => false,
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenantOne->id,
            'customer_phone' => '+621111',
            'full_name' => 'Lead One',
        ]);
        LeadProfile::query()->create([
            'tenant_id' => $tenantTwo->id,
            'customer_phone' => '+622222',
            'full_name' => 'Lead Two',
        ]);

        $conversationOne = Conversation::query()->create([
            'tenant_id' => $tenantOne->id,
            'customer_phone' => '+621111',
            'status' => 'open',
        ]);
        $conversationTwo = Conversation::query()->create([
            'tenant_id' => $tenantTwo->id,
            'customer_phone' => '+622222',
            'status' => 'open',
        ]);

        Handoff::query()->create([
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $conversationOne->id,
            'reason_code' => 'needs_human',
            'status' => 'pending',
        ]);
        Handoff::query()->create([
            'tenant_id' => $tenantTwo->id,
            'conversation_id' => $conversationTwo->id,
            'reason_code' => 'resolve',
            'status' => 'resolved',
        ]);

        ActionLog::query()->create([
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $conversationOne->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => 5],
        ]);
        ActionLog::query()->create([
            'tenant_id' => $tenantTwo->id,
            'conversation_id' => $conversationTwo->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => 7],
        ]);

        $this->actingAs($superadmin)->get('/superadmin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('summary')
                ->has('recentTenants')
                ->where('summary.tenants_total', 2)
                ->where('summary.tenants_active', 1)
                ->where('summary.tenants_inactive', 1)
                ->where('summary.lead_count', 2)
                ->where('summary.handoff_count', 2)
                ->where('summary.booking_action_count', 2)
                ->where('summary.token_usage_total', 12)
            );
    }
}
