# Haman Planner as a SaaS — languages, plans, billing, privacy

Copyright (c) 2026 Reza Rafiei. All rights reserved.

This document covers the multi-tenant, bilingual and commercial parts of the application.
Planner concepts are described in the other documents in this folder.

## 1. Data isolation

Every planner model uses `App\Models\Concerns\BelongsToPlannerUser`:

- a global `planner_owner` scope limits every query to the current owner
  (Telegram context → `PlannerUserContext`, web/API → `Auth::id()`);
- on `saving` the owner is always the current user — a `user_id` sent in a request is ignored;
- referenced ids (`goal_id`, `project_id`, `task_id`, …) are checked to belong to the same owner;
- background workers (reminders, billing lifecycle) run without a user context and see all rows on purpose.

Admins manage accounts, plans and support from `/admin`; they do not browse other users' planner data.

## 2. Languages (fa / en)

| Where | How the language is chosen |
|---|---|
| Public pages | By URL: `/`, `/pricing`, `/privacy`, `/terms` are Persian; `/en`, `/en/pricing`, … are English |
| Signed-in web + API | The account's `users.locale` |
| Guests | Session → `hp_locale` cookie → browser `Accept-Language` → `APP_LOCALE` (default `fa`) |
| Telegram | Linked account's language; unlinked chats use Telegram's `language_code` |

- Translations live in `resources/lang/fa/*.php` and `resources/lang/en/*.php` with identical keys
  (enforced by `LocalizationAccountAndSeoTest::test_language_files_have_identical_keys`).
- The switcher (`/language/{fa|en}?to=/current/path`) saves the choice to the account, the session and a cookie,
  and returns to the same page (only same-site relative paths are accepted).
- Layouts set `lang` and `dir` (`rtl` for Persian, `ltr` for English); styles use logical properties
  (`margin-inline-start`, `inset-inline-end`, …) so one stylesheet serves both directions.
- Persian commands and words typed into the bot (`امروز`, `فردا`, `لغو`, …) keep working for every user.

### Dates and time zones

- The database stores times in `APP_TIMEZONE` (Asia/Tehran), exactly as before — no data was converted.
- Every user has `users.timezone` (existing users were set to Asia/Tehran). Pages, the bot and the AI prompt
  display times in that zone; the dashboard sends datetimes with an explicit UTC offset, which the models
  convert to the storage zone.
- Persian shows the Jalali calendar with Persian digits (`App\Support\LocalDate`); English shows Gregorian dates.

## 3. Plans, limits and entitlements

Plans are rows in `plans` and are edited in **Admin → Plans** (names/descriptions per language, prices per currency
and interval, limits, feature flags, trial days, default/public/active). `PlanSeeder` only creates the initial
`free`, `pro`, `business` rows if missing — it never overwrites edits. **The seeded prices are placeholders: review them
before enabling payments.**

`App\Services\Billing\Entitlements` is the only place that answers "may this user do X?":

```php
$entitlements->canUse($user, 'telegram');          // feature flag
$entitlements->limit($user, 'ai_requests');        // null = unlimited
$entitlements->remaining($user, 'ai_requests');
$entitlements->consume($user, 'ai_requests');      // atomic monthly metering, refunded if the AI call fails
$entitlements->ensureCanCreate($user, 'open_tasks');
```

- Metrics: `ai_requests` (monthly), `active_goals`, `active_projects`, `open_tasks` (counts of non-closed rows).
  A limit only blocks **creating** more; existing data is never hidden or removed.
- Features: `telegram`, `ai_planner`, `advanced_analytics`, `priority_support`.
- Admins are never limited. If no plan exists, nothing is limited (so the feature is inert until seeded).
- Plan codes are never hard-coded in business logic; the effective plan is the user's current subscription
  or the plan marked *default*.
- When a limit is hit: API → HTTP 402 JSON `{message, metric, limit, upgrade_url}`; web → back with a translated
  error; Telegram → a translated message with the upgrade link.

With the seeded catalogue, the *Free* plan includes every feature and **30 AI requests per month**; all other
limits are unlimited. Change this in Admin → Plans if you want different defaults.

## 4. Billing

```
/billing → choose plan → POST /billing/checkout → provider → signed /billing/callback/{payment}
        → server-to-server verification with the provider → payment "paid" → subscription period → invoice
```

- Providers (`app/Services/Billing/Gateways`): **Zarinpal** REST v4 (IRT) and **Stripe Checkout** (USD, one-time
  `payment` mode). The interface language picks the currency (`fa` → IRT, `en` → USD, see `config/billing.php`).
- A payment becomes `paid` **only** after the provider confirms it (Zarinpal `verify` code 100/101; Stripe session
  `payment_status=paid` with the exact amount, currency and reference). There is no local "simulate success" path.
- The callback URL is signed; completion is idempotent (row lock, activates at most once).
- No automatic recurring charges: each period is paid explicitly. `billing:lifecycle` (hourly) expires ended
  periods and sends one renewal reminder `BILLING_RENEWAL_REMINDER_DAYS` before the end (Telegram + email,
  if the user allows billing emails).
- Trials: a plan with `trial_days > 0` can be tried once per account, without payment details.
- Cancel keeps access until the end of the current period; resume undoes it.
- Admin → Users → *Grant* gives a plan for N months without payment (offline payments, partners).
- Adding a provider: implement `PaymentGateway` (`start`, `verify`, `currency`, `isConfigured`) and register it
  in `BillingService::gateways()`.

### Enabling payments

1. Review plan prices in Admin → Plans.
2. Admin → **Payment settings**: enable Zarinpal and enter the merchant ID (tick *sandbox* to test against
   Zarinpal's sandbox), and/or enable Stripe and enter the secret key (`sk_test_…` first, then `sk_live_…`).
   Secrets are stored encrypted with `APP_KEY` and never shown again in full. Values saved in the panel take
   precedence over `.env` (`ZARINPAL_*`, `STRIPE_*`), which remain a fallback.
3. `APP_URL` must be the public HTTPS URL (it is used in the signed callback URL); the page warns if it is not.
4. Make one real low-value payment per provider and check Admin → Payments.

If `APP_KEY` is ever rotated, re-enter the keys in the panel (old encrypted values can no longer be read and
are ignored).

## 5. Onboarding

New accounts go to `/onboarding` (profile/language/time zone → first goal and task → Telegram → AI intro).
Every step and the whole flow can be skipped. Existing accounts were marked as onboarded by the migration.

## 6. Privacy

Settings → *Privacy & data* lets a user export all their data (JSON), disconnect Telegram and delete the account.
Deletion requires the current password and typing a confirmation word; it removes planner data, tickets, tokens,
subscriptions and usage, and keeps paid invoices as accounting records with the name/email replaced by a pseudonym
(`BILLING_ANONYMIZE_ON_DELETE`). The last active admin cannot delete their own account.

`/privacy` and `/terms` are **placeholders** in both languages. They describe what the software actually does and
must be reviewed by a lawyer and completed with your legal entity (`LEGAL_ENTITY_NAME`, `SUPPORT_EMAIL`) before launch.

## 7. SEO

Public pages have a title, description, canonical URL, `hreflang` (fa, en, x-default), Open Graph, Twitter card and
JSON-LD (`SoftwareApplication`). `/sitemap.xml` lists both languages; `/robots.txt` disallows the app, account,
billing, admin and API paths. Every non-public response carries `X-Robots-Tag: noindex, nofollow`.

## 8. Product analytics

`product_events` records a small set of first-party events (no third-party trackers, no page views, no content):
`registered`, `logged_in`, `onboarding_completed`, `first_goal`, `first_task`, `first_ai_request`,
`telegram_connected`, `trial_started`, `subscription_started`, `subscription_upgraded`, `subscription_canceled`,
`account_deleted`. Admin → Overview shows the activation funnel built from them.

## 9. Public page content

Admin → **Landing content** edits, per language, the texts of the landing page (SEO title/description, hero,
sections, features, FAQ, final call to action, footer), the pricing page SEO, and the Privacy and Terms pages,
including hiding the "draft" notice once the legal texts are final. Only changed fields are stored
(`app_settings.landing_content`); emptying a field or *Reset* returns to the built-in text in `resources/lang`.
Plan names and prices on the page come from Admin → Plans.

## 10. Admin

`/admin`: overview (users, active, paying, trials, AI usage, 30-day revenue, funnel), users (filters incl. *paying*,
plan, grant, activate/deactivate, CSV export), subscriptions, plans, payments/revenue, support tickets,
platform settings (name, logo, taglines per language, registration), and system health (DB, queue, scheduler
heartbeat, provider configuration, recent errors with credentials masked).
