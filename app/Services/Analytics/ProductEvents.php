<?php
declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ProductEvent;
use App\Models\User;

/**
 * First-party product analytics. Records *what happened* (event name + small, non-content
 * properties such as plan code or locale) — never planner content, IPs or user agents.
 */
final class ProductEvents
{
    public const REGISTERED = 'registered';
    public const LOGGED_IN = 'logged_in';
    public const ONBOARDING_COMPLETED = 'onboarding_completed';
    public const FIRST_GOAL = 'first_goal';
    public const FIRST_TASK = 'first_task';
    public const FIRST_AI_REQUEST = 'first_ai_request';
    public const TELEGRAM_CONNECTED = 'telegram_connected';
    public const TRIAL_STARTED = 'trial_started';
    public const SUBSCRIPTION_STARTED = 'subscription_started';
    public const SUBSCRIPTION_UPGRADED = 'subscription_upgraded';
    public const SUBSCRIPTION_CANCELED = 'subscription_canceled';
    public const ACCOUNT_DELETED = 'account_deleted';
    public const ONBOARDING_STARTED = 'onboarding_started';
    public const FIRST_PROJECT = 'first_project';
    public const FIRST_TASK_COMPLETED = 'first_task_completed';
    public const FIRST_PLAN_GENERATED = 'first_plan_generated';
    public const FIRST_PLAN_APPLIED = 'first_plan_applied';
    public const CALENDAR_CONNECTED = 'calendar_connected';
    public const CHECKOUT_STARTED = 'checkout_started';
    public const PAYMENT_FAILED = 'payment_failed';

    /** Activation / conversion funnel in order (shown in Admin → Overview). */
    public const FUNNEL = [
        self::REGISTERED, self::ONBOARDING_STARTED, self::ONBOARDING_COMPLETED, self::FIRST_GOAL, self::FIRST_PROJECT, self::FIRST_TASK,
        self::FIRST_TASK_COMPLETED, self::FIRST_AI_REQUEST, self::FIRST_PLAN_GENERATED, self::FIRST_PLAN_APPLIED,
        self::TELEGRAM_CONNECTED, self::CALENDAR_CONNECTED, self::TRIAL_STARTED, self::CHECKOUT_STARTED,
        self::SUBSCRIPTION_STARTED, self::SUBSCRIPTION_UPGRADED, self::SUBSCRIPTION_CANCELED,
    ];

    /** @param array<string,scalar|null> $properties */
    public static function record(?User $user, string $event, array $properties = [], bool $once = false): void
    {
        try {
            if ($once && $user && ProductEvent::query()->where('user_id', $user->id)->where('event', $event)->exists()) {
                return;
            }
            ProductEvent::create([
                'user_id' => $user?->id,
                'event' => $event,
                'properties' => array_filter($properties, fn ($v) => is_scalar($v) || $v === null) ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e); // analytics must never break a user action
        }
    }
}
