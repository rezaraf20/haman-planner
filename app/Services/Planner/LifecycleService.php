<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Area;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Complete, reopen, cancel, archive and restore goals, projects, milestones and areas.
 *
 * Nothing about child tasks changes unless the user explicitly asks for it
 * ($cancelOpenTasks): open tasks are then marked cancelled — never deleted.
 */
final class LifecycleService
{
    public const KINDS = ['goals' => Goal::class, 'projects' => Project::class, 'milestones' => Milestone::class, 'areas' => Area::class];

    public function __construct(
        private readonly ProgressPropagationService $progress,
        private readonly ActivityLogger $activity,
    ) {}

    /** Open tasks that hang directly under this item (what "cancel open tasks" would touch). */
    public function openTasks(Model $item): Builder
    {
        $col = match (true) {
            $item instanceof Project => 'project_id',
            $item instanceof Milestone => 'milestone_id',
            $item instanceof Goal => 'goal_id',
            default => 'area_id',
        };
        return Task::query()->where($col, $item->getKey())->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function apply(Model $item, string $action, bool $cancelOpenTasks = false): Model
    {
        return DB::transaction(function () use ($item, $action, $cancelOpenTasks) {
            $before = ['status' => $item->status, 'archived_at' => $item->archived_at?->toIso8601String()];
            $statuses = $item::STATUSES;
            $isArea = $item instanceof Area;

            match ($action) {
                'complete' => $isArea ? null : $item->status = 'completed',
                'cancel' => $isArea ? null : $item->status = 'cancelled',
                'reopen' => $isArea ? null : $item->status = $statuses[0],
                'archive' => $item->archived_at = now(),
                'unarchive' => $item->archived_at = null,
            };
            $item->save();

            $cancelled = 0;
            if ($cancelOpenTasks && in_array($action, ['complete', 'cancel', 'archive'], true)) {
                foreach ($this->openTasks($item)->get() as $task) {
                    $task->update(['status' => 'cancelled']);
                    $cancelled++;
                }
            }

            $this->recalculate($item);
            $this->activity->log('lifecycle_'.$action, $item::class, (int) $item->getKey(), $before,
                ['status' => $item->status, 'archived_at' => $item->archived_at?->toIso8601String(), 'tasks_cancelled' => $cancelled]);

            return $item->refresh();
        });
    }

    private function recalculate(Model $item): void
    {
        if ($item instanceof Milestone) {
            $this->progress->milestone($item);
            if ($p = $item->project()->first()) {
                $this->progress->project($p);
                if ($g = $p->goal()->first()) $this->progress->goal($g);
            }
        } elseif ($item instanceof Project) {
            $this->progress->project($item);
            if ($g = $item->goal()->first()) $this->progress->goal($g);
        } elseif ($item instanceof Goal) {
            $this->progress->goal($item);
        }
    }
}
