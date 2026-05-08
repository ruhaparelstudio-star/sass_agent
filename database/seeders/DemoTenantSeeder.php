<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Provides minimal data so a tenant_admin user can log in and reach
 * /tenant/dashboard end-to-end:
 *   - one active Plan with the four feature flags expected by FeatureGateService
 *   - one Tenant with an active TenantSubscription on that plan
 *   - the test@example.com user (created or upserted) linked via tenant_users
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::query()->updateOrCreate(
            ['slug' => 'starter'],
            ['name' => 'Starter', 'is_active' => true],
        );

        $features = [
            ['code' => 'wa_agent_limit',     'name' => 'WhatsApp Agent Limit',  'value_int' => 1,    'value_bool' => null, 'value_string' => null],
            ['code' => 'lead_limit',         'name' => 'Monthly Lead Limit',    'value_int' => 1000, 'value_bool' => null, 'value_string' => null],
            ['code' => 'calendar_access',    'name' => 'Calendar Access',       'value_int' => null, 'value_bool' => true, 'value_string' => null],
            ['code' => 'automation_enabled', 'name' => 'Automation Enabled',    'value_int' => null, 'value_bool' => true, 'value_string' => null],
        ];
        foreach ($features as $f) {
            PlanFeature::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'code' => $f['code']],
                [
                    'name' => $f['name'],
                    'value_int' => $f['value_int'],
                    'value_bool' => $f['value_bool'],
                    'value_string' => $f['value_string'],
                ],
            );
        }

        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => 'demo'],
            ['name' => 'Demo Studio', 'is_active' => true],
        );

        // Use a transaction so the unique (tenant_id, current_marker=1) invariant
        // can't be temporarily violated when an old row exists.
        DB::transaction(function () use ($tenant, $plan): void {
            TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->where('current_marker', 1)
                ->update(['current_marker' => null]);

            TenantSubscription::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active->value],
                [
                    'starts_at' => now(),
                    'ends_at' => null,
                    'current_marker' => 1,
                ],
            );
        });

        $user = User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'role' => UserRole::TenantAdmin,
                'email_verified_at' => now(),
            ],
        );

        $user->tenants()->syncWithoutDetaching([$tenant->id]);
    }
}
