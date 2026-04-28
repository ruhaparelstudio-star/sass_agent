<?php

namespace App\Modules\Validation\Services;

use App\Modules\Validation\Contracts\PolicyValidator;

class PolicyValidatorService implements PolicyValidator
{
    public function validate(array $candidate, array $context): ?string
    {
        return null;
    }
}
