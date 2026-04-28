<?php

namespace App\Modules\Validation\Contracts;

interface ModeValidator
{
    public function validate(array $candidate, array $context): ?string;
}
