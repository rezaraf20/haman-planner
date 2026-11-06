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
        $progress = $milestone->status === 'completed' ? 100.0 : $this->weighted($milestone->tasks()->get());
        $milestone->update(['progress' => $progress]);
        return $milestone->refresh();
    }

    public function project(Project $project): Project
    {
        // Milestones plus the tasks that sit directly on the project (not inside a milestone).
        $children = $this->counted($project->milestones()->get())
            ->concat($project->tasks()->whereNull('milestone_id')->get());
        $progress = $project->status === 'completed' ? 100.0 : $this->weighted($children);
        $project->update(['progress' => $progress]);
        return $project->refresh();
    }

    public function goal(Goal $goal): Goal
    {
        // Projects plus the tasks that sit directly on the goal (not inside a project).
        $children = $this->counted($goal->projects()->get())
            ->concat($goal->tasks()->whereNull('project_id')->get());
        $progress = $goal->status === 'completed' ? 100.0 : $this->weighted($children);
        $goal->update(['progress' => $progress]);
        return $goal->refresh();
    }

    /** Cancelled or archived-without-completion children don't count towards (or against) progress. */
    private function counted(\Illuminate\Support\Collection $children): \Illuminate\Support\Collection
    {
        return $children->reject(fn ($c) => $c->status === 'cancelled' || (($c->archived_at ?? null) !== null && $c->status !== 'completed'))->values();
    }

    private function weighted(iterable $children): float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($children as $child) {
            if (($child->status ?? null) === 'cancelled') continue;
            $weight = max(0.0, (float) ($child->weight ?? 1));
            $sum += (float) ($child->progress ?? 0) * $weight;
            $weights += $weight;
        }
        return $weights > 0 ? round($sum / $weights, 2) : 0.0;
    }
}
