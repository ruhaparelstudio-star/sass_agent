<?php

namespace App\Modules\Validation\Services;

use App\Modules\Validation\Contracts\ModeValidator;

class ModeValidatorService implements ModeValidator
{
    public function validate(array $candidate, array $context): ?string
    {
        $agentMode = $this->normalizeMode((string) ($context['state']['agent_mode'] ?? 'active'));
        $action = (string) ($candidate['action'] ?? '');

        if ($agentMode === 'active' || $agentMode === 'assistant') {
            return null;
        }

        if ($agentMode === 'paused') {
            return 'mode_paused_blocked';
        }

        if ($agentMode === 'handoff') {
            return 'mode_handoff_blocked';
        }

        if ($agentMode === 'limited') {
            if ($action === 'reply_safe_text') {
                return null;
            }

            return 'mode_limited_blocked_action';
        }

        return 'invalid_mode';
    }

    private function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return match ($normalized) {
            'ai' => 'assistant',
            default => $normalized,
        };
    }
}
