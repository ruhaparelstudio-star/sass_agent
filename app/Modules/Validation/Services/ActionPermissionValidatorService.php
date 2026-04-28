<?php

namespace App\Modules\Validation\Services;

use App\Modules\Validation\Contracts\ActionPermissionValidator;

class ActionPermissionValidatorService implements ActionPermissionValidator
{
    public function validate(array $candidate, array $context): ?string
    {
        $action = (string) ($candidate['action'] ?? '');

        if ($action === 'reply_safe_text') {
            return null;
        }

        $permissions = $context['permissions'] ?? null;
        $blockedActions = is_array($permissions) ? ($permissions['blocked_actions'] ?? []) : [];

        if (is_array($blockedActions) && in_array($action, $blockedActions, true)) {
            return 'permission_action_blocked';
        }

        if (! $this->isSensitiveAction($action)) {
            return null;
        }

        $allowedActions = is_array($permissions) ? ($permissions['allowed_actions'] ?? null) : null;

        if (! is_array($allowedActions)) {
            return 'permission_context_missing';
        }

        if (! in_array($action, $allowedActions, true)) {
            return 'permission_action_not_allowed';
        }

        return null;
    }

    private function isSensitiveAction(string $action): bool
    {
        return in_array($action, ['send_pricelist', 'request_booking', 'send_invoice'], true);
    }
}
