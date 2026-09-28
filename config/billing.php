<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Billing & entitlements
|--------------------------------------------------------------------------
| Plans themselves (names, prices, limits, features) live in the `plans` table and are
| edited from Admin → Plans. This file only declares what the application knows how to
| meter/enforce, the currencies it can charge in and the payment providers it can use.
*/
return [
    // Metered or counted limits a plan may set (null in a plan = unlimited).
    'metrics' => [
        'ai_requests' => ['type' => 'monthly'],          // AI Planner + AI command parsing
        'active_goals' => ['type' => 'count'],           // goals not completed/cancelled
        'active_projects' => ['type' => 'count'],        // projects not completed/cancelled
        'open_tasks' => ['type' => 'count'],             // tasks not completed/cancelled
        'attachment_storage_mb' => ['type' => 'storage'], // total size of a user's attachments
    ],

    // Boolean capabilities a plan may grant.
    'features' => ['telegram', 'ai_planner', 'advanced_analytics', 'priority_support',
        'recurring_tasks', 'calendar', 'advanced_ai_planning', 'attachments'],

    // Minor-unit handling per currency.
    'currencies' => [
        'IRT' => ['decimals' => 0],   // Iranian toman
        'USD' => ['decimals' => 2],
    ],

    // Which currency to price in for each interface language.
    'locale_currency' => ['fa' => 'IRT', 'en' => 'USD'],

    'providers' => [
        'zarinpal' => [
            'enabled' => filter_var(env('ZARINPAL_ENABLED', false), FILTER_VALIDATE_BOOL),
            'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
            'sandbox' => filter_var(env('ZARINPAL_SANDBOX', false), FILTER_VALIDATE_BOOL),
            'currency' => 'IRT',
        ],
        'stripe' => [
            'enabled' => filter_var(env('STRIPE_ENABLED', false), FILTER_VALIDATE_BOOL),
            'secret' => env('STRIPE_SECRET'),
            'currency' => 'USD',
        ],
    ],

    // Days before the end of a paid period to remind the user (Telegram/email).
    'renewal_reminder_days' => (int) env('BILLING_RENEWAL_REMINDER_DAYS', 3),

    // Account deletion: paid invoices are accounting records and are kept, with personal
    // fields replaced by a pseudonym when this is true.
    'anonymize_invoices_on_account_deletion' => filter_var(env('BILLING_ANONYMIZE_ON_DELETE', true), FILTER_VALIDATE_BOOL),

    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'HP'),
];
