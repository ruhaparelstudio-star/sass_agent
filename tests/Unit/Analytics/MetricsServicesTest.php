<?php

namespace Tests\Unit\Analytics;

use App\Models\ActionLog;
use App\Models\Conversation;
use App\Models\Handoff;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Modules\Analytics\Services\MetricsSnapshotWriter;
use App\Modules\Analytics\Services\SuperadminMetricsQueryService;
use App\Modules\Analytics\Services\TenantMetricsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricsServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_metrics_query_returns_correct_counts(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $otherTenant = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+621111',
            'full_name' => 'One',
        ]);
        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+621112',
            'full_name' => 'Two',
        ]);
        LeadProfile::query()->create([
            'tenant_id' => $otherTenant->id,
            'customer_phone' => '+629999',
            'full_name' => 'Other',
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+621111',
            'status' => 'open',
        ]);
        $otherConversation = Conversation::query()->create([
            'tenant_id' => $otherTenant->id,
            'customer_phone' => '+629999',
            'status' => 'open',
        ]);

        Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'low_confidence',
            'status' => 'pending',
        ]);
        Handoff::query()->create([
            'tenant_id' => $otherTenant->id,
            'conversation_id' => $otherConversation->id,
            'reason_code' => 'other',
            'status' => 'pending',
        ]);

        ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => 42],
        ]);
        ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'blocked',
            'reason' => 'missing_name',
            'payload' => null,
            'result' => ['token_usage_total' => 10],
        ]);
        ActionLog::query()->create([
            'tenant_id' => $otherTenant->id,
            'conversation_id' => $otherConversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => 100],
        ]);

        $metrics = app(TenantMetricsQueryService::class)->getSummary($tenant->id);

        $this->assertSame(2, $metrics['lead_count']);
        $this->assertSame(1, $metrics['handoff_count']);
        $this->assertSame(1, $metrics['booking_action_count']);
        $this->assertSame(52, $metrics['token_usage_total']);
    }

    public function test_superadmin_metrics_query_aggregates_across_tenants(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);
        $otherTenant = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+621111',
            'full_name' => 'One',
        ]);
        LeadProfile::query()->create([
            'tenant_id' => $otherTenant->id,
            'customer_phone' => '+622222',
            'full_name' => 'Two',
        ]);

        $conversationOne = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+621111',
            'status' => 'open',
        ]);
        $conversationTwo = Conversation::query()->create([
            'tenant_id' => $otherTenant->id,
            'customer_phone' => '+622222',
            'status' => 'open',
        ]);

        Handoff::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationOne->id,
            'reason_code' => 'one',
            'status' => 'pending',
        ]);
        Handoff::query()->create([
            'tenant_id' => $otherTenant->id,
            'conversation_id' => $conversationTwo->id,
            'reason_code' => 'two',
            'status' => 'resolved',
        ]);

        ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationOne->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => 11],
        ]);
        ActionLog::query()->create([
            'tenant_id' => $otherTenant->id,
            'conversation_id' => $conversationTwo->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => 19],
        ]);

        $summary = app(SuperadminMetricsQueryService::class)->getSummary();

        $this->assertSame(2, $summary['lead_count']);
        $this->assertSame(2, $summary['handoff_count']);
        $this->assertSame(2, $summary['booking_action_count']);
        $this->assertSame(30, $summary['token_usage_total']);
    }

    public function test_metrics_query_ignores_non_numeric_token_usage_values(): void
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

        ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => 'not-a-number'],
        ]);
        ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => null],
        ]);
        ActionLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
            'payload' => null,
            'result' => ['token_usage_total' => '21'],
        ]);

        $tenantSummary = app(TenantMetricsQueryService::class)->getSummary($tenant->id);
        $superadminSummary = app(SuperadminMetricsQueryService::class)->getSummary();

        $this->assertSame(3, $tenantSummary['booking_action_count']);
        $this->assertSame(21, $tenantSummary['token_usage_total']);
        $this->assertSame(21, $superadminSummary['token_usage_total']);
    }

    public function test_metrics_snapshot_writer_persists_metric_rows(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $written = app(MetricsSnapshotWriter::class)->writeTenantSummary(
            $tenant->id,
            [
                'lead_count' => 3,
                'handoff_count' => 2,
                'booking_action_count' => 1,
                'token_usage_total' => 99,
            ],
            ['source' => 'unit-test']
        );

        $this->assertSame(4, $written);
        $this->assertDatabaseHas('analytics_snapshots', [
            'tenant_id' => $tenant->id,
            'metric' => 'lead_count',
            'value' => 3,
        ]);
        $this->assertDatabaseHas('analytics_snapshots', [
            'tenant_id' => $tenant->id,
            'metric' => 'token_usage_total',
            'value' => 99,
        ]);
    }
}
