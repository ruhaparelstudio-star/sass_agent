<?php

namespace App\Modules\Validation\Services;

use App\Modules\Validation\Contracts\GroundingValidator;

class GroundingValidatorService implements GroundingValidator
{
    public function validate(array $candidate, array $context): ?string
    {
        $action = (string) ($candidate['action'] ?? '');
        $requiredClaims = $this->requiredClaimsByAction($action);

        if ($requiredClaims === []) {
            return null;
        }

        $grounding = $context['grounding'] ?? [];

        foreach ($requiredClaims as $claim) {
            if (! $this->isClaimGrounded($claim, $grounding)) {
                return 'grounding_'.$claim.'_missing_source';
            }
        }

        return null;
    }

    private function requiredClaimsByAction(string $action): array
    {
        return match ($action) {
            'send_file' => ['price', 'package', 'file'],
            'send_booking_link' => ['package', 'calendar'],
            'send_invoice' => ['invoice', 'price', 'file'],
            default => [],
        };
    }

    private function isClaimGrounded(string $claim, array $grounding): bool
    {
        if (! array_key_exists($claim, $grounding)) {
            return false;
        }

        $value = $grounding[$claim];

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return ($value['is_grounded'] ?? false) === true;
        }

        return false;
    }
}
