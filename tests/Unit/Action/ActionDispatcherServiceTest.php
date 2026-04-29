<?php

namespace Tests\Unit\Action;

use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Modules\Action\Services\ActionDispatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionDispatcherServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_action_executes_and_logs_result(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'reply_safe_text',
                'reasons' => [],
            ]
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame('reply_safe_text', $result['action']);
        $this->assertNull($result['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'reply_safe_text',
            'status' => 'executed',
            'reason' => null,
        ]);
    }

    public function test_unsupported_action_is_blocked_and_logged(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_pricelist',
                'reasons' => [],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('send_pricelist', $result['action']);
        $this->assertSame('unsupported_action', $result['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_pricelist',
            'status' => 'blocked',
            'reason' => 'unsupported_action',
        ]);
    }

    public function test_tenant_ownership_mismatch_is_blocked_and_logged(): void
    {
        [$tenantOne] = $this->createConversation();
        [$tenantTwo, $conversationTwo] = $this->createConversation('tenant-two');

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenantOne,
            $conversationTwo,
            [
                'action' => 'reply_safe_text',
                'reasons' => [],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('reply_safe_text', $result['action']);
        $this->assertSame('tenant_conversation_mismatch', $result['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $conversationTwo->id,
            'action' => 'reply_safe_text',
            'status' => 'blocked',
            'reason' => 'tenant_conversation_mismatch',
        ]);

        $this->assertDatabaseMissing('action_logs', [
            'tenant_id' => $tenantTwo->id,
            'conversation_id' => $conversationTwo->id,
            'action' => 'reply_safe_text',
        ]);
    }

    public function test_candidate_with_reasons_is_blocked_and_logged(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'reply_safe_text',
                'reasons' => ['missing_name'],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('missing_name', $result['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'reply_safe_text',
            'status' => 'blocked',
            'reason' => 'missing_name',
        ]);
    }

    private function createConversation(string $slug = 'tenant-one'): array
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+628111111111',
            'status' => 'open',
        ]);

        ConversationState::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'current_stage' => 'new',
            'active_goal' => 'pricing',
            'agent_mode' => 'assistant',
            'memory_mode' => 'short',
            'retention_policy' => 'standard',
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $conversation->customer_phone,
            'full_name' => null,
        ]);

        return [$tenant, $conversation];
    }
}
