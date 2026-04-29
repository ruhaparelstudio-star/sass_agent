<?php

namespace Tests\Feature\AdminUi;

use App\Models\Conversation;
use App\Models\Handoff;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperadminConversationMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_access_global_conversation_monitoring_page(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+620901',
            'status' => 'open',
        ]);

        Message::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Mohon info paket wedding.',
            'meta' => null,
        ]);

        Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'low_confidence',
            'status' => 'pending',
        ]);

        $this->actingAs($superadmin)
            ->get('/superadmin/conversations')
            ->assertOk()
            ->assertSee('Superadmin\\/ConversationsIndex', false)
            ->assertSeeText($tenant->name)
            ->assertSeeText((string) $conversation->id)
            ->assertSeeText('Mohon info paket wedding.');
    }

    public function test_tenant_admin_is_forbidden_from_superadmin_conversation_monitoring_page(): void
    {
        $tenantAdmin = User::factory()->create([
            'role' => 'tenant_admin',
        ]);

        $this->actingAs($tenantAdmin)
            ->get('/superadmin/conversations')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_for_superadmin_conversation_monitoring_page(): void
    {
        $this->get('/superadmin/conversations')
            ->assertRedirect('/login');
    }
}
