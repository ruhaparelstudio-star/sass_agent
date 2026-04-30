<?php

namespace App\Modules\Plans\Services;

use App\Enums\SubscriptionStatus;
use App\Models\TenantSubscription;

class FeatureGateService
{
    /**
     * @return array{wa_agent_limit:int,lead_limit:int,calendar_access:bool,automation_enabled:bool}
     */
    public function resolveForTenant(?int $tenantId): array
    {
        $defaults = $this->defaults();

        if ($tenantId === null) {
            return $defaults;
        }

        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('current_marker', 1)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->with('plan.features')
            ->first();

        if (! $subscription || ! $subscription->plan) {
            return $defaults;
        }

        $features = $subscription->plan->features->keyBy('code');
        $calendarAccess = $this->resolveFeatureBool($features, ['calendar_access', 'calendar_enabled']) ?? false;
        $automationEnabled = $this->resolveFeatureBool($features, ['automation_enabled', 'automation']);
        if ($automationEnabled === null) {
            // Backward compatibility: legacy plans may not have this flag yet.
            $automationEnabled = true;
        }

        return [
            'wa_agent_limit' => $this->resolveIntValue($features->get('wa_agent_limit')?->value_int),
            'lead_limit' => $this->resolveIntValue($features->get('lead_limit')?->value_int),
            'calendar_access' => $calendarAccess,
            'automation_enabled' => $automationEnabled,
        ];
    }

    /**
     * @return array{wa_agent_limit:int,lead_limit:int,calendar_access:bool,automation_enabled:bool}
     */
    private function defaults(): array
    {
        return [
            'wa_agent_limit' => 0,
            'lead_limit' => 0,
            'calendar_access' => false,
            'automation_enabled' => false,
        ];
    }

    private function resolveIntValue(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private function resolveBoolValue(mixed $value): bool
    {
        return is_bool($value) ? $value : false;
    }

    private function resolveFeatureBool(\Illuminate\Support\Collection $features, array $codes): ?bool
    {
        foreach ($codes as $code) {
            $feature = $features->get($code);
            if ($feature === null) {
                continue;
            }

            if (is_bool($feature->value_bool)) {
                return $feature->value_bool;
            }
        }

        return null;
    }
}
