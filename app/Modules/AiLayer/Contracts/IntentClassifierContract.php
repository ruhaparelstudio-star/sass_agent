<?php

namespace App\Modules\AiLayer\Contracts;

use App\Modules\AiLayer\DTO\InterpretationResult;

interface IntentClassifierContract
{
    public function classify(int $tenantId, string $userMessage, string $llmJson): InterpretationResult;

    public function fallbackFromMessage(int $tenantId, string $userMessage, string $reason, array $raw = []): InterpretationResult;
}
