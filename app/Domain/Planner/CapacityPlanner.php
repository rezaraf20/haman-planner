<?php

declare(strict_types=1);

namespace App\Domain\Planner;

final class CapacityPlanner
{
    public function __construct(private readonly float $bufferRatio = 0.20) {}

    public function usableMinutes(int $availableMinutes, int $committedMinutes = 0): int
    {
        $available = max(0, $availableMinutes - max(0, $committedMinutes));
        return (int) floor($available * (1 - $this->bufferRatio));
    }
}
