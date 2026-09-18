<?php
return [
    'ai' => [
        'provider' => env('AI_PROVIDER'),
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
    ],
    'speech' => [
        'provider' => env('SPEECH_PROVIDER', 'openai-compatible'),
        'api_key' => env('SPEECH_API_KEY', env('AI_API_KEY')),
        'base_url' => env('SPEECH_BASE_URL', env('AI_BASE_URL', 'https://api.openai.com/v1')),
        'model' => env('SPEECH_MODEL', 'whisper-1'),
    ],
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],
    'haman_planner' => [
        'api_token' => env('APP_API_TOKEN'),
    ],
];
