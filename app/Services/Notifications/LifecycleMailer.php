<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use App\Mail\LifecycleMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Lifecycle emails (welcome, verification, trial, billing, weekly review, inactivity).
 *
 * - Each message is sent at most once per (user, dedupe key), recorded in notification_deliveries
 *   (type + time only — the content is never stored).
 * - Security and essential billing email is always sent; optional categories follow the user's
 *   email preferences and carry a signed one-click unsubscribe link.
 * - Queued; a mail failure never breaks the action that triggered it.
 */
final class LifecycleMailer
{
    /** type => preference that must be true (null = always sent: security / essential billing) */
    public const TYPES = [
        'welcome' => null,
        'verify_email' => null,
        'trial_started' => 'notify_billing_email',
        'trial_ending' => 'notify_billing_email',
        'subscription_started' => null,
        'payment_failed' => null,
        'subscription_canceled' => null,
        'renewal_reminder' => 'notify_billing_email',
        'weekly_review' => 'email_weekly_review',
        'inactive_reminder' => 'email_product_updates',
    ];

    /** Preferences a signed link may switch off. */
    public const UNSUBSCRIBABLE = ['notify_billing_email', 'email_weekly_review', 'email_product_updates'];

    /**
     * @param array<string,mixed> $params placeholders for the localized text (and optional 'url')
     * @return bool whether a message was queued
     */
    public function send(User $user, string $type, array $params = [], ?string $dedupeKey = null): bool
    {
        if (!array_key_exists($type, self::TYPES) || !$user->is_active || !filled($user->email)) {
            return false;
        }
        $preference = self::TYPES[$type];
        if ($preference !== null && !$user->preference($preference)) {
            return false;
        }
        $dedupeKey = mb_substr($dedupeKey ?? $type, 0, 120);
        $inserted = DB::table('notification_deliveries')->insertOrIgnore([
            'user_id' => $user->id, 'type' => $type, 'dedupe_key' => $dedupeKey, 'channel' => 'mail', 'sent_at' => now(),
        ]);
        if ($inserted === 0) {
            return false; // already sent
        }
        $locale = $user->preferredLocale();
        $unsubscribe = $preference !== null ? self::unsubscribeUrl($user, $preference) : null;
        try {
            Mail::to($user->email, $user->name)->queue((new LifecycleMail($type, $params + ['name' => $user->name], $unsubscribe))->locale($locale));
        } catch (\Throwable $e) {
            DB::table('notification_deliveries')->where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->delete();
            report($e);
            return false;
        }
        return true;
    }

    public static function unsubscribeUrl(User $user, string $preference): string
    {
        return URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'preference' => $preference]);
    }
}
