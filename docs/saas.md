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

- Metrics: `ai_requests` (monthly), `active_goals`, `active_projects`, `open_tasks` (counts of non-closed rows),
  `attachment_storage_mb` (total size of uploaded files). A limit only blocks **creating** more; existing data is
  never hidden or removed.
- Features: `telegram`, `ai_planner`, `advanced_analytics`, `priority_support`, `recurring_tasks`, `calendar`,
  `advanced_ai_planning`, `attachments`. The upgrade migration switched the four new features **on** for every
  existing plan, so nobody loses anything; turn them off per plan in Admin → Plans if you want to sell them.
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
- Zarinpal and Stripe-without-webhook: each period is paid explicitly. `billing:lifecycle` (hourly) expires ended
  periods and sends one renewal reminder `BILLING_RENEWAL_REMINDER_DAYS` before the end (Telegram + email,
  if the user allows billing emails).
- **Stripe auto-renewing subscriptions** (when a webhook signing secret is configured) — see below.
- Trials: a plan with `trial_days > 0` can be tried once per account, without payment details.
- Cancel keeps access until the end of the current period; resume undoes it.
- Admin → Users → *Grant* gives a plan for N months without payment (offline payments, partners).
- Adding a provider: implement `PaymentGateway` (`start`, `verify`, `currency`, `isConfigured`) and register it
  in `BillingService::gateways()`. A provider that can renew by itself also implements
  `RecurringGateway` (`supportsRecurring`, `setCancelAtPeriodEnd`, `cancelNow`).

### Zibal (toman)

`App\Services\Billing\Gateways\ZibalGateway` — Iranian cards, next to Zarinpal (both charge IRT; Persian users see
both, English users see Stripe only).

- Flow: `POST https://gateway.zibal.ir/v1/request` (merchant, amount, callbackUrl, orderId = payment ID) → `trackId`
  → redirect to `https://gateway.zibal.ir/start/{trackId}` → Zibal returns to `GET /billing/return/zibal?success&status&trackId&orderId`
  → the payment is looked up by `trackId` → `POST /v1/verify` (merchant, trackId). A payment is paid only if verify
  answers `result=100` with `amount` equal to the expected rial amount and the same `orderId`. `result=201` (already
  verified) is accepted only after `/v1/inquiry` reports `status=1`. The browser's `success`/`status` values are never
  trusted. A 5xx/timeout leaves the payment pending, so the next callback retries.
- **Currency:** the app stores tomans. `ZibalGateway::toRial()` (×10) is the only conversion, used for the request
  and for checking the verified amount; invoices stay in tomans. Tests cover 10,000 / 100,000 / 1,000,000 tomans.
- Idempotency: the same row lock as the other gateways — repeated callbacks never create a second payment, invoice or
  period. Only a masked card number and Zibal's reference number are stored.
- Settings: Admin → Payment settings → Zibal (merchant stored encrypted), or `ZIBAL_ENABLED`, `ZIBAL_MERCHANT`,
  `ZIBAL_SANDBOX` (test merchant `zibal`). The return URL has no signature because Zibal appends its own query string.

### Stripe subscriptions (auto-renewal)

When `STRIPE_WEBHOOK_SECRET` (or Admin → Payment settings → *Webhook signing secret*) is set, Stripe checkouts use
`mode=subscription`. Without it, Stripe keeps the original one-time behaviour.

1. Stripe dashboard → Developers → Webhooks → *Add endpoint* → `https://<APP_URL>/api/billing/webhook/stripe`.
2. Events: `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`,
   `customer.subscription.updated`, `customer.subscription.deleted`.
3. Copy the signing secret (`whsec_…`) into the panel (stored encrypted) or `.env`.

How it works:

- The browser redirect still activates the first period, but only after the server fetches the Checkout Session
  from Stripe (amount, currency, reference and subscription ID must match). If the browser never comes back,
  `checkout.session.completed` does the same verification.
- Webhooks are accepted only with a valid `Stripe-Signature` (HMAC-SHA256, 5-minute tolerance); anything else
  gets HTTP 400 and changes nothing. Each event ID is processed once (`webhook_events`); failed processing returns
  500 so Stripe retries. Payloads are never stored or logged.
- `invoice.paid` for a renewal records a paid payment + invoice (idempotent per Stripe invoice) and extends the
  period. `invoice.payment_failed` sets the subscription to `past_due`: access continues for
  `BILLING_PAST_DUE_GRACE_DAYS` (7) counted from the failure while Stripe retries (the unpaid new period is not
  granted), and the customer gets one email per failed invoice. `customer.subscription.updated/deleted` mirror
  cancellations made in Stripe; because Stripe does not deliver events in order, the handler re-reads the
  subscription from Stripe and applies its current state, and a period end never moves backwards. If a checkout's
  browser redirect failed verification, `checkout.session.completed` verifies it again. A renewal for a subscription
  that already ended locally (e.g. replaced by another plan) is cancelled at Stripe instead of being revived.
- Cancel/resume in the app call Stripe first (`cancel_at_period_end`); if Stripe fails, nothing changes locally.
  Switching plans cancels the old Stripe subscription immediately. Auto-renewing periods keep access for
  `BILLING_WEBHOOK_GRACE_DAYS` (3) after the period end, in case a renewal webhook is late. A second checkout for a
  plan that already renews automatically is refused, and the webhook secret cannot be cleared in the panel while
  auto-renewing subscriptions exist.
- No card data is ever handled by the app; only Stripe IDs (`sub_…`, `cus_…`, `in_…`) are stored.

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
`registered`, `logged_in`, `onboarding_started/completed`, `first_goal`, `first_project`, `first_task`,
`first_task_completed`, `first_ai_request`, `first_plan_generated/applied`, `telegram_connected`,
`calendar_connected`, `trial_started`, `checkout_started`, `subscription_started/upgraded/canceled`,
`payment_failed`, `account_deleted`. Admin → Overview shows the activation funnel built from them.

`user_activity_days` stores one row per user per active day (web, API or Telegram while signed in). **Admin →
Analytics** (`App\Services\Analytics\ProductAnalytics`) computes, only from recorded data:

| Metric | Definition |
|---|---|
| DAU / WAU / MAU | distinct active users today / last 7 / last 30 days (WAU/MAU hidden until 7/30 days of tracking exist) |
| Activation | users who signed up 7–37 days ago and created a task within 7 days |
| Trial → paid | trials ended in the last 90 days followed by a paid payment |
| Sign-up → paid | users who signed up in the last 90 days with ≥1 paid payment |
| Churn (30 days) | paid subscribers 30 days ago who no longer have a paid subscription |
| Retention W1 / M1 | active on days 7–13 / 28–34 after sign-up (cohorts after tracking began) |
| MRR / ARPU | last paid amount of each active/past-due paid subscription (yearly ÷ 12), per currency; ARPU = MRR ÷ subscriptions |

Empty cohorts show "not enough data" instead of 0 %. Currencies are never converted; trials and manual grants
are not revenue. Activity tracking starts with this release, so retention and MAU fill in over the first month.

## 11. Lifecycle emails

`App\Services\Notifications\LifecycleMailer` sends queued, localized (fa RTL / en LTR) HTML emails:

| Type | When | Can be turned off |
|---|---|---|
| welcome, verify_email | sign-up / email change / "send verification link" | no (account) |
| trial_started, trial_ending | trial start / `BILLING_RENEWAL_REMINDER_DAYS` before the end | billing emails |
| subscription_started, payment_failed, subscription_canceled | billing events | no (essential) |
| renewal_reminder | before a manually renewed period ends | billing emails |
| weekly_review | first day of the user's week after 08:00 local time | opt-in (Settings) |
| inactive_reminder | once after 7 days without activity (not for accounts idle > 30 days) | tips & reminders |

Each message is recorded in `notification_deliveries` (type + time, no content) with a dedupe key, so it is sent at
most once even if a job repeats. Optional emails carry a signed one-click unsubscribe link (and a
`List-Unsubscribe` header). `planner:lifecycle-emails` runs hourly. Mail needs `MAIL_*`; without it nothing breaks.

## 12. Security

- `Content-Security-Policy`: self-hosted assets only (`default-src 'self'`, no third-party scripts, `object-src
  'none'`, `frame-ancestors 'self'`, `base-uri 'self'`); `form-action` allows the payment/OAuth providers the
  checkout redirects to. Inline scripts are still allowed because the existing pages use them.
  `CSP_REPORT_ONLY=true` to observe, `CSP_ENABLED=false` to switch off.
- HSTS on HTTPS requests, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP.
- Session cookies are `Secure` automatically when `APP_URL` is `https://` (override with `SESSION_SECURE_COOKIE`);
  `FORCE_HTTPS=true` redirects plain-HTTP page requests in production.
- Email verification (signed 60-minute link bound to the address; changing the email resets it). It is a reminder,
  not a gate, so existing accounts keep working.
- Changing the password rotates the remember-me token; logout sends `Clear-Site-Data: "cache"`.
- Observability: every web/API request has an `X-Request-Id` that is added to all its log lines and carried into
  queued jobs; jobs log their job ID; AI interactions store a request ID; billing logs carry the payment/event ID.
  Tokens, keys and webhook secrets are masked in Admin → System.

## 13. Planning, calendar and files

- Recurring tasks, time blocking, the Haman AI planner and weekly reviews are described in
  [planning-engine.md](planning-engine.md). AI proposals are always shown first and applied only on confirmation;
  the AI cannot add tasks or IDs that do not exist.
- Google Calendar: configure the OAuth client in Admin → Integrations (or `GOOGLE_CALENDAR_*`). Tokens are stored
  encrypted; a revoked grant marks the connection instead of failing jobs. `calendar:sync` runs every 15 minutes.
  The private iCal feed (Settings → Calendar) needs no Google account.
- Attachments are stored on the `attachments` disk under random names, the type is detected from the file content
  (the client's name/MIME are not trusted), downloads are sent as attachments with `nosniff` and a sandbox CSP, and
  files are deleted with their task/project or account. In Docker they live on the `planner_storage` volume.

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
