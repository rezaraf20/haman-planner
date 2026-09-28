<?php
return [
    'greeting' => 'Hi :name,',
    'footer' => 'You are receiving this because you have a Haman Planner account.',
    'unsubscribe' => 'Stop emails like this',
    'unsubscribed_title' => 'Email preference updated',
    'unsubscribed_text' => 'You will no longer receive ":type" emails. You can turn them back on any time in Settings.',
    'pref' => [
        'notify_billing_email' => 'billing reminders',
        'email_weekly_review' => 'weekly review',
        'email_product_updates' => 'tips and reminders',
    ],
    'welcome' => [
        'subject' => 'Welcome to Haman Planner',
        'lines' => ['Your account is ready. Start by adding one goal and the three tasks that matter most this week — Haman will help you fit them into your days.'],
        'cta' => 'Open my planner',
    ],
    'verify_email' => [
        'subject' => 'Confirm your email address',
        'lines' => ['Please confirm that this is your email address. The link is valid for 60 minutes.', 'If you did not create a Haman Planner account, you can ignore this email.'],
        'cta' => 'Confirm email',
    ],
    'trial_started' => [
        'subject' => 'Your :plan trial has started',
        'lines' => ['Your :plan trial is active until :date. All of the plan\'s features are unlocked — no payment details were taken.'],
        'cta' => 'See my plan',
    ],
    'trial_ending' => [
        'subject' => 'Your :plan trial ends on :date',
        'lines' => ['Your :plan trial ends on :date. After that your account moves to the free plan and nothing is deleted. You can subscribe any time to keep the extra features.'],
        'cta' => 'Choose a plan',
    ],
    'subscription_started' => [
        'subject' => 'Your :plan subscription is active',
        'lines' => ['Thank you! Your :plan subscription is active. The invoice is available on your billing page.'],
        'cta' => 'View billing',
    ],
    'payment_failed' => [
        'subject' => 'We could not renew your :plan subscription',
        'lines' => ['The renewal payment for your :plan subscription failed. The payment provider will retry automatically, and your access continues for up to :days days.', 'Please check your card details with the payment provider to avoid losing access to paid features.'],
        'cta' => 'View billing',
    ],
    'subscription_canceled' => [
        'subject' => 'Your :plan subscription was canceled',
        'lines' => ['Your :plan subscription will not renew. You keep access until :date; after that the account moves to the free plan and your data stays safe.', 'Changed your mind? You can resume it from the billing page before that date.'],
        'cta' => 'View billing',
    ],
    'renewal_reminder' => [
        'subject' => 'Your plan renews soon',
        'lines' => ['Your :plan period ends on :date. Renew from the billing page to keep your plan\'s features.'],
        'cta' => 'Renew',
    ],
    'weekly_review' => [
        'subject' => 'Your weekly review',
        'lines' => [':summary'],
        'cta' => 'Open the full review',
    ],
    'inactive_reminder' => [
        'subject' => 'Your plan is waiting for you',
        'lines' => ['It has been a while since you last planned your days. Take two minutes to pick today\'s most important task — Haman can plan the rest.'],
        'cta' => 'Plan today',
    ],
];
