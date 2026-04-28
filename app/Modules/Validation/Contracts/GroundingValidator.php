<?php

namespace App\Modules\Validation\Contracts;

interface GroundingValidator
{
    public function validate(array $candidate, array $context): ?string;
}
