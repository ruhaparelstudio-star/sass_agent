<?php

namespace App\Modules\AiLayer\Services;

class LlmJsonGuard
{
    public function sanitize(string $content): array
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'json' => null,
                'reason' => 'invalid_json',
            ];
        }

        if (! array_key_exists('intent', $decoded)
            || ! array_key_exists('confidence', $decoded)
            || ! array_key_exists('entities', $decoded)
            || ! is_array($decoded['entities'])) {
            return [
                'ok' => false,
                'json' => null,
                'reason' => 'invalid_entities',
            ];
        }

        $sanitized = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($sanitized)) {
            return [
                'ok' => false,
                'json' => null,
                'reason' => 'invalid_json',
            ];
        }

        return [
            'ok' => true,
            'json' => $sanitized,
            'reason' => null,
        ];
    }
}
