<?php

namespace Tests\Unit\CoreEngine;

use App\Models\Tenant;
use App\Modules\CoreEngine\Services\ResponseComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponseComposerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Studio Aurora',
            'slug' => 'studio-aurora',
            'is_active' => true,
        ]);
    }

    private function compose(array $decision, array $grounding = [], array $entities = []): string
    {
        return app(ResponseComposerService::class)->compose($decision, $grounding, $entities, $this->tenant());
    }

    public function test_opening_goal_includes_tenant_name(): void
    {
        $msg = $this->compose(['active_goal' => 'opening']);
        $this->assertStringContainsString('Studio Aurora', $msg);
    }

    public function test_qualification_goal_uses_next_required_field(): void
    {
        $msg = $this->compose([
            'active_goal' => 'qualification',
            'action_candidates' => [
                'allowed' => [],
                'blocked' => [['action' => 'reply_text', 'reasons' => [], 'meta' => ['next_required' => 'customer_name']]],
            ],
        ]);
        $this->assertStringContainsString('nama', strtolower($msg));
    }

    public function test_pricing_blocked_missing_name_asks_for_name(): void
    {
        $msg = $this->compose([
            'active_goal' => 'pricing',
            'action_candidates' => [
                'allowed' => [],
                'blocked' => [['action' => 'send_pricelist_file', 'reasons' => ['missing_name']]],
            ],
        ]);
        $this->assertStringContainsString('nama', strtolower($msg));
    }

    public function test_pricing_allowed_send_file_returns_pricelist_message(): void
    {
        $msg = $this->compose([
            'active_goal' => 'pricing',
            'action_candidates' => [
                'allowed' => [['action' => 'send_pricelist_file']],
                'blocked' => [],
            ],
        ]);
        $this->assertStringContainsString('pricelist', strtolower($msg));
    }

    public function test_booking_allowed_uses_grounding_link(): void
    {
        $msg = $this->compose(
            [
                'active_goal' => 'booking',
                'action_candidates' => [
                    'allowed' => [['action' => 'send_booking_link']],
                    'blocked' => [],
                ],
            ],
            ['booking_link' => ['data' => ['booking_url' => 'https://book.example.com/abc']]],
        );
        $this->assertStringContainsString('https://book.example.com/abc', $msg);
    }

    public function test_handoff_goal_returns_handoff_message(): void
    {
        $msg = $this->compose(['active_goal' => 'handoff']);
        $this->assertStringContainsString('tim kami', strtolower($msg));
    }

    public function test_clarification_default_when_goal_missing(): void
    {
        $msg = $this->compose([]);
        $this->assertNotSame('', trim($msg));
        $this->assertStringContainsString('maaf', strtolower($msg));
    }

    public function test_objection_price_returns_value_oriented_response(): void
    {
        $msg = $this->compose([
            'active_goal' => 'objection_handling',
            'intent' => 'objection_price',
        ]);
        $this->assertStringContainsString('budget', strtolower($msg));
    }
}
