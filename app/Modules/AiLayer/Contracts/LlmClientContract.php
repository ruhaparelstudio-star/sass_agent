<?php

namespace App\Modules\AiLayer\Contracts;

use App\Modules\AiLayer\DTO\LlmResponse;

interface LlmClientContract
{
    public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse;
}
