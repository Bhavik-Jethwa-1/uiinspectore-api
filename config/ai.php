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

    'ollama' => [
        'base_url' => env('AI_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('AI_OLLAMA_MODEL', 'llava'),
    ],

    'cloudflare' => [
        'account_id' => env('AI_CF_ACCOUNT_ID', ''),
        'api_token' => env('AI_CF_API_TOKEN', ''),
        'model' => env('AI_CF_MODEL', '@cf/meta/llama-3.1-8b-instruct'),
    ],

    'groq' => [
        'api_key' => env('AI_GROQ_API_KEY', ''),
        'model' => env('AI_GROQ_MODEL', 'llama-3.2-11b-vision-preview'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('AI_GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'xai' => [
        'api_key' => env('XAI_API_KEY', ''),
        'model' => env('AI_XAI_MODEL', 'grok-2-vision-1212'),
    ],
];
