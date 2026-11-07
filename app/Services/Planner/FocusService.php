<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Focus Mode (Pomodoro). Breaks run on the client; only focus time is recorded.
 *
 * - One active (running/paused) session per user; starting while one is active returns it
 *   instead of creating a second one. client_uuid makes a retried start idempotent.
 * - Elapsed time comes from server timestamps minus paused time, so a reload never loses time.
 * - Finishing a session that is linked to a task writes ONE execution log through the existing
 *   PlannerService::logTime path (actual minutes, activity history). Finishing twice is a no-op.
 * - Cancelled sessions record nothing against the task.
 */
final class FocusService
{
    /** Shorter than this is noise (a mis-tap), so no time is logged against the task. */
    public const MIN_LOG_SECONDS = 60;

    /** Running/paused sessions older than this are closed as "interrupted" by the scheduler. */
    public const STALE_HOURS = 12;

    public function __construct(private readonly PlannerService $planner, private readonly ActivityLogger $activity) {}

    public function current(): ?FocusSession
    {
        return FocusSession::query()->with(['task:id,title,project_id', 'project:id,title'])->whereIn('status', FocusSession::ACTIVE)->latest('id')->first();
    }

    /** @param array{task_id?:int|null,project_id?:int|null,planned_minutes:int,client_uuid?:string|null} $data */
    public function start(User $user, array $data): FocusSession
    {
        return DB::transaction(function () use ($user, $data) {
            if (!empty($data['client_uuid'])) {
                $same = FocusSession::query()->where('client_uuid', $data['client_uuid'])->first();
                if ($same) return $same->load(['task:id,title,project_id', 'project:id,title']);
            }
            // Lock the user's row so two parallel starts cannot both pass the "no active session" check.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($active = $this->current()) {
                return $active;
            }
            $projectId = $data['project_id'] ?? null;
            if (!empty($data['task_id']) && $projectId === null) {
                $projectId = Task::query()->whereKey($data['task_id'])->value('project_id');
            }
            $session = FocusSession::create([
                'user_id' => $user->id,
                'task_id' => $data['task_id'] ?? null,
                'project_id' => $projectId,
                'planned_minutes' => max(1, min(240, (int) $data['planned_minutes'])),
                'status' => 'running',
                'started_at' => now(),
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);
            return $session->load(['task:id,title,project_id', 'project:id,title']);
        });
    }

    public function pause(FocusSession $s): FocusSession
    {
        if ($s->status === 'running') {
            $s->update(['status' => 'paused', 'paused_at' => now()]);
        }
        return $s->refresh();
    }

    public function resume(FocusSession $s): FocusSession
    {
        if ($s->status === 'paused' && $s->paused_at) {
            $s->update(['status' => 'running', 'paused_seconds' => $s->paused_seconds + (int) $s->paused_at->diffInSeconds(now()), 'paused_at' => null]);
        }
        return $s->refresh();
    }

    /** Finish (on time or early). Time is logged against the task once. */
    public function finish(FocusSession $s, ?string $note = null, string $status = 'completed'): FocusSession
    {
        return DB::transaction(function () use ($s, $note, $status) {
            $s = FocusSession::query()->whereKey($s->id)->lockForUpdate()->first();
            if (!$s->isActive()) {
                return $s; // already finished/cancelled: idempotent
            }
            $end = $s->paused_at ?? now();
            $seconds = min($s->elapsed_seconds, $s->planned_minutes * 60 * 3); // a forgotten timer cannot log days
            $s->fill(['status' => $status, 'ended_at' => $end, 'actual_seconds' => $seconds, 'paused_at' => null, 'note' => $note ?? $s->note])->save();

            if ($s->task_id && $seconds >= self::MIN_LOG_SECONDS && ($task = Task::find($s->task_id))) {
                $log = $this->planner->logTime($task, [
                    'started_at' => $s->started_at,
                    'ended_at' => $s->started_at->copy()->addSeconds($seconds + $s->paused_seconds),
                    'duration_minutes' => (int) round($seconds / 60),
                    'notes' => trim(__('focus.log_note').($note ? ' — '.$note : '')),
                ]);
                $s->update(['execution_log_id' => $log->id]);
            }
            $this->activity->log('focus_'.$status, FocusSession::class, $s->id, null,
                ['task_id' => $s->task_id, 'minutes' => (int) round($seconds / 60)]);
            return $s->refresh()->load(['task:id,title,project_id', 'project:id,title']);
        });
    }

    public function cancel(FocusSession $s): FocusSession
    {
        if ($s->isActive()) {
            $s->update(['status' => 'cancelled', 'ended_at' => $s->paused_at ?? now(), 'actual_seconds' => $s->elapsed_seconds, 'paused_at' => null]);
        }
        return $s->refresh();
    }

    /** Close sessions someone forgot about. Their time is NOT logged (we cannot know it was real). */
    public function closeStale(): int
    {
        $n = 0;
        FocusSession::withoutGlobalScopes()->whereIn('status', FocusSession::ACTIVE)
            ->where('started_at', '<', now()->subHours(self::STALE_HOURS))
            ->each(function (FocusSession $s) use (&$n) {
                $seconds = min($s->elapsed_seconds, $s->planned_minutes * 60);
                $s->forceFill(['status' => 'interrupted', 'ended_at' => $s->started_at->copy()->addSeconds($seconds), 'actual_seconds' => $seconds, 'paused_at' => null])->save();
                $n++;
            });
        return $n;
    }

    /**
     * Focus statistics in the user's timezone: today, the last $days days (per day), totals.
     * Only completed and interrupted-early ("completed" status) sessions count as focus time.
     */
    public function stats(User $user, int $days = 7): array
    {
        $tz = $user->preferredTimezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $from = $today->subDays($days - 1);
        $rows = FocusSession::query()->where('status', 'completed')
            ->where('started_at', '>=', $from->utc())->get(['started_at', 'actual_seconds']);

        $perDay = [];
        for ($d = $from; $d <= $today; $d = $d->addDay()) $perDay[$d->toDateString()] = ['date' => $d->toDateString(), 'minutes' => 0, 'sessions' => 0];
        foreach ($rows as $r) {
            $k = $r->started_at->copy()->setTimezone($tz)->toDateString();
            if (!isset($perDay[$k])) continue;
            $perDay[$k]['minutes'] += intdiv((int) $r->actual_seconds, 60);
            $perDay[$k]['sessions']++;
        }
        $total = array_sum(array_column($perDay, 'minutes'));
        $count = array_sum(array_column($perDay, 'sessions'));
        $t = $perDay[$today->toDateString()];

        return [
            'today_minutes' => $t['minutes'],
            'today_sessions' => $t['sessions'],
            'total_minutes' => $total,
            'sessions' => $count,
            'average_minutes' => $count ? (int) round($total / $count) : null,
            'days' => array_values($perDay),
        ];
    }

    /** Settings with safe bounds, from the user's preferences. */
    public static function settings(User $user): array
    {
        return [
            'focus_minutes' => (int) $user->preference('focus_minutes'),
            'break_minutes' => (int) $user->preference('focus_break_minutes'),
            'long_break_minutes' => (int) $user->preference('focus_long_break_minutes'),
            'long_break_every' => (int) $user->preference('focus_long_break_every'),
            'sound' => (bool) $user->preference('focus_sound'),
            'notify' => (bool) $user->preference('focus_notify'),
        ];
    }

    /** @return array<string,mixed> validated preference values */
    public static function validateSettings(array $input): array
    {
        $v = validator($input, [
            'focus_minutes' => 'required|integer|min:5|max:180',
            'break_minutes' => 'required|integer|min:1|max:60',
            'long_break_minutes' => 'required|integer|min:1|max:90',
            'long_break_every' => 'required|integer|min:2|max:12',
            'sound' => 'sometimes|boolean',
            'notify' => 'sometimes|boolean',
        ]);
        if ($v->fails()) throw new ValidationException($v);
        $d = $v->validated();
        return ['focus_minutes' => (int) $d['focus_minutes'], 'focus_break_minutes' => (int) $d['break_minutes'],
            'focus_long_break_minutes' => (int) $d['long_break_minutes'], 'focus_long_break_every' => (int) $d['long_break_every'],
            'focus_sound' => (bool) ($d['sound'] ?? true), 'focus_notify' => (bool) ($d['notify'] ?? false)];
    }
}
