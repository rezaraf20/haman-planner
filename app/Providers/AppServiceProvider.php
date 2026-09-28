<?php
declare(strict_types=1);

namespace App\Providers;

use App\Domain\Planner\CapacityPlanner;
use App\Models\User;
use App\Support\PlannerUserContext;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The planning buffer follows the current user's setting (20% unless they changed it).
        $this->app->bind(CapacityPlanner::class, function (): CapacityPlanner {
            $id = PlannerUserContext::id() ?? auth()->id();
            return CapacityPlanner::forUser($id !== null ? User::find($id) : null);
        });
    }

    public function boot(): void
    {
        // Files of deleted tasks/projects are removed from storage too.
        \App\Models\Task::deleted(fn ($t) => app(\App\Services\Planner\AttachmentService::class)->purge('task', (int) $t->id));
        \App\Models\Project::deleted(fn ($p) => app(\App\Services\Planner\AttachmentService::class)->purge('project', (int) $p->id));
    }
}
