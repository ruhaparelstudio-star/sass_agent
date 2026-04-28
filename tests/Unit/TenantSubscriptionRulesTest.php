<?php

namespace Tests\Unit;

use App\Enums\SubscriptionStatus;
use App\Modules\Plans\Services\PlanService;
use App\Modules\Plans\Services\TenantSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenantSubscriptionRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_value_payload_must_define_only_one_typed_value(): void
    {
        $service = app(PlanService::class);

        $normalized = $service->normalizeFeatureValuePayload([
            'value_string' => null,
            'value_int' => 42,
            'value_bool' => null,
        ]);

        $this->assertSame(42, $normalized['value_int']);
        $this->assertNull($normalized['value_string']);
        $this->assertNull($normalized['value_bool']);

        $this->expectException(HttpException::class);
        $service->normalizeFeatureValuePayload([
            'value_int' => 1,
            'value_bool' => true,
        ]);
    }

    public function test_subscription_status_transition_rules_are_enforced(): void
    {
        $service = app(TenantSubscriptionService::class);

        $this->assertTrue($service->canTransition(SubscriptionStatus::Trial, SubscriptionStatus::Active));
        $this->assertTrue($service->canTransition(SubscriptionStatus::Active, SubscriptionStatus::Cancelled));
        $this->assertFalse($service->canTransition(SubscriptionStatus::Cancelled, SubscriptionStatus::Active));
        $this->assertFalse($service->canTransition(SubscriptionStatus::Expired, SubscriptionStatus::Trial));
    }
}

