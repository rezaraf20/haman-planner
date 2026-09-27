<?php
return [
    'draft_notice' => 'This is a template drafted from how the software actually works. It has not been reviewed by a lawyer — adapt it to the legal requirements of your jurisdiction before commercial use.',
    'updated' => 'Last updated: :date',
    'operator' => 'Service operator: :name — contact: :email',
    'privacy_title' => 'Privacy Policy',
    'privacy_meta' => 'What Haman Planner stores, why, and how you can download or delete it.',
    'privacy' => [
        ['What we store', 'Account details (name, email, a hash of your password, language and timezone); the content you add to the planner (goals, projects, tasks, notes, decisions, reminders, work logs); your Telegram ID and username if you connect the bot; subscription and payment records; and when you were last active.'],
        ['Why we need it', 'To provide the planning service, send reminders and messages on Telegram, send password-reset and subscription emails, apply your plan\'s limits, and answer support tickets.'],
        ['Data separation', 'Your planner data can only be seen and changed by your account. Administrators can see account details and aggregate usage (such as how many tasks exist), not the content of your plans.'],
        ['AI', 'When you use the AI Planner or a text command, your account\'s open tasks and plan are sent for analysis to the AI provider configured by the operator. You can turn AI features off in your settings.'],
        ['Payments', 'Payments are handled by third-party providers (Stripe or Zarinpal); we never store your card details. We keep the amount, status and reference of each payment.'],
        ['Product analytics', 'We record high-level events such as sign-ups, finishing setup or starting a subscription to improve the product — without plan content, IP addresses or advertising trackers.'],
        ['Your rights', 'In Account & settings you can view and edit your account details, download a complete copy of your data (JSON), disconnect Telegram and permanently delete your account.'],
        ['Deleting your account', 'Deleting your account removes your planner data, AI history, reminders, support tickets and Telegram connection, and ends your subscription. Paid invoices are kept as accounting records, with your personal details anonymised.'],
    ],
    'terms_title' => 'Terms of Service',
    'terms_meta' => 'The terms for using Haman Planner, subscriptions and payments.',
    'terms' => [
        ['Using the service', 'By creating an account you are responsible for keeping your password safe and for activity under your account, and you agree not to use the service for unlawful purposes or to harm others.'],
        ['Your content', 'You own the content you add to the planner. We only process it to provide the service.'],
        ['AI suggestions', 'The AI Planner\'s output is a suggestion and may be wrong. Decisions — and responsibility for them — remain yours.'],
        ['Subscriptions & payment', 'Plans, allowances and prices are listed on the pricing page. A subscription covers the period you paid for and does not renew automatically. If you cancel, you keep access until the end of the paid period.'],
        ['Refunds', 'The refund policy must be set by the service operator. [Complete this section before enabling payments.]'],
        ['Changes & termination', 'Features and terms may change; important changes are announced in the dashboard. Accounts that break these terms may be disabled.'],
    ],
];
