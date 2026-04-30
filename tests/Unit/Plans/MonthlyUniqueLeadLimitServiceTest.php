<?php

namespace Tests\Unit\Plans;

use App\Enums\SubscriptionStatus;
use App\Models\LeadProfile;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Modules\Plans\Services\MonthlyUniqueLeadLimitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyUniqueLeadLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_number_in_same_billing_period_is_not_counted_twice(): void
    {
        CarbonImmutable::setTestNow('2026-04-30 10:00:00');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $plan = Plan::query()->create([
            'code' => 'growth',
            'name' => 'Growth',
            'slug' => 'growth',
            'is_active' => true,
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
            'current_marker' => 1,
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '628111',
            'full_name' => 'Rina',
            'created_at' => CarbonImmutable::parse('2026-04-03 09:00:00'),
            'updated_at' => CarbonImmutable::parse('2026-04-03 09:00:00'),
        ]);

        $result = app(MonthlyUniqueLeadLimitService::class)
            ->evaluate($tenant->id, '628111', 1, CarbonImmutable::parse('2026-04-30 10:00:00'));

        $this->assertSame(1, $result['unique_lead_count']);
        $this->assertFalse($result['is_new_unique_lead']);
        $this->assertFalse($result['limit_exhausted_for_new_lead']);
    }

    public function test_new_number_after_limit_exhausted_is_blocked_for_automation(): void
    {
        CarbonImmutable::setTestNow('2026-04-30 10:00:00');

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $plan = Plan::query()->create([
            'code' => 'starter',
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
            'current_marker' => 1,
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '628211',
            'full_name' => 'Budi',
            'created_at' => CarbonImmutable::parse('2026-04-02 09:00:00'),
            'updated_at' => CarbonImmutable::parse('2026-04-02 09:00:00'),
        ]);

        $result = app(MonthlyUniqueLeadLimitService::class)
            ->evaluate($tenant->id, '628999', 1, CarbonImmutable::parse('2026-04-30 10:00:00'));

        $this->assertSame(1, $result['unique_lead_count']);
        $this->assertTrue($result['is_new_unique_lead']);
        $this->assertTrue($result['limit_exhausted_for_new_lead']);
    }
}
