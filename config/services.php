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
        // Public bot username without "@" (used for one-tap t.me link buttons).
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    ],
    'haman_planner' => [
        'api_token' => env('APP_API_TOKEN'),
        // Public self-service sign-up on /register (set PLANNER_REGISTRATION=false to close it).
        'registration' => filter_var(env('PLANNER_REGISTRATION', true), FILTER_VALIDATE_BOOL),
    ],
];
