<?php

namespace Tests\Unit\Validation;

use App\Modules\Validation\Services\ModeValidatorService;
use Tests\TestCase;

class ModeValidatorServiceTest extends TestCase
{
    public function test_active_mode_allows_sensitive_action(): void
    {
        $validator = new ModeValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'state' => [
                    'agent_mode' => 'active',
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_assistant_mode_is_treated_as_active_for_backward_compatibility(): void
    {
        $validator = new ModeValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'state' => [
                    'agent_mode' => 'assistant',
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_ai_mode_alias_is_treated_as_assistant_for_backward_compatibility(): void
    {
        $validator = new ModeValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'state' => [
                    'agent_mode' => 'ai',
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_limited_mode_blocks_sensitive_action(): void
    {
        $validator = new ModeValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'state' => [
                    'agent_mode' => 'limited',
                ],
            ]
        );

        $this->assertSame('mode_limited_blocked_action', $reason);
    }

    public function test_limited_mode_allows_reply_safe_text(): void
    {
        $validator = new ModeValidatorService;

        $reason = $validator->validate(
            ['action' => 'reply_safe_text'],
            [
                'state' => [
                    'agent_mode' => 'limited',
                ],
            ]
        );

        $this->assertNull($reason);
    }

    public function test_paused_mode_blocks_all_actions_including_reply_safe_text(): void
    {
        $validator = new ModeValidatorService;

        $sensitiveActionReason = $validator->validate(
            ['action' => 'send_file'],
            [
                'state' => [
                    'agent_mode' => 'paused',
                ],
            ]
        );

        $safeActionReason = $validator->validate(
            ['action' => 'reply_safe_text'],
            [
                'state' => [
                    'agent_mode' => 'paused',
                ],
            ]
        );

        $this->assertSame('mode_paused_blocked', $sensitiveActionReason);
        $this->assertSame('mode_paused_blocked', $safeActionReason);
    }

    public function test_handoff_mode_blocks_all_actions_including_reply_safe_text(): void
    {
        $validator = new ModeValidatorService;

        $sensitiveActionReason = $validator->validate(
            ['action' => 'send_file'],
            [
                'state' => [
                    'agent_mode' => 'handoff',
                ],
            ]
        );

        $safeActionReason = $validator->validate(
            ['action' => 'reply_safe_text'],
            [
                'state' => [
                    'agent_mode' => 'handoff',
                ],
            ]
        );

        $this->assertSame('mode_handoff_blocked', $sensitiveActionReason);
        $this->assertSame('mode_handoff_blocked', $safeActionReason);
    }

    public function test_unknown_mode_returns_invalid_mode_reason_deterministically(): void
    {
        $validator = new ModeValidatorService;

        $reason = $validator->validate(
            ['action' => 'send_file'],
            [
                'state' => [
                    'agent_mode' => 'experimental',
                ],
            ]
        );

        $this->assertSame('invalid_mode', $reason);
    }
}
