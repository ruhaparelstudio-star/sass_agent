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
        $customerName = $this->normalizeName($decoded['entities']['customer_name'] ?? ($decoded['entities']['name'] ?? null));
        $eventType = $this->normalizeText($decoded['entities']['event_type'] ?? null);
        $eventDateIso = $this->parseEventDateIso($decoded['entities']['event_date'] ?? null);
        $location = $this->normalizeText($decoded['entities']['location'] ?? null);
        $budgetAmount = $this->parseBudgetAmount($decoded['entities']['budget'] ?? null);
        $invoiceReference = $this->normalizeText($decoded['entities']['invoice_reference'] ?? null);
        [$isCorrection, $correctedFields] = $this->detectCorrection(
            $userMessage,
            $decoded['entities']
        );
        if ($intent === Intent::Unknown) {
            $intent = $this->inferIntentFromMessage($userMessage, $isCorrection);
        }

        [$resolvedCode, $resolvedName] = $this->resolvePackageAlias($tenantId, $packageQuery);

        return new InterpretationResult(
            $intent,
            $confidence,
            [
                'package_query' => $packageQuery,
                'resolved_package_code' => $resolvedCode,
                'resolved_package_name' => $resolvedName,
                'customer_name' => $customerName,
                'event_type' => $eventType,
                'event_date_iso' => $eventDateIso,
                'location' => $location,
                'budget_amount' => $budgetAmount,
                'invoice_reference' => $invoiceReference,
                'is_correction' => $isCorrection,
                'corrected_fields' => $correctedFields,
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

    private function normalizeName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolvePackageAlias(int $tenantId, ?string $packageQuery): array
    {
        if ($packageQuery === null) {
            return [null, null];
        }

        $catalogs = $this->catalogResolver->resolveCatalog($tenantId, now());
        $aliasMatches = [];

        foreach ($catalogs as $catalog) {
            foreach ($catalog['products'] as $product) {
                foreach ($product['packages'] as $package) {
                    $code = mb_strtolower((string) ($package['code'] ?? ''));
                    $name = mb_strtolower((string) ($package['name'] ?? ''));

                    if ($packageQuery === $code || $packageQuery === $name) {
                        return [$package['code'], $package['name']];
                    }

                    foreach (($package['aliases'] ?? []) as $aliasRow) {
                        $alias = mb_strtolower((string) ($aliasRow['alias'] ?? ''));
                        if ($packageQuery !== $alias) {
                            continue;
                        }

                        $packageCode = (string) ($package['code'] ?? '');
                        if ($packageCode === '') {
                            continue;
                        }

                        $aliasMatches[$packageCode] = [
                            $package['code'] ?? null,
                            $package['name'] ?? null,
                        ];
                    }
                }
            }
        }

        if (count($aliasMatches) === 1) {
            return array_values($aliasMatches)[0];
        }

        return [null, null];
    }

    private function parseEventDateIso(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $normalized, $matches) === 1) {
            return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
                ? $normalized
                : null;
        }

        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $normalized, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    private function parseBudgetAmount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            return $value > 0 ? (int) round($value) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\d/', $normalized) !== 1) {
            return null;
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(juta|jt)\b/u', $normalized, $matches) === 1) {
            $base = (float) str_replace(',', '.', $matches[1]);
            $amount = (int) round($base * 1000000);

            return $amount > 0 ? $amount : null;
        }

        $digits = preg_replace('/\D+/u', '', $normalized);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        $amount = (int) $digits;

        return $amount > 0 ? $amount : null;
    }

    private function detectCorrection(string $userMessage, array $entities): array
    {
        $explicitCorrection = ($entities['correction'] ?? null) === true;
        $messageHasMarker = preg_match('/\b(koreksi|ralat|revisi|bukan|salah|ubah|ganti)\b/u', mb_strtolower($userMessage)) === 1;
        $isCorrection = $explicitCorrection || $messageHasMarker;

        $correctedFields = $this->normalizeCorrectedFields($entities['corrected_fields'] ?? null);

        if (! $isCorrection) {
            return [false, []];
        }

        return [true, $correctedFields];
    }

    private function normalizeCorrectedFields(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allowed = [
            'package_query' => 'package_query',
            'package' => 'package_query',
            'event_date' => 'event_date',
            'event_date_iso' => 'event_date',
            'budget' => 'budget',
            'budget_amount' => 'budget',
        ];

        $normalized = [];

        foreach ($value as $field) {
            if (! is_string($field)) {
                continue;
            }

            $key = mb_strtolower(trim($field));
            if ($key === '' || ! array_key_exists($key, $allowed)) {
                continue;
            }

            $mapped = $allowed[$key];
            if (! in_array($mapped, $normalized, true)) {
                $normalized[] = $mapped;
            }
        }

        return $normalized;
    }

    private function inferIntentFromMessage(string $userMessage, bool $isCorrection): Intent
    {
        $text = mb_strtolower(trim($userMessage));
        if ($text === '') {
            return Intent::UnclearMessage;
        }

        if (preg_match('/\b(komplain|complain|kecewa|marah|ga puas|tidak puas)\b/u', $text) === 1) {
            return Intent::Complaint;
        }

        if (preg_match('/\b(admin|operator|cs|human|orang|takeover|handoff)\b/u', $text) === 1) {
            return Intent::RequestHandoff;
        }

        if (preg_match('/\b(topic|bahas|ganti topik|pindah topik)\b/u', $text) === 1) {
            return Intent::TopicSwitch;
        }

        if ($isCorrection) {
            return Intent::Correction;
        }

        if (preg_match('/\b(pricelist|price ?list|brosur|katalog pdf)\b/u', $text) === 1) {
            return Intent::AskPricelist;
        }

        if (preg_match('/\b(harga|biaya|berapa)\b/u', $text) === 1) {
            return Intent::AskPrice;
        }

        if (preg_match('/\b(detail paket|isi paket|include|fasilitas)\b/u', $text) === 1) {
            return Intent::AskPackageDetail;
        }

        if (preg_match('/\b(available|availability|jadwal|tanggal kosong|slot)\b/u', $text) === 1) {
            return Intent::AskAvailability;
        }

        if (preg_match('/\b(booking|book|reservasi|jadi ambil|jadi deal)\b/u', $text) === 1) {
            return Intent::BookingIntent;
        }

        if (preg_match('/\b(bayar|pembayaran|invoice|dp|lunas|transfer)\b/u', $text) === 1) {
            return Intent::PaymentRelated;
        }

        if (preg_match('/^[\p{L}\p{N}\s?!.]{1,10}$/u', $text) === 1) {
            return Intent::UnclearMessage;
        }

        return Intent::Unknown;
    }
}
