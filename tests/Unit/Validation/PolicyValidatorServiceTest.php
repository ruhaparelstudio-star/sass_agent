<?php

namespace Tests\Unit\Validation;

use App\Modules\Validation\Services\PolicyValidatorService;
use Tests\TestCase;

class PolicyValidatorServiceTest extends TestCase
{
    public function test_blocks_unsafe_action_by_global_policy(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'policy' => [
                    'global' => [
                        'blocked_actions' => ['send_file'],
                    ],
                ],
            ]
        );

        $this->assertSame('policy_global_blocked', $reason);
    }

    public function test_blocks_unsafe_action_by_tenant_policy(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_booking_link'],
            [
                'policy' => [
                    'tenant' => [
                        'blocked_actions' => ['send_booking_link'],
                    ],
                ],
            ]
        );

        $this->assertSame('policy_tenant_blocked', $reason);
    }

    public function test_allows_safe_action_with_valid_policies(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'policy' => [
                    'global' => [
                        'blocked_actions' => ['send_booking_link'],
                    ],
                    'tenant' => [
                        'blocked_actions' => [],
                    ],
                    'business_hours' => [
                        'enabled' => true,
                        'in_hours' => true,
                    ],
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_blocks_action_outside_business_hours(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'policy' => [
                    'business_hours' => [
                        'enabled' => true,
                        'in_hours' => false,
                    ],
                ],
            ]
        );

        $this->assertSame('policy_business_hours_blocked', $reason);
    }

    public function test_blocks_action_when_follow_up_disallowed(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'policy' => [
                    'follow_up' => [
                        'required' => true,
                        'allowed' => false,
                    ],
                ],
            ]
        );

        $this->assertSame('policy_follow_up_blocked', $reason);
    }

    public function test_blocks_action_when_dormant_rule_restricts_it(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_booking_link'],
            [
                'policy' => [
                    'dormant' => [
                        'required' => true,
                        'allowed' => false,
                    ],
                ],
            ]
        );

        $this->assertSame('policy_dormant_blocked', $reason);
    }

    public function test_returns_first_failure_reason_by_policy_order(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'policy' => [
                    'global' => [
                        'blocked_actions' => ['send_file'],
                    ],
                    'tenant' => [
                        'blocked_actions' => ['send_file'],
                    ],
                    'business_hours' => [
                        'enabled' => true,
                        'in_hours' => false,
                    ],
                ],
            ]
        );

        $this->assertSame('policy_global_blocked', $reason);
    }

    public function test_reply_safe_text_is_always_allowed(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'reply_safe_text'],
            [
                'policy' => [
                    'global' => [
                        'blocked_actions' => ['reply_safe_text'],
                    ],
                    'business_hours' => [
                        'enabled' => true,
                        'in_hours' => false,
                    ],
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_blocks_send_invoice_when_invoice_cap_is_exceeded(): void
    {
        $validator = new PolicyValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_invoice'],
            [
                'policy' => [
                    'invoice' => [
                        'max_count_enabled' => true,
                        'max_count' => 3,
                        'sent_count' => 3,
                    ],
                ],
            ]
        );

        $this->assertSame('policy_invoice_cap_exceeded', $reason);
    }
}
