<?php

namespace Tests\Feature\AdminUi;

use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\ConversationState;
use App\Models\DecisionTrace;
use App\Models\Handoff;
use App\Models\Invoice;
use App\Models\LeadProfile;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantConversationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_tenant_admin_can_access_inbox(): void
    {
        [, $tenantAdmin] = $this->createTenantAdmin('tenant-one');

        $response = $this->actingAs($tenantAdmin)->get('/tenant/inbox');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Inbox', false)
                ->has('conversationList')
                ->has('selectedConversation')
                ->has('messages')
                ->has('handoffs')
                ->has('contextPanel')
                ->has('invoiceAssets')
                ->has('invoices')
                ->where('query', '')
            );
    }

    public function test_unauthenticated_user_is_denied_inbox_access(): void
    {
        $this->get('/tenant/inbox')->assertRedirect('/login');
    }

    public function test_non_tenant_admin_cannot_access_inbox(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)->get('/tenant/inbox')->assertForbidden();
    }

    public function test_cross_tenant_conversation_access_is_forbidden(): void
    {
        [, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        [$tenantTwo] = $this->createTenantAdmin('tenant-two');

        $otherConversation = Conversation::query()->create([
            'tenant_id' => $tenantTwo->id,
            'customer_phone' => '+6281234567',
            'status' => 'open',
        ]);

        $this->actingAs($tenantAdmin)
            ->get('/tenant/inbox?conversation_id='.$otherConversation->id)
            ->assertForbidden();
    }

    public function test_inbox_displays_tenant_scoped_data_and_context_panel(): void
    {
        [$tenantOne, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        [$tenantTwo] = $this->createTenantAdmin('tenant-two');

        $selectedConversation = Conversation::query()->create([
            'tenant_id' => $tenantOne->id,
            'customer_phone' => '+620001',
            'status' => 'open',
        ]);

        ConversationState::query()->create([
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $selectedConversation->id,
            'current_stage' => 'qualified',
            'active_goal' => 'booking',
            'agent_mode' => 'ai',
            'memory_mode' => 'short',
        ]);
        LeadProfile::query()->create([
            'tenant_id' => $tenantOne->id,
            'customer_phone' => $selectedConversation->customer_phone,
            'full_name' => 'Rina Utami',
        ]);
        ConversationContext::query()->create([
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $selectedConversation->id,
            'summary' => 'Lead meminta katalog paket wedding.',
            'reason' => 'low_confidence',
            'recommended_next_action' => 'booking',
        ]);

        $inboundMessage = Message::query()->create([
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $selectedConversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'Halo, saya mau tanya paket.',
            'grounding_refs' => ['catalog:package'],
            'meta' => null,
        ]);

        $trace = DecisionTrace::query()->create([
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $selectedConversation->id,
            'message_id' => $inboundMessage->id,
            'trace_key' => 'inbound_turn',
            'token_usage_total' => 0,
            'validators_json' => ['order' => ['policy', 'grounding', 'permission', 'mode']],
            'blocked_actions_json' => [],
            'grounding_refs_json' => ['catalog:package'],
            'final_reply' => 'ok',
            'meta' => ['decision' => ['trace' => ['fallback_reason' => null]]],
        ]);

        $inboundMessage->forceFill(['decision_trace_id' => $trace->id])->save();

        $pendingHandoff = Handoff::query()->create([
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $selectedConversation->id,
            'reason_code' => 'low_confidence',
            'status' => 'pending',
        ]);

        $otherTenantConversation = Conversation::query()->create([
            'tenant_id' => $tenantTwo->id,
            'customer_phone' => '+629999',
            'status' => 'open',
        ]);

        Message::query()->create([
            'tenant_id' => $tenantTwo->id,
            'conversation_id' => $otherTenantConversation->id,
            'direction' => 'inbound',
            'content' => 'Other tenant message',
            'meta' => null,
        ]);

        $this->actingAs($tenantAdmin)
            ->get('/tenant/inbox?conversation_id='.$selectedConversation->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Inbox', false)
                ->where('selectedConversation.id', $selectedConversation->id)
                ->where('contextPanel.lead.full_name', 'Rina Utami')
                ->where('contextPanel.context.reason', 'low_confidence')
                ->where('contextPanel.context.recommended_next_action', 'booking')
                ->where('handoffs.0.id', $pendingHandoff->id)
                ->where('handoffs.0.can_resolve_handoff', true)
                ->where('handoffs.0.can_resume_ai', false)
                ->where('messages.0.body', 'Halo, saya mau tanya paket.')
                ->where('messages.0.message_type', 'text')
                ->where('messages.0.trace.validators.order.0', 'policy')
            );
    }

    public function test_resolve_handoff_succeeds_for_pending_handoff_in_same_tenant(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+620001',
            'status' => 'open',
        ]);

        $handoff = Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'low_confidence',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($tenantAdmin)
            ->post('/tenant/inbox/'.$conversation->id.'/handoff/'.$handoff->id.'/resolve');

        $response->assertRedirect('/tenant/inbox?conversation_id='.$conversation->id);

        $this->assertDatabaseHas('handoffs', [
            'id' => $handoff->id,
            'status' => 'resolved',
        ]);
    }

    public function test_resume_ai_succeeds_for_resolved_handoff_in_same_tenant(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+620100',
            'status' => 'open',
        ]);

        ConversationState::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'current_stage' => 'qualified',
            'active_goal' => 'booking',
            'agent_mode' => 'handoff',
            'memory_mode' => 'short',
        ]);

        $handoff = Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'low_confidence',
            'status' => 'resolved',
        ]);

        $response = $this->actingAs($tenantAdmin)
            ->post('/tenant/inbox/'.$conversation->id.'/handoff/'.$handoff->id.'/resume');

        $response->assertRedirect('/tenant/inbox?conversation_id='.$conversation->id);

        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'agent_mode' => 'ai',
        ]);
    }

    public function test_tenant_admin_can_create_invoice_for_selected_lead_using_invoice_asset(): void
    {
        [$tenant, $tenantAdmin] = $this->createTenantAdmin('tenant-one');

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+620200',
            'status' => 'open',
        ]);

        $asset = TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'invoice',
            'display_name' => 'Invoice Standard',
            'original_filename' => 'invoice-standard.pdf',
            'storage_disk' => 'private',
            'storage_path' => 'tenant-assets/invoice/1/invoice-standard.pdf',
            'uploaded_by_user_id' => $tenantAdmin->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($tenantAdmin)
            ->post('/tenant/inbox/'.$conversation->id.'/invoices', [
                'tenant_asset_id' => $asset->id,
            ]);

        $response->assertRedirect('/tenant/inbox?conversation_id='.$conversation->id);

        $this->assertDatabaseHas('invoices', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'tenant_asset_id' => $asset->id,
            'customer_phone' => '+620200',
            'status' => 'ready',
        ]);
    }

    public function test_tenant_admin_cannot_create_invoice_using_other_tenant_asset(): void
    {
        [$tenantOne, $tenantAdmin] = $this->createTenantAdmin('tenant-one');
        [$tenantTwo] = $this->createTenantAdmin('tenant-two');

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenantOne->id,
            'customer_phone' => '+620201',
            'status' => 'open',
        ]);

        $otherAsset = TenantAsset::query()->create([
            'tenant_id' => $tenantTwo->id,
            'asset_type' => 'invoice',
            'display_name' => 'Invoice Tenant Two',
            'original_filename' => 'invoice-tenant-two.pdf',
            'storage_disk' => 'private',
            'storage_path' => 'tenant-assets/invoice/2/invoice-tenant-two.pdf',
            'uploaded_by_user_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($tenantAdmin)
            ->post('/tenant/inbox/'.$conversation->id.'/invoices', [
                'tenant_asset_id' => $otherAsset->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('invoices', 0);
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
