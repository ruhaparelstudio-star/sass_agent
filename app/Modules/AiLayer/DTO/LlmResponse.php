<?php

namespace App\Modules\AiLayer\DTO;

class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly int $totalTokens,
        public readonly array $raw,
    ) {}
}
