<?php

namespace Tests\Unit\WhatsApp;

use App\Enums\WaAccountStatus;
use App\Enums\WaSessionStatus;
use App\Modules\WhatsApp\Services\WaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_transition_rules_are_enforced(): void
    {
        $service = app(WaSyncService::class);

        $this->assertTrue($service->canTransitionAccountStatus(WaAccountStatus::Disconnected, WaAccountStatus::Connecting));
        $this->assertTrue($service->canTransitionAccountStatus(WaAccountStatus::Connecting, WaAccountStatus::Connected));
        $this->assertFalse($service->canTransitionAccountStatus(WaAccountStatus::Connected, WaAccountStatus::Connecting));
    }

    public function test_session_transition_rules_are_enforced(): void
    {
        $service = app(WaSyncService::class);

        $this->assertTrue($service->canTransitionSessionStatus(WaSessionStatus::Pending, WaSessionStatus::Active));
        $this->assertTrue($service->canTransitionSessionStatus(WaSessionStatus::Active, WaSessionStatus::Closed));
        $this->assertFalse($service->canTransitionSessionStatus(WaSessionStatus::Closed, WaSessionStatus::Active));
    }

    public function test_payload_must_be_array(): void
    {
        $service = app(WaSyncService::class);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->assertPayloadContract('not-array');
    }
}
