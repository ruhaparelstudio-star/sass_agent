<?php

namespace Tests\Unit\AiLayer;

use App\Modules\AiLayer\Services\OpenAiLlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiLlmClientTest extends TestCase
{
    public function test_openai_adapter_returns_content_and_metadata(): void
    {
        config()->set('ai.provider', 'openai');
        config()->set('ai.openai.api_key', 'test-key');
        config()->set('ai.openai.base_url', 'https://api.openai.com/v1');
        config()->set('ai.openai.model', 'gpt-4o-mini');
        config()->set('ai.openai.timeout_seconds', 10);
        config()->set('ai.openai.temperature', 0);
        config()->set('ai.openai.max_output_tokens', 300);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"intent":"ask_package","confidence":0.91,"entities":{"package_query":"gold"}}',
                        ],
                    ],
                ],
                'usage' => [
                    'total_tokens' => 123,
                ],
            ], 200),
        ]);

        $response = app(OpenAiLlmClient::class)->complete(1, 'halo', 'extract intent');

        $this->assertSame('gpt-4o-mini', $response->model);
        $this->assertSame(123, $response->totalTokens);
        $this->assertSame('{"intent":"ask_package","confidence":0.91,"entities":{"package_query":"gold"}}', $response->content);
    }
}
