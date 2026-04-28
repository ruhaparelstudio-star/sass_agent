<?php

namespace App\Modules\AiLayer\Services;

use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiLlmClient implements LlmClientContract
{
    public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
    {
        $apiKey = (string) config('ai.openai.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $baseUrl = rtrim((string) config('ai.openai.base_url', 'https://api.openai.com/v1'), '/');
        $endpoint = $baseUrl.'/chat/completions';

        $response = Http::timeout((int) config('ai.openai.timeout_seconds', 30))
            ->withToken($apiKey)
            ->post($endpoint, [
                'model' => (string) config('ai.openai.model', 'gpt-4o'),
                'temperature' => (float) config('ai.openai.temperature', 0.3),
                'max_tokens' => (int) config('ai.openai.max_output_tokens', 600),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $instruction,
                    ],
                    [
                        'role' => 'user',
                        'content' => $userMessage,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI completion request failed.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('OpenAI response is invalid.');
        }

        $content = data_get($body, 'choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenAI response content is empty.');
        }

        $model = data_get($body, 'model');
        $totalTokens = data_get($body, 'usage.total_tokens');

        return new LlmResponse(
            content: $content,
            model: is_string($model) ? $model : '',
            totalTokens: is_numeric($totalTokens) ? (int) $totalTokens : 0,
            raw: $body,
        );
    }
}
