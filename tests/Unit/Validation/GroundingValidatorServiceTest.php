<?php

namespace Tests\Unit\Validation;

use App\Modules\Validation\Services\GroundingValidatorService;
use Tests\TestCase;

class GroundingValidatorServiceTest extends TestCase
{
    public function test_blocks_send_file_when_required_grounding_is_missing(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'grounding' => [
                    'file' => ['is_grounded' => false],
                ],
            ]
        );

        $this->assertSame('grounding_file_missing_source', $reason);
    }

    public function test_allows_send_file_when_pricelist_asset_is_grounded(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'grounding' => [
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
            ['action' => 'send_booking_link'],
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
            ['action' => 'send_booking_link'],
            [
                'tenant_id' => 999,
                'policy' => [
                    'global' => ['blocked_actions' => ['send_booking_link']],
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

    public function test_blocks_booking_link_when_calendar_not_grounded(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_booking_link'],
            [
                'grounding' => [
                    'package' => ['is_grounded' => true],
                    'calendar' => ['is_grounded' => false, 'reason' => 'calendar_provider_error'],
                ],
            ]
        );

        $this->assertSame('grounding_calendar_missing_source', $reason);
    }

    public function test_send_file_does_not_require_price_or_package_grounding(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'grounding' => [
                    'price' => ['is_grounded' => false],
                    'package' => ['is_grounded' => false],
                    'file' => ['is_grounded' => true],
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_blocks_send_file_when_pricelist_asset_is_not_grounded(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'grounding' => [
                    'file' => ['is_grounded' => false],
                ],
            ]
        );

        $this->assertSame('grounding_file_missing_source', $reason);
    }

    public function test_blocks_send_invoice_when_invoice_claim_is_not_grounded(): void
    {
        $validator = new GroundingValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_invoice'],
            [
                'grounding' => [
                    'invoice' => ['is_grounded' => false],
                    'price' => ['is_grounded' => true],
                    'file' => ['is_grounded' => true],
                ],
            ]
        );

        $this->assertSame('grounding_invoice_missing_source', $reason);
    }
}
