<?php
return [
    'name' => env('APP_NAME', 'Haman Planner'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    // Redirect plain-HTTP GET requests to HTTPS in production (enable when TLS terminates at a proxy that sets X-Forwarded-Proto).
    'force_https' => filter_var(env('FORCE_HTTPS', false), FILTER_VALIDATE_BOOL),
    // Content-Security-Policy on web pages (CSP_REPORT_ONLY=true to observe without enforcing).
    'csp' => filter_var(env('CSP_ENABLED', true), FILTER_VALIDATE_BOOL),
    'csp_report_only' => filter_var(env('CSP_REPORT_ONLY', false), FILTER_VALIDATE_BOOL),
    'timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),
    // Interface language for visitors with no saved or browser preference (fa or en).
    'locale' => env('APP_LOCALE', 'fa'),
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'maintenance' => ['driver' => 'file'],
];
