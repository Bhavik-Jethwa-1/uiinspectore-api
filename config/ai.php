<?php

return [
    'provider' => env('AI_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('AI_OPENAI_API_KEY', ''),
        'model' => env('AI_OPENAI_MODEL', 'gpt-4o'),
    ],

    'anthropic' => [
        'api_key' => env('AI_ANTHROPIC_API_KEY', ''),
        'model' => env('AI_ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
    ],
];
