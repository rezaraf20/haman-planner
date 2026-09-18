<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Services\Planner\ProgressPropagationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProgressPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_progress_propagates_to_milestone_project_and_goal(): void
    {
        $goal = Goal::create(['title' => 'Goal', 'weight' => 1]);
        $project = Project::create(['goal_id' => $goal->id, 'title' => 'Project', 'weight' => 1]);
        $milestone = Milestone::create(['project_id' => $project->id, 'title' => 'Milestone', 'weight' => 1]);
        Task::create(['goal_id' => $goal->id, 'project_id' => $project->id, 'milestone_id' => $milestone->id, 'title' => 'A', 'progress' => 100, 'weight' => 1]);
        Task::create(['goal_id' => $goal->id, 'project_id' => $project->id, 'milestone_id' => $milestone->id, 'title' => 'B', 'progress' => 50, 'weight' => 1]);

        app(ProgressPropagationService::class)->recalculateFromTask(Task::first());

        $this->assertSame('75.00', $milestone->fresh()->progress);
        $this->assertSame('75.00', $project->fresh()->progress);
        $this->assertSame('75.00', $goal->fresh()->progress);
    }
}
