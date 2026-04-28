<?php

return [
    'provider' => env('AI_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 30),
        'temperature' => (float) env('OPENAI_TEMPERATURE', 0.3),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 800),
        'force_json' => (bool) env('OPENAI_FORCE_JSON', true),
    ],
];
