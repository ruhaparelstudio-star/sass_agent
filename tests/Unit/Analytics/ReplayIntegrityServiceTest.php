<?php

namespace Tests\Unit\Analytics;

use App\Models\ActionLog;
use App\Models\Conversation;
use App\Models\DecisionTrace;
use App\Models\Tenant;
use App\Modules\Analytics\Services\ReplayIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplayIntegrityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrity_passes_for_consistent_trace_and_action_links(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+621111',
            'status' => 'open',
        ]);

        $actionLog = ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => [],
        ]);

        DecisionTrace::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action_log_id' => $actionLog->id,
            'trace_key' => 'action_dispatch',
            'token_usage_total' => 10,
            'meta' => null,
        ]);

        $result = app(ReplayIntegrityService::class)->checkTenantConversation(
            $tenant->id,
            $conversation->id
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['issues_count']);
        $this->assertSame([], $result['issues']);
    }

    public function test_integrity_fails_when_trace_action_belongs_to_other_tenant(): void
    {
        $tenantA = Tenant::query()->create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'is_active' => true,
        ]);
        $tenantB = Tenant::query()->create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
        ]);

        $conversationA = Conversation::query()->create([
            'tenant_id' => $tenantA->id,
            'customer_phone' => '+621111',
            'status' => 'open',
        ]);
        $conversationB = Conversation::query()->create([
            'tenant_id' => $tenantB->id,
            'customer_phone' => '+622222',
            'status' => 'open',
        ]);

        $actionLogB = ActionLog::query()->create([
            'tenant_id' => $tenantB->id,
            'conversation_id' => $conversationB->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => [],
        ]);

        DecisionTrace::query()->create([
            'tenant_id' => $tenantA->id,
            'conversation_id' => $conversationA->id,
            'action_log_id' => $actionLogB->id,
            'trace_key' => 'action_dispatch',
            'token_usage_total' => 9,
            'meta' => null,
        ]);

        $result = app(ReplayIntegrityService::class)->checkTenantConversation(
            $tenantA->id,
            $conversationA->id
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['issues_count']);
        $this->assertContains('trace_action_tenant_mismatch', array_column($result['issues'], 'code'));
        $this->assertContains('trace_action_conversation_mismatch', array_column($result['issues'], 'code'));
    }
}
