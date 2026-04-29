<?php

namespace App\Modules\AdminUi\Services;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;

class SuperadminPlanManagementQueryService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPlans(): array
    {
        return Plan::query()
            ->with(['features' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getTenantsWithCurrentSubscription(): array
    {
        $tenants = Tenant::query()
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'is_active'])
            ->toArray();

        $currentSubscriptions = TenantSubscription::query()
            ->with('plan:id,name,slug')
            ->where('current_marker', 1)
            ->get()
            ->keyBy('tenant_id');

        return array_map(function (array $tenant) use ($currentSubscriptions): array {
            /** @var TenantSubscription|null $current */
            $current = $currentSubscriptions->get($tenant['id']);

            return array_merge($tenant, [
                'current_subscription' => $current ? [
                    'id' => $current->id,
                    'status' => $current->status->value,
                    'starts_at' => $current->starts_at?->toDateTimeString(),
                    'ends_at' => $current->ends_at?->toDateTimeString(),
                    'plan' => $current->plan ? [
                        'id' => $current->plan->id,
                        'name' => $current->plan->name,
                        'slug' => $current->plan->slug,
                    ] : null,
                ] : null,
            ]);
        }, $tenants);
    }
}
