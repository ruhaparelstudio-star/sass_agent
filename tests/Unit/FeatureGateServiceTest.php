<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Modules\Plans\Services\FeatureGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureGateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_fail_closed_defaults_without_eligible_current_subscription(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $gates = app(FeatureGateService::class)->resolveForTenant($tenant->id);

        $this->assertSame([
            'wa_agent_limit' => 0,
            'lead_limit' => 0,
            'calendar_access' => false,
            'automation_enabled' => false,
        ], $gates);
    }

    public function test_it_resolves_from_current_active_or_trial_subscription_and_ignores_other_tenants(): void
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

        $plan = Plan::query()->create([
            'name' => 'Pro',
            'slug' => 'pro',
            'is_active' => true,
        ]);
        $plan->features()->createMany([
            ['code' => 'wa_agent_limit', 'name' => 'WA Agent Limit', 'value_int' => 5],
            ['code' => 'lead_limit', 'name' => 'Lead Limit', 'value_int' => 2000],
            ['code' => 'calendar_access', 'name' => 'Calendar Access', 'value_bool' => true],
            ['code' => 'automation_enabled', 'name' => 'Automation Enabled', 'value_bool' => true],
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
            'starts_at' => now(),
            'current_marker' => 1,
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $otherTenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_marker' => 1,
        ]);

        $gates = app(FeatureGateService::class)->resolveForTenant($tenant->id);

        $this->assertSame([
            'wa_agent_limit' => 5,
            'lead_limit' => 2000,
            'calendar_access' => true,
            'automation_enabled' => true,
        ], $gates);
    }

    public function test_it_uses_backward_compatible_defaults_when_active_subscription_has_legacy_feature_shape(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);
        $plan->features()->createMany([
            ['code' => 'wa_agent_limit', 'name' => 'WA Agent Limit', 'value_string' => 'bad-type'],
            ['code' => 'calendar_enabled', 'name' => 'Calendar Enabled', 'value_bool' => true],
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_marker' => 1,
        ]);

        $gates = app(FeatureGateService::class)->resolveForTenant($tenant->id);

        $this->assertSame([
            'wa_agent_limit' => 0,
            'lead_limit' => 0,
            'calendar_access' => true,
            'automation_enabled' => true,
        ], $gates);
    }

    public function test_it_respects_explicit_automation_flag_when_present(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'slug' => 'starter',
            'is_active' => true,
        ]);
        $plan->features()->createMany([
            ['code' => 'wa_agent_limit', 'name' => 'WA Agent Limit', 'value_int' => 1],
            ['code' => 'automation_enabled', 'name' => 'Automation Enabled', 'value_bool' => false],
        ]);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_marker' => 1,
        ]);

        $gates = app(FeatureGateService::class)->resolveForTenant($tenant->id);

        $this->assertSame([
            'wa_agent_limit' => 1,
            'lead_limit' => 0,
            'calendar_access' => false,
            'automation_enabled' => false,
        ], $gates);
    }
}
