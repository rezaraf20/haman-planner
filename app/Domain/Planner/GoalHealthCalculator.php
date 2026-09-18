<?php
declare(strict_types=1);
namespace App\Domain\Planner;

use DateTimeInterface;

final class GoalHealthCalculator
{
    public function calculate(float $progress, DateTimeInterface $targetDate, DateTimeInterface $today, float $velocityRatio = 1.0): string
    {
        if ($progress >= 100) return 'completed';
        $daysRemaining = (int) $today->diff($targetDate)->format('%r%a');
        if ($daysRemaining < 0) return 'behind';
        if ($velocityRatio < 0.75) return 'at_risk';
        return $velocityRatio >= 0.95 ? 'on_track' : 'at_risk';
    }
}