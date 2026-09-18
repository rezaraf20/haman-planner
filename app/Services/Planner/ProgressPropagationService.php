<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;

final class ProgressPropagationService
{
    public function recalculateFromTask(Task $task): void
    {
        if ($task->milestone_id) {
            $milestone = $task->milestone()->first();
            if ($milestone) $this->milestone($milestone);
        }

        if ($task->project_id) {
            $project = $task->project()->first();
            if ($project) $this->project($project);
        }

        if ($task->goal_id) {
            $goal = $task->goal()->first();
            if ($goal) $this->goal($goal);
        }
    }

    public function milestone(Milestone $milestone): Milestone
    {
        $progress = $this->weighted($milestone->tasks()->get());
        $milestone->update(['progress' => $progress]);
        return $milestone->refresh();
    }

    public function project(Project $project): Project
    {
        $milestones = $project->milestones()->get();
        $children = $milestones->isNotEmpty() ? $milestones : $project->tasks()->get();
        $progress = $this->weighted($children);
        $project->update(['progress' => $progress]);
        return $project->refresh();
    }

    public function goal(Goal $goal): Goal
    {
        $projects = $goal->projects()->get();
        $children = $projects->isNotEmpty() ? $projects : $goal->tasks()->get();
        $progress = $this->weighted($children);
        $goal->update(['progress' => $progress]);
        return $goal->refresh();
    }

    private function weighted(iterable $children): float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($children as $child) {
            $weight = max(0.0, (float) ($child->weight ?? 1));
            $sum += (float) ($child->progress ?? 0) * $weight;
            $weights += $weight;
        }
        return $weights > 0 ? round($sum / $weights, 2) : 0.0;
    }
}
