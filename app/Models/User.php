<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class User extends Authenticatable
{
    use Notifiable;

    /** Default notification / AI preferences; stored values are merged over these. */
    public const PREFERENCE_DEFAULTS = [
        'notify_reminders_telegram' => true,
        'notify_support_email' => true,
        'notify_billing_email' => true,
        'weekly_summary_telegram' => false,
        'ai_enabled' => true,
        'ai_response_language' => 'auto', // auto = follow the interface language
        // Planning / time blocking
        'work_days' => null,               // ISO weekdays; null = locale default (fa: Sat–Wed, en: Mon–Fri)
        'work_start' => '09:00',
        'work_end' => '17:00',
        'default_task_minutes' => 30,
        'break_minutes' => 10,
        'planning_buffer_percent' => 20,   // share of working time kept free (the original 20% buffer)
        'calendar_blocks_planning' => true, // imported busy events reduce available time
        // Non-essential email (transactional/security email is always sent)
        'email_weekly_review' => false,
        'email_product_updates' => true,   // onboarding tips and inactivity reminders
    ];

    protected $fillable = [
        'name', 'email', 'password', 'is_admin', 'is_active',
        'telegram_chat_id', 'telegram_username', 'telegram_linked_at',
        'locale', 'timezone', 'preferences', 'onboarded_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'telegram_linked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'preferences' => 'array',
        ];
    }

    public function apiTokens(): HasMany { return $this->hasMany(ApiToken::class); }
    public function supportTickets(): HasMany { return $this->hasMany(SupportTicket::class); }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }

    /** Records activity at most every 5 minutes, without touching updated_at. */
    public function markSeen(): void
    {
        if ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subMinutes(5))) {
            $now = now();
            static::query()->whereKey($this->id)->toBase()->update(['last_seen_at' => $now]);
            $this->last_seen_at = $now;
        }
    }

    public function preferredLocale(): string
    {
        return Locales::normalize($this->locale) ?? Locales::DEFAULT;
    }

    public function preferredTimezone(): string
    {
        $tz = (string) ($this->timezone ?: config('app.timezone'));
        return in_array($tz, timezone_identifiers_list(), true) ? $tz : (string) config('app.timezone');
    }

    public function preference(string $key): mixed
    {
        $stored = is_array($this->preferences) ? $this->preferences : [];
        $value = array_key_exists($key, $stored) ? $stored[$key] : (self::PREFERENCE_DEFAULTS[$key] ?? null);
        if ($key === 'work_days' && !is_array($value)) {
            $value = $this->preferredLocale() === 'fa' ? [6, 7, 1, 2, 3] : [1, 2, 3, 4, 5];
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public function allPreferences(): array
    {
        $all = array_merge(self::PREFERENCE_DEFAULTS, array_intersect_key(is_array($this->preferences) ? $this->preferences : [], self::PREFERENCE_DEFAULTS));
        $all['work_days'] = $this->preference('work_days');
        return $all;
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }
}
