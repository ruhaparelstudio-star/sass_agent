<?php

namespace Tests\Unit\Validation;

use App\Modules\Validation\Services\GroundingValidatorService;
use Tests\TestCase;

class GroundingValidatorServiceTest extends TestCase
{
    public function test_blocks_send_pricelist_when_required_grounding_is_missing(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_pricelist'],
            [
                'grounding' => [
                    'price' => ['is_grounded' => true],
                    'package' => ['is_grounded' => false],
                    'file' => ['is_grounded' => true],
                ],
            ]
        );

        $this->assertSame('grounding_package_missing_source', $reason);
    }

    public function test_allows_send_pricelist_when_all_required_grounding_exists(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_pricelist'],
            [
                'grounding' => [
                    'price' => ['is_grounded' => true],
                    'package' => ['is_grounded' => true],
                    'file' => ['is_grounded' => true],
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_returns_first_failure_reason_deterministically(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'request_booking'],
            [
                'grounding' => [
                    'package' => ['is_grounded' => false],
                    'calendar' => ['is_grounded' => false],
                ],
            ]
        );

        $this->assertSame('grounding_package_missing_source', $reason);
    }

    public function test_is_pure_context_driven_and_does_not_depend_on_tenant_policy_data(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'request_booking'],
            [
                'tenant_id' => 999,
                'policy' => [
                    'global' => ['blocked_actions' => ['request_booking']],
                ],
                'grounding' => [
                    'package' => ['is_grounded' => true],
                    'calendar' => ['is_grounded' => true],
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_non_sensitive_action_is_allowed_without_grounding_context(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'reply_safe_text'],
            []
        );

        $this->assertNull($reason);
    }
}
