<?php
declare(strict_types=1);
namespace App\Services\Planner;
use App\Domain\Planner\CapacityPlanner;
use App\Models\DailyPlan;

final class DailyPlanService
{
    public function __construct(private readonly CapacityPlanner $capacity) {}

    public function prepare(DailyPlan $plan): DailyPlan
    {
        $usable = $this->capacity->usableMinutes((int)$plan->available_minutes);
        $plan->buffer_minutes = max(0, (int)$plan->available_minutes - $usable);
        $plan->planned_minutes = min((int)$plan->planned_minutes, $usable);
        $plan->save();
        return $plan->refresh();
    }
}