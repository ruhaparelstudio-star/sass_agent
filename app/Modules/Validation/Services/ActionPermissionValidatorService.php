<?php

namespace App\Modules\Validation\Services;

use App\Modules\Validation\Contracts\ActionPermissionValidator;

class ActionPermissionValidatorService implements ActionPermissionValidator
{
    public function validate(array $candidate, array $context): ?string
    {
        return null;
    }
}
