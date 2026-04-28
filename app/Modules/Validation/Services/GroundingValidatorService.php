<?php

namespace App\Modules\Validation\Services;

use App\Modules\Validation\Contracts\GroundingValidator;

class GroundingValidatorService implements GroundingValidator
{
    public function validate(array $candidate, array $context): ?string
    {
        $reasons = $candidate['reasons'] ?? [];

        if (! is_array($reasons) || $reasons === []) {
            return null;
        }

        return (string) $reasons[0];
    }
}
