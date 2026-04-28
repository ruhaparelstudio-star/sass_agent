<?php

namespace App\Modules\AiLayer\DTO;

use App\Modules\AiLayer\Enums\Intent;

class InterpretationResult
{
    public function __construct(
        public readonly Intent $intent,
        public readonly float $confidence,
        public readonly array $entities,
        public readonly array $raw,
        public readonly ?string $fallbackReason,
    ) {}

    public static function safeFallback(string $reason, array $raw = []): self
    {
        return new self(
            Intent::Unknown,
            0.0,
            [
                'package_query' => null,
                'resolved_package_code' => null,
                'resolved_package_name' => null,
                'event_date_iso' => null,
                'budget_amount' => null,
                'is_correction' => false,
                'corrected_fields' => [],
            ],
            $raw,
            $reason
        );
    }
}
