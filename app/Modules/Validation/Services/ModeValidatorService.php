<?php

namespace App\Modules\Validation\Services;

use App\Modules\Validation\Contracts\ModeValidator;

class ModeValidatorService implements ModeValidator
{
    public function validate(array $candidate, array $context): ?string
    {
        $agentMode = (string) ($context['state']['agent_mode'] ?? 'assistant');

        if ($agentMode !== 'assistant') {
            return 'invalid_mode';
        }

        return null;
    }
}
