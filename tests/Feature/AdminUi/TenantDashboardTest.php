<?php

namespace Tests\Feature\AdminUi;

use App\Models\Conversation;
use App\Models\DecisionTrace;
use App\Models\Handoff;
use App\Models\LeadProfile;
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
                ->has('summary.conversations_total')
                ->has('summary.conversations_open')
                ->has('summary.handoffs_pending')
                ->has('summary.notifications_queued')
                ->has('summary.notifications_failed')
                ->has('summary.lead_count')
                ->has('summary.handoff_count')
                ->has('summary.booking_action_count')
                ->has('summary.token_usage_total')
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
            ->where('summary.lead_count', 0)
            ->where('summary.handoff_count', 2)
            ->where('summary.booking_action_count', 0)
            ->where('summary.token_usage_total', 0)
        );
    }

    public function test_dashboard_renders_an1_metrics_with_tenant_scope(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        $otherTenant = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+6211111111',
            'status' => 'open',
        ]);
        $otherConversation = Conversation::query()->create([
            'tenant_id' => $otherTenant->id,
            'customer_phone' => '+6299999999',
            'status' => 'open',
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+6211111111',
            'full_name' => 'Tenant One Lead',
        ]);
        LeadProfile::query()->create([
            'tenant_id' => $otherTenant->id,
            'customer_phone' => '+6299999999',
            'full_name' => 'Other Lead',
        ]);

        Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'needs_human',
            'status' => 'pending',
        ]);
        Handoff::query()->create([
            'tenant_id' => $otherTenant->id,
            'conversation_id' => $otherConversation->id,
            'reason_code' => 'other',
            'status' => 'pending',
        ]);

        \App\Models\ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => [],
        ]);
        \App\Models\ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'blocked',
            'reason' => 'missing_name',
            'payload' => null,
            'result' => [],
        ]);
        \App\Models\ActionLog::query()->create([
            'tenant_id' => $otherTenant->id,
            'conversation_id' => $otherConversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => [],
        ]);
        DecisionTrace::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action_log_id' => null,
            'trace_key' => 'action_dispatch',
            'token_usage_total' => 12,
            'meta' => null,
        ]);
        DecisionTrace::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action_log_id' => null,
            'trace_key' => 'action_dispatch',
            'token_usage_total' => 8,
            'meta' => null,
        ]);
        DecisionTrace::query()->create([
            'tenant_id' => $otherTenant->id,
            'conversation_id' => $otherConversation->id,
            'action_log_id' => null,
            'trace_key' => 'action_dispatch',
            'token_usage_total' => 100,
            'meta' => null,
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/tenant/dashboard');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.lead_count', 1)
            ->where('summary.handoff_count', 1)
            ->where('summary.booking_action_count', 1)
            ->where('summary.token_usage_total', 20)
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
