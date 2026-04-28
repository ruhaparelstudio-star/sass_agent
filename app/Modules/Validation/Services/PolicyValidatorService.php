<?php

namespace App\Modules\Validation\Services;

use App\Modules\Validation\Contracts\PolicyValidator;

class PolicyValidatorService implements PolicyValidator
{
    public function validate(array $candidate, array $context): ?string
    {
        $action = (string) ($candidate['action'] ?? '');

        if ($action === 'reply_safe_text') {
            return null;
        }

        if (! in_array($action, ['send_pricelist', 'request_booking'], true)) {
            return null;
        }

        $policy = $context['policy'] ?? [];

        $globalError = $this->validateGlobalPolicy($action, $policy);
        if ($globalError !== null) {
            return $globalError;
        }

        $tenantError = $this->validateTenantPolicy($action, $policy);
        if ($tenantError !== null) {
            return $tenantError;
        }

        $businessHoursError = $this->validateBusinessHoursPolicy($policy);
        if ($businessHoursError !== null) {
            return $businessHoursError;
        }

        $followUpError = $this->validateFollowUpPolicy($policy);
        if ($followUpError !== null) {
            return $followUpError;
        }

        $dormantError = $this->validateDormantPolicy($policy);
        if ($dormantError !== null) {
            return $dormantError;
        }

        return null;
    }

    private function validateGlobalPolicy(string $action, array $policy): ?string
    {
        $blockedActions = $policy['global']['blocked_actions'] ?? [];
        if (is_array($blockedActions) && in_array($action, $blockedActions, true)) {
            return 'policy_global_blocked';
        }

        return null;
    }

    private function validateTenantPolicy(string $action, array $policy): ?string
    {
        $blockedActions = $policy['tenant']['blocked_actions'] ?? [];
        if (is_array($blockedActions) && in_array($action, $blockedActions, true)) {
            return 'policy_tenant_blocked';
        }

        return null;
    }

    private function validateBusinessHoursPolicy(array $policy): ?string
    {
        $businessHours = $policy['business_hours'] ?? null;

        if (! is_array($businessHours)) {
            return null;
        }

        $enabled = ($businessHours['enabled'] ?? false) === true;
        if (! $enabled) {
            return null;
        }

        if (! array_key_exists('in_hours', $businessHours)) {
            return 'policy_business_hours_missing';
        }

        if (($businessHours['in_hours'] ?? false) !== true) {
            return 'policy_business_hours_blocked';
        }

        return null;
    }

    private function validateFollowUpPolicy(array $policy): ?string
    {
        $followUp = $policy['follow_up'] ?? null;

        if (! is_array($followUp)) {
            return null;
        }

        $required = ($followUp['required'] ?? false) === true;
        if (! $required) {
            return null;
        }

        if (! array_key_exists('allowed', $followUp)) {
            return 'policy_follow_up_missing';
        }

        if (($followUp['allowed'] ?? false) !== true) {
            return 'policy_follow_up_blocked';
        }

        return null;
    }

    private function validateDormantPolicy(array $policy): ?string
    {
        $dormant = $policy['dormant'] ?? null;

        if (! is_array($dormant)) {
            return null;
        }

        $required = ($dormant['required'] ?? false) === true;
        if (! $required) {
            return null;
        }

        if (! array_key_exists('allowed', $dormant)) {
            return 'policy_dormant_missing';
        }

        if (($dormant['allowed'] ?? false) !== true) {
            return 'policy_dormant_blocked';
        }

        return null;
    }
}
