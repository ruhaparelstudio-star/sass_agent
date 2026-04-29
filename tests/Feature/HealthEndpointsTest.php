<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthEndpointsTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/health');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_db_health_endpoint_is_not_publicly_accessible(): void
    {
        Config::set('whatsapp.internal_secret', 'health-secret');

        $this->getJson('/health/db')->assertForbidden();
    }

    public function test_redis_health_endpoint_is_not_publicly_accessible(): void
    {
        Config::set('whatsapp.internal_secret', 'health-secret');

        $this->getJson('/health/redis')->assertForbidden();
    }

    public function test_db_health_endpoint_returns_ok_for_internal_caller_with_valid_secret(): void
    {
        Config::set('whatsapp.internal_secret', 'health-secret');

        $response = $this->withHeader('X-Internal-Secret', 'health-secret')->getJson('/health/db');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'db',
            ]);
    }

    public function test_redis_health_endpoint_returns_ok_for_internal_caller_with_valid_secret(): void
    {
        Config::set('whatsapp.internal_secret', 'health-secret');

        Redis::shouldReceive('connection->ping')
            ->once()
            ->andReturn('PONG');

        $response = $this->withHeader('X-Internal-Secret', 'health-secret')->getJson('/health/redis');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'redis',
            ]);
    }
}
