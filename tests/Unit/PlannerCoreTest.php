<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Planner\CapacityPlanner;
use App\Domain\Planner\DependencyValidator;
use App\Domain\Planner\GoalHealthCalculator;
use App\Domain\Planner\PriorityCalculator;
use App\Domain\Planner\TaskStatus;
use PHPUnit\Framework\TestCase;

final class PlannerCoreTest extends TestCase
{
    public function test_task_status_enum_covers_deferred_state(): void
    {
        $this->assertSame('deferred', TaskStatus::Deferred->value);
        $this->assertSame('completed', TaskStatus::Completed->value);
    }

    public function test_capacity_reserves_twenty_percent_buffer(): void
    {
        $this->assertSame(400, (new CapacityPlanner())->usableMinutes(500));
        $this->assertSame(320, (new CapacityPlanner())->usableMinutes(500, 100));
    }

    public function test_capacity_never_returns_negative_minutes(): void
    {
        $this->assertSame(0, (new CapacityPlanner())->usableMinutes(-10));
        $this->assertSame(0, (new CapacityPlanner())->usableMinutes(60, 100));
    }

    public function test_dependency_validator_blocks_non_completed_dependencies(): void
    {
        $validator = new DependencyValidator();
        $this->assertTrue($validator->canStart('ready', ['completed', 'completed']));
        $this->assertFalse($validator->canStart('ready', ['completed', 'blocked']));
    }

    public function test_dependency_validator_rejects_completed_or_cancelled_task(): void
    {
        $validator = new DependencyValidator();
        $this->assertFalse($validator->canStart('completed', []));
        $this->assertFalse($validator->canStart('cancelled', []));
    }

    public function test_priority_manual_override_wins(): void
    {
        $calculator = new PriorityCalculator();
        $this->assertSame('p3', $calculator->calculate(100, 100, 100, 100, 100, true, 1, 'p3'));
    }

    public function test_priority_score_clamps_inputs(): void
    {
        $calculator = new PriorityCalculator();
        $this->assertSame('p0', $calculator->calculate(100, 100, 999, 999, 999, true, 0));
    }

    public function test_goal_health_marks_completed_goal(): void
    {
        $calculator = new GoalHealthCalculator();
        $today = new \DateTimeImmutable('2026-09-18');
        $this->assertSame('completed', $calculator->calculate(100, $today, $today));
    }

    public function test_goal_health_marks_overdue_goal_behind(): void
    {
        $calculator = new GoalHealthCalculator();
        $target = new \DateTimeImmutable('2026-09-17');
        $today = new \DateTimeImmutable('2026-09-18');
        $this->assertSame('behind', $calculator->calculate(50, $target, $today));
    }

    public function test_goal_health_marks_low_velocity_at_risk(): void
    {
        $calculator = new GoalHealthCalculator();
        $target = new \DateTimeImmutable('2026-10-01');
        $today = new \DateTimeImmutable('2026-09-18');
        $this->assertSame('at_risk', $calculator->calculate(40, $target, $today, 0.50));
    }

    public function test_goal_health_marks_healthy_velocity_on_track(): void
    {
        $calculator = new GoalHealthCalculator();
        $target = new \DateTimeImmutable('2026-10-01');
        $today = new \DateTimeImmutable('2026-09-18');
        $this->assertSame('on_track', $calculator->calculate(40, $target, $today, 1.00));
    }
}
