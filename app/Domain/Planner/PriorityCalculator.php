<?php
declare(strict_types=1);
namespace App\Domain\Planner;

final class PriorityCalculator
{
    public function calculate(
        int $importance,
        int $goalImportance = 0,
        float $deadlineUrgency = 0,
        float $contribution = 0,
        float $dependencyImpact = 0,
        bool $overdue = false,
        int $effortMinutes = 0,
        ?string $manualOverride = null
    ): string {
        if ($manualOverride !== null && in_array($manualOverride, ['p0','p1','p2','p3'], true)) {
            return $manualOverride;
        }
        $score = ($importance * 0.25) + ($goalImportance * 0.15)
            + (min(100, max(0, $deadlineUrgency)) * 0.20)
            + (min(100, max(0, $contribution)) * 0.20)
            + (min(100, max(0, $dependencyImpact)) * 0.10)
            + ($overdue ? 15 : 0) - min(10, $effortMinutes / 120);
        return match (true) {
            $score >= 80 => 'p0',
            $score >= 60 => 'p1',
            $score >= 35 => 'p2',
            default => 'p3',
        };
    }
}