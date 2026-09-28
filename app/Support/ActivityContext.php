<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Who is making the current change, for the activity timeline.
 * Changes applied from an AI proposal run inside ActivityContext::asAi(), so the timeline can
 * mark them as AI actions (always ones the user explicitly confirmed).
 */
final class ActivityContext
{
    private static ?string $actor = null;

    /** @template T @param callable():T $fn @return T */
    public static function asAi(callable $fn): mixed
    {
        $previous = self::$actor;
        self::$actor = 'ai';
        try {
            return $fn();
        } finally {
            self::$actor = $previous;
        }
    }

    /** user | ai | system */
    public static function actor(): string
    {
        if (self::$actor !== null) {
            return self::$actor;
        }
        return PlannerUserContext::id() !== null || auth()->id() !== null ? 'user' : 'system';
    }

    /** web | api | telegram | system */
    public static function channel(): string
    {
        if (PlannerUserContext::id() !== null) {
            return 'telegram';
        }
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return 'system';
        }
        $request = request();
        if ($request->is('api/*')) {
            return $request->bearerToken() ? 'api' : 'web';
        }
        return auth()->id() !== null ? 'web' : 'system';
    }
}
