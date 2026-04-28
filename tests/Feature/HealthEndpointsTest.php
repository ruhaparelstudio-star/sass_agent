<?php

namespace Tests\Feature;

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

    public function test_db_health_endpoint_returns_ok_when_db_is_reachable(): void
    {
        $response = $this->getJson('/health/db');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'db',
            ]);
    }

    public function test_redis_health_endpoint_returns_ok_when_redis_is_reachable(): void
    {
        Redis::shouldReceive('connection->ping')
            ->once()
            ->andReturn('PONG');

        $response = $this->getJson('/health/redis');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'redis',
            ]);
    }
}
