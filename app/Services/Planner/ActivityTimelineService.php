<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;

/**
 * User-facing activity timeline built from the audit log. Only planner entities the user owns
 * are shown (internal/admin events never are), each entry says who did it (you, Haman AI after
 * your confirmation, or the system) and through which channel.
 */
final class ActivityTimelineService
{
    /** entity class => short type used for labels and title lookup */
    private const TYPES = [
        \App\Models\Task::class => 'task', \App\Models\Goal::class => 'goal', \App\Models\Project::class => 'project',
        \App\Models\Milestone::class => 'milestone', \App\Models\Note::class => 'note', \App\Models\Decision::class => 'decision',
        \App\Models\Reminder::class => 'reminder', \App\Models\ScheduleBlock::class => 'schedule', \App\Models\RecurringTask::class => 'recurring',
        \App\Models\Area::class => 'area',
    ];

    public function timeline(User $user, ?int $beforeId = null, int $limit = 50, ?string $actor = null): array
    {
        $rows = ActivityLog::query()->ownedBy($user->id)
            ->whereIn('entity_type', array_keys(self::TYPES))
            ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
            ->when(in_array($actor, ['user', 'ai', 'system'], true), fn ($q) => $q->where('actor_type', $actor))
            ->orderByDesc('id')->limit(min(100, max(1, $limit)))->get();

        // Current titles in one query per type (entries keep working after a rename).
        $titles = [];
        foreach ($rows->groupBy('entity_type') as $class => $group) {
            $ids = $group->pluck('entity_id')->filter()->unique()->all();
            $col = in_array($class, [\App\Models\Area::class], true) ? 'name' : 'title';
            if ($class === \App\Models\ScheduleBlock::class || $class === \App\Models\Reminder::class) continue;
            $titles[$class] = $class::query()->ownedBy($user->id)->whereIn('id', $ids)->pluck($col, 'id')->all();
        }

        $tz = $user->preferredTimezone();
        $loc = $user->preferredLocale();
        $items = $rows->map(function (ActivityLog $r) use ($titles, $tz, $loc) {
            $type = self::TYPES[$r->entity_type];
            $title = $titles[$r->entity_type][$r->entity_id] ?? ($r->after_json['title'] ?? $r->before_json['title'] ?? null);
            $at = CarbonImmutable::instance($r->created_at)->setTimezone($tz);
            $params = ['item' => __('planner.entities.'.$type, [], $loc), 'title' => $title ? '«'.$title.'»' : ''];
            if ($r->action === 'time_logged') {
                $params['duration'] = SchedulingService::duration((int) ($r->after_json['duration_minutes'] ?? 0), $loc);
            }
            if (in_array($r->action, ['rescheduled', 'scheduled'], true) && !empty($r->after_json['planned_start'] ?? $r->after_json['starts_at'] ?? null)) {
                $params['when'] = LocalDate::short($r->after_json['planned_start'] ?? $r->after_json['starts_at'], $loc, $tz, true);
            }
            $key = 'activity.action.'.$r->action;
            $text = __($key, $params, $loc);
            if ($text === $key) {
                $text = __('activity.action.changed', $params, $loc);
            }
            return [
                'id' => $r->id,
                'at' => $at->toIso8601String(),
                'day' => $at->toDateString(),
                'time' => LocalDate::time($at, $loc, $tz),
                'actor' => in_array($r->actor_type, ['user', 'ai', 'system'], true) ? $r->actor_type : 'system',
                'channel' => $r->channel,
                'action' => $r->action,
                'entity' => $type,
                'entity_id' => $r->entity_id !== null ? (int) $r->entity_id : null,
                'text' => trim(preg_replace('/\s+/u', ' ', (string) $text)),
            ];
        })->values();

        $today = CarbonImmutable::now($tz)->toDateString();
        $yesterday = CarbonImmutable::now($tz)->subDay()->toDateString();
        $groups = $items->groupBy('day')->map(fn ($list, $day) => [
            'day' => $day,
            'label' => $day === $today ? __('activity.today', [], $loc) : ($day === $yesterday ? __('activity.yesterday', [], $loc)
                : LocalDate::weekday(CarbonImmutable::parse($day, $tz), $loc).' '.LocalDate::short(CarbonImmutable::parse($day, $tz), $loc, $tz, false)),
            'items' => $list->values()->all(),
        ])->values()->all();

        return ['groups' => $groups, 'next_before' => $rows->count() === $limit ? $rows->last()?->id : null];
    }
}
