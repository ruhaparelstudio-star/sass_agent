<?php

namespace App\Modules\Validation\Contracts;

interface ActionPermissionValidator
{
    public function validate(array $candidate, array $context): ?string;
}
