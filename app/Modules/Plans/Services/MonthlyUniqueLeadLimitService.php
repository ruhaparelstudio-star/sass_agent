<?php

namespace App\Modules\Plans\Services;

use App\Enums\SubscriptionStatus;
use App\Models\LeadProfile;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;

class MonthlyUniqueLeadLimitService
{
    /**
     * @return array{
     *   period_start:string,
     *   period_end:string,
     *   unique_lead_count:int,
     *   is_new_unique_lead:bool,
     *   limit:int,
     *   limit_exhausted_for_new_lead:bool
     * }
     */
    public function evaluate(int $tenantId, string $customerPhone, int $limit, ?CarbonImmutable $at = null): array
    {
        $at = $at ?? CarbonImmutable::now();
        [$periodStart, $periodEnd] = $this->resolveCurrentBillingPeriod($tenantId, $at);

        $uniqueLeadCount = (int) LeadProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->distinct('customer_phone')
            ->count('customer_phone');

        $isExistingInPeriod = LeadProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $customerPhone)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->exists();

        $isNewUniqueLead = ! $isExistingInPeriod;
        $limitExhaustedForNewLead = $limit > 0 && $isNewUniqueLead && $uniqueLeadCount >= $limit;

        return [
            'period_start' => $periodStart->toIso8601String(),
            'period_end' => $periodEnd->toIso8601String(),
            'unique_lead_count' => $uniqueLeadCount,
            'is_new_unique_lead' => $isNewUniqueLead,
            'limit' => $limit,
            'limit_exhausted_for_new_lead' => $limitExhaustedForNewLead,
        ];
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function resolveCurrentBillingPeriod(int $tenantId, CarbonImmutable $at): array
    {
        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('current_marker', 1)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->first();

        if (! $subscription || $subscription->starts_at === null) {
            $start = $at->startOfMonth();

            return [$start, $start->addMonthNoOverflow()];
        }

        $periodStart = CarbonImmutable::instance($subscription->starts_at);
        while ($periodStart->addMonthNoOverflow()->lte($at)) {
            $periodStart = $periodStart->addMonthNoOverflow();
        }

        return [$periodStart, $periodStart->addMonthNoOverflow()];
    }
}
