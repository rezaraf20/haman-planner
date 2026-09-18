<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;

final class PlannerQueryService
{
    public function today(int $availableMinutes = 480): array
    {
        return app(DailyPlannerEngine::class)->build($availableMinutes);
    }

    public function progress(?int $goalId = null, ?int $projectId = null): array
    {
        if ($projectId !== null) {
            $project = Project::with(['goal', 'milestones'])->find($projectId);
            if (! $project) return ['found' => false, 'message' => 'Project not found.'];

            return [
                'found' => true,
                'type' => 'project',
                'id' => $project->id,
                'title' => $project->title,
                'progress' => (float) $project->progress,
                'health' => $project->health,
                'status' => $project->status,
                'target_date' => $project->target_date?->toDateString(),
                'milestones' => $project->milestones->map(fn ($m) => [
                    'id' => $m->id, 'title' => $m->title, 'progress' => (float) $m->progress,
                    'status' => $m->status, 'target_date' => $m->target_date?->toDateString(),
                ])->values()->all(),
            ];
        }

        if ($goalId !== null) {
            $goal = Goal::with(['projects'])->find($goalId);
            if (! $goal) return ['found' => false, 'message' => 'Goal not found.'];

            return [
                'found' => true,
                'type' => 'goal',
                'id' => $goal->id,
                'title' => $goal->title,
                'progress' => (float) $goal->progress,
                'health' => $goal->health,
                'status' => $goal->status,
                'target_date' => $goal->target_date?->toDateString(),
                'projects' => $goal->projects->map(fn ($p) => [
                    'id' => $p->id, 'title' => $p->title, 'progress' => (float) $p->progress,
                    'health' => $p->health, 'status' => $p->status,
                ])->values()->all(),
            ];
        }

        return [
            'found' => true,
            'type' => 'overview',
            'goals' => Goal::query()->whereIn('status', ['active', 'in_progress'])->orderByDesc('importance')->limit(20)
                ->get(['id','title','progress','health','status','target_date'])->toArray(),
            'projects' => Project::query()->whereIn('status', ['active', 'in_progress'])->orderByDesc('importance')->limit(20)
                ->get(['id','goal_id','title','progress','health','status','target_date'])->toArray(),
        ];
    }

    public function report(string $period = 'month'): array
    {
        $to = now();
        $from = match ($period) {
            'today', 'day' => $to->copy()->startOfDay(),
            'week' => $to->copy()->startOfWeek(),
            'month' => $to->copy()->startOfMonth(),
            default => $to->copy()->startOfMonth(),
        };

        return app(AnalyticsService::class)->summary($from, $to);
    }

    public function taskSearch(string $query): array
    {
        $tasks = Task::query()->where('title', 'like', '%'.$query.'%')
            ->orderByDesc('id')->limit(20)->get();

        return ['query' => $query, 'tasks' => $tasks->map(fn ($task) => [
            'id' => $task->id, 'title' => $task->title, 'status' => $task->status,
            'priority' => $task->priority, 'progress' => (float) $task->progress,
        ])->all()];
    }
}
