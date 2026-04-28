<?php

namespace Tests\Unit\Validation;

use App\Modules\Validation\Services\ActionPermissionValidatorService;
use Tests\TestCase;

class ActionPermissionValidatorServiceTest extends TestCase
{
    public function test_blocks_action_when_explicitly_blocked(): void
    {
        $validator = new ActionPermissionValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_pricelist'],
            [
                'permissions' => [
                    'blocked_actions' => ['send_pricelist'],
                    'allowed_actions' => ['send_pricelist'],
                ],
            ]
        );

        $this->assertSame('permission_action_blocked', $reason);
    }

    public function test_allows_sensitive_action_when_in_allowed_actions(): void
    {
        $validator = new ActionPermissionValidatorService;

        $reason = $validator->validate(
            ['action' => 'request_booking'],
            [
                'permissions' => [
                    'allowed_actions' => ['request_booking'],
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_blocks_sensitive_action_when_permission_context_missing(): void
    {
        $validator = new ActionPermissionValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_invoice'],
            []
        );

        $this->assertSame('permission_context_missing', $reason);
    }

    public function test_blocks_sensitive_action_when_not_in_allowed_actions(): void
    {
        $validator = new ActionPermissionValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_pricelist'],
            [
                'permissions' => [
                    'allowed_actions' => ['request_booking'],
                ],
            ]
        );

        $this->assertSame('permission_action_not_allowed', $reason);
    }

    public function test_reply_safe_text_is_always_allowed(): void
    {
        $validator = new ActionPermissionValidatorService;

        $reason = $validator->validate(
            ['action' => 'reply_safe_text'],
            [
                'permissions' => [
                    'blocked_actions' => ['reply_safe_text'],
                    'allowed_actions' => [],
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_returns_first_reason_deterministically_when_both_lists_present(): void
    {
        $validator = new ActionPermissionValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_pricelist'],
            [
                'permissions' => [
                    'blocked_actions' => ['send_pricelist'],
                    'allowed_actions' => [],
                ],
            ]
        );

        $this->assertSame('permission_action_blocked', $reason);
    }
}
