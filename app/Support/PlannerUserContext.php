<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Request-scoped planner owner for code paths that do not use Laravel Auth
 * (e.g. the Telegram webhook). Always clear it in a finally block.
 */
final class PlannerUserContext
{
    private static ?int $userId = null;

    public static function set(?int $userId): void
    {
        self::$userId = $userId !== null && $userId > 0 ? $userId : null;
    }

    public static function id(): ?int
    {
        return self::$userId;
    }

    public static function clear(): void
    {
        self::$userId = null;
    }

    /**
     * Run a callback with the given user as planner owner, restoring the previous value afterwards.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function runAs(int $userId, callable $callback): mixed
    {
        $previous = self::$userId;
        self::set($userId);
        try {
            return $callback();
        } finally {
            self::$userId = $previous;
        }
    }
}
