<?php

namespace App\Modules\Validation\Contracts;

interface PolicyValidator
{
    public function validate(array $candidate, array $context): ?string;
}
