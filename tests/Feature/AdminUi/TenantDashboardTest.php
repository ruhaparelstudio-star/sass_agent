<?php

namespace Tests\Feature\AdminUi;

use App\Models\Conversation;
use App\Models\Handoff;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_tenant_admin_can_access_dashboard(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');

        $response = $this->actingAs($tenantAdmin)->get('/tenant/dashboard?tenant_id='.$tenant->id);

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Dashboard', false)
                ->has('summary')
                ->has('recentConversations')
                ->has('recentHandoffs')
                ->has('recentNotifications')
                ->where('tenantId', $tenant->id)
            );
    }

    public function test_unauthenticated_user_is_denied_dashboard_access(): void
    {
        $this->get('/tenant/dashboard')->assertRedirect('/login');
    }

    public function test_non_tenant_admin_cannot_access_dashboard(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)->get('/tenant/dashboard')->assertForbidden();
    }

    public function test_cross_tenant_access_is_forbidden(): void
    {
        [$ownedTenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $otherTenant = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/tenant/dashboard?tenant_id='.$otherTenant->id);

        $response->assertForbidden();

        $this->assertNotSame($ownedTenant->id, $otherTenant->id);
    }

    public function test_dashboard_renders_expected_core_summary_values(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');

        $conversationOpen = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+6211111111',
            'status' => 'open',
        ]);
        $conversationClosed = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+6222222222',
            'status' => 'closed',
        ]);

        Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationOpen->id,
            'reason_code' => 'low_confidence',
            'status' => 'pending',
        ]);
        Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationClosed->id,
            'reason_code' => 'done',
            'status' => 'resolved',
        ]);

        Notification::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationOpen->id,
            'handoff_id' => null,
            'type' => 'handoff_created',
            'channel' => 'internal',
            'status' => 'queued',
        ]);
        Notification::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationClosed->id,
            'handoff_id' => null,
            'type' => 'handoff_created',
            'channel' => 'internal',
            'status' => 'failed',
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/tenant/dashboard');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Dashboard', false)
            ->where('summary.conversations_total', 2)
            ->where('summary.conversations_open', 1)
            ->where('summary.handoffs_pending', 1)
            ->where('summary.notifications_queued', 1)
            ->where('summary.notifications_failed', 1)
        );
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
}
