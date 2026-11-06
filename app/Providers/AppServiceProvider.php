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
        // Secure cookies whenever the app is served over HTTPS, unless SESSION_SECURE_COOKIE says otherwise.
        if (config('session.secure') === null && str_starts_with((string) config('app.url'), 'https://')) {
            config(['session.secure' => true]);
        }
        if (config('app.force_https')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Observability: queued jobs log with their own job ID (plus the request ID they were dispatched from).
        \Illuminate\Support\Facades\Queue::before(function (\Illuminate\Queue\Events\JobProcessing $e): void {
            \Illuminate\Support\Facades\Context::add('job_id', (string) ($e->job->getJobId() ?? $e->job->uuid()));
            \Illuminate\Support\Facades\Context::add('job', $e->job->resolveName());
        });
        \Illuminate\Support\Facades\Queue::after(fn () => \Illuminate\Support\Facades\Context::forget(['job_id', 'job']));

        // Files of deleted tasks/projects are removed from storage too.
        // Before the row goes (the foreign keys would null task_id): no ghost calendar blocks — they would
        // also be exported to Google — and no reminders about a task that no longer exists.
        \App\Models\Task::deleting(function ($t) {
            \App\Models\ScheduleBlock::withoutGlobalScopes()->where('task_id', $t->id)->delete();
            \App\Models\Reminder::withoutGlobalScopes()->where('task_id', $t->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        });
        \App\Models\Task::deleted(fn ($t) => app(\App\Services\Planner\AttachmentService::class)->purge('task', (int) $t->id));
        \App\Models\Project::deleted(fn ($p) => app(\App\Services\Planner\AttachmentService::class)->purge('project', (int) $p->id));
    }
}
