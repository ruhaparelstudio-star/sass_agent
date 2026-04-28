<?php

namespace App\Modules\AiLayer\Services;

use App\Modules\AiLayer\Contracts\IntentClassifierContract;
use App\Modules\AiLayer\DTO\InterpretationResult;
use App\Modules\AiLayer\Enums\Intent;
use App\Modules\DataKnowledge\Services\CatalogResolver;

class DeterministicIntentClassifier implements IntentClassifierContract
{
    public function __construct(
        private readonly CatalogResolver $catalogResolver,
    ) {}

    public function classify(int $tenantId, string $userMessage, string $llmJson): InterpretationResult
    {
        $decoded = json_decode($llmJson, true);

        if (! is_array($decoded)) {
            return InterpretationResult::safeFallback('invalid_json', [
                'user_message' => $userMessage,
                'llm_json' => $llmJson,
            ]);
        }

        $intent = Intent::fromNullableString($decoded['intent'] ?? null);
        $confidence = $this->normalizeConfidence($decoded['confidence'] ?? null);

        if (! array_key_exists('entities', $decoded) || ! is_array($decoded['entities'])) {
            return InterpretationResult::safeFallback('invalid_entities', [
                'user_message' => $userMessage,
                'llm_payload' => $decoded,
            ]);
        }

        $packageQuery = $this->normalizeText($decoded['entities']['package_query'] ?? null);

        [$resolvedCode, $resolvedName] = $this->resolvePackageAlias($tenantId, $packageQuery);

        return new InterpretationResult(
            $intent,
            $confidence,
            [
                'package_query' => $packageQuery,
                'resolved_package_code' => $resolvedCode,
                'resolved_package_name' => $resolvedName,
            ],
            [
                'user_message' => $userMessage,
                'llm_payload' => $decoded,
            ],
            null
        );
    }

    private function normalizeConfidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $float = (float) $value;

        if ($float < 0.0) {
            return 0.0;
        }

        if ($float > 1.0) {
            return 1.0;
        }

        return $float;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    private function resolvePackageAlias(int $tenantId, ?string $packageQuery): array
    {
        if ($packageQuery === null) {
            return [null, null];
        }

        $catalogs = $this->catalogResolver->resolveCatalog($tenantId, now());

        foreach ($catalogs as $catalog) {
            foreach ($catalog['products'] as $product) {
                foreach ($product['packages'] as $package) {
                    $code = mb_strtolower((string) ($package['code'] ?? ''));
                    $name = mb_strtolower((string) ($package['name'] ?? ''));

                    if ($packageQuery === $code || $packageQuery === $name) {
                        return [$package['code'], $package['name']];
                    }
                }
            }
        }

        return [null, null];
    }
}
