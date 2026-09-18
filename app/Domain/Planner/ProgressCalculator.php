<?php

declare(strict_types=1);

namespace App\Domain\Planner;

final class ProgressCalculator
{
    /** @param array<int, array{progress: float|int, weight: float|int}> $children */
    public function calculate(array $children): float
    {
        if ($children === []) {
            return 0.0;
        }

        $weighted = 0.0;
        $weights = 0.0;

        foreach ($children as $child) {
            $weight = max(0.0, (float) $child['weight']);
            $progress = min(100.0, max(0.0, (float) $child['progress']));
            $weighted += $progress * $weight;
            $weights += $weight;
        }

        return $weights > 0 ? round($weighted / $weights, 2) : 0.0;
    }
}
