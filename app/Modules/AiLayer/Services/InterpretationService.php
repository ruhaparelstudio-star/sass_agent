<?php

namespace App\Modules\AiLayer\Services;

use App\Modules\AiLayer\Contracts\IntentClassifierContract;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\InterpretationResult;
use Throwable;

class InterpretationService
{
    public function __construct(
        private readonly LlmClientContract $llmClient,
        private readonly LlmJsonGuard $jsonGuard,
        private readonly IntentClassifierContract $classifier,
    ) {}

    public function interpret(int $tenantId, string $userMessage, string $instruction): InterpretationResult
    {
        try {
            $llmResponse = $this->llmClient->complete($tenantId, $userMessage, $instruction);
        } catch (Throwable $e) {
            return InterpretationResult::safeFallback('provider_error', [
                'user_message' => $userMessage,
                'error' => $e->getMessage(),
            ]);
        }

        $guarded = $this->jsonGuard->sanitize($llmResponse->content);

        if (($guarded['ok'] ?? false) !== true || ! is_string($guarded['json'] ?? null)) {
            return $this->classifier->fallbackFromMessage($tenantId, $userMessage, (string) ($guarded['reason'] ?? 'invalid_json'), [
                'user_message' => $userMessage,
                'llm_raw' => $llmResponse->raw,
                'llm_content' => $llmResponse->content,
            ]);
        }

        return $this->classifier->classify($tenantId, $userMessage, $guarded['json']);
    }
}
