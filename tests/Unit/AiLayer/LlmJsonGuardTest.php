<?php

namespace Tests\Unit\AiLayer;

use App\Modules\AiLayer\Services\LlmJsonGuard;
use Tests\TestCase;

class LlmJsonGuardTest extends TestCase
{
    public function test_valid_json_payload_is_sanitized(): void
    {
        $guard = app(LlmJsonGuard::class);

        $result = $guard->sanitize('{"intent":"ask_price","confidence":0.9,"entities":{"package_query":"gold"}}');

        $this->assertTrue($result['ok']);
        $this->assertSame('{"intent":"ask_price","confidence":0.9,"entities":{"package_query":"gold"}}', $result['json']);
        $this->assertNull($result['reason']);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $guard = app(LlmJsonGuard::class);

        $result = $guard->sanitize('{"intent":"ask_price"');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['json']);
        $this->assertSame('invalid_json', $result['reason']);
    }

    public function test_missing_required_top_level_keys_is_rejected(): void
    {
        $guard = app(LlmJsonGuard::class);

        $result = $guard->sanitize('{"intent":"ask_price","confidence":0.9}');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['json']);
        $this->assertSame('invalid_entities', $result['reason']);
    }
}
