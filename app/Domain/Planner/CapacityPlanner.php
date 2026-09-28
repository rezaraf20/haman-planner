<?php

declare(strict_types=1);

namespace App\Domain\Planner;

final class CapacityPlanner
{
    public function __construct(private readonly float $bufferRatio = 0.20) {}

    /** The user's own planning buffer (Settings → Planning), 20% by default. */
    public static function forUser(?\App\Models\User $user): self
    {
        $percent = $user ? (int) $user->preference('planning_buffer_percent') : 20;
        return new self(max(0, min(60, $percent)) / 100);
    }

    public function bufferRatio(): float
    {
        return $this->bufferRatio;
    }

    public function usableMinutes(int $availableMinutes, int $committedMinutes = 0): int
    {
        $available = max(0, $availableMinutes - max(0, $committedMinutes));
        return (int) floor($available * (1 - $this->bufferRatio));
    }
}
