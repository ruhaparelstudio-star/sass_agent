<?php

namespace Tests\Feature;

use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_env_example_sets_non_sync_queue_connection(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertIsString($envExample);
        $this->assertStringContainsString('QUEUE_CONNECTION=redis', $envExample);
        $this->assertStringNotContainsString('QUEUE_CONNECTION=sync', $envExample);
    }

    public function test_queue_default_connection_uses_environment_value(): void
    {
        $expected = (string) env('QUEUE_CONNECTION', 'sync');

        $this->assertSame($expected, config('queue.default'));
    }
}
