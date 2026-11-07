<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Goal;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Area;
use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Task filters as data. A definition is a small JSON object that is validated here (unknown
 * keys are rejected, referenced ids must belong to the user) and then applied with the query
 * builder — never interpolated into SQL. The same validator guards filters produced by AI.
 *
 * Keys: state (open|closed|all), due (today|tomorrow|overdue|week|upcoming|none),
 *       priority [p0..p3], tags [ids] + tag_mode (any|all), project_id, goal_id, area_id,
 *       no_project, min_minutes, max_minutes, min_importance, blocked (true|false), text.
 */
final class TaskFilter
{
    public const PRESETS = [
        'today' => ['due' => 'today'],
        'tomorrow' => ['due' => 'tomorrow'],
        'overdue' => ['due' => 'overdue'],
        'upcoming' => ['due' => 'week'],
        'no_deadline' => ['due' => 'none'],
        'high_priority' => ['priority' => ['p0', 'p1']],
        'high_contribution' => ['min_importance' => 70],
        'quick' => ['max_minutes' => 30],
        'deep_work' => ['min_minutes' => 60],
        'blocked' => ['blocked' => true],
        'no_project' => ['no_project' => true],
    ];

    private const KEYS = ['state', 'due', 'priority', 'tags', 'tag_mode', 'project_id', 'goal_id', 'area_id', 'no_project',
        'min_minutes', 'max_minutes', 'min_importance', 'blocked', 'text'];

    /** @return array<string,mixed> a clean definition (throws 422 with a readable message otherwise) */
    public function validate(array $def, User $user): array
    {
        $unknown = array_diff(array_keys($def), self::KEYS);
        if ($unknown) {
            throw ValidationException::withMessages(['definition' => __('filters.unknown_key', ['keys' => implode(', ', $unknown)])]);
        }
        $v = validator($def, [
            'state' => 'nullable|in:open,closed,all',
            'due' => 'nullable|in:today,tomorrow,overdue,week,upcoming,none',
            'priority' => 'nullable|array|max:4', 'priority.*' => 'in:p0,p1,p2,p3',
            'tags' => 'nullable|array|max:20', 'tags.*' => 'integer',
            'tag_mode' => 'nullable|in:any,all',
            'project_id' => 'nullable|integer', 'goal_id' => 'nullable|integer', 'area_id' => 'nullable|integer',
            'no_project' => 'nullable|boolean', 'blocked' => 'nullable|boolean',
            'min_minutes' => 'nullable|integer|min:0|max:10000', 'max_minutes' => 'nullable|integer|min:0|max:10000',
            'min_importance' => 'nullable|integer|min:0|max:100',
            'text' => 'nullable|string|max:100',
        ]);
        if ($v->fails()) throw new ValidationException($v);
        $d = array_filter($v->validated(), fn ($x) => $x !== null && $x !== [] && $x !== '');

        // Referenced records must exist and belong to this user.
        $own = fn (string $class, $id) => $class::withoutGlobalScopes()->whereKey($id)->where('user_id', $user->id)->exists();
        foreach (['project_id' => Project::class, 'goal_id' => Goal::class, 'area_id' => Area::class] as $k => $class) {
            if (isset($d[$k]) && !$own($class, $d[$k])) throw ValidationException::withMessages([$k => __('filters.not_found')]);
        }
        if (!empty($d['tags'])) {
            $d['tags'] = array_values(array_unique(array_map('intval', $d['tags'])));
            $found = Tag::withoutGlobalScopes()->where('user_id', $user->id)->whereIn('id', $d['tags'])->count();
            if ($found !== count($d['tags'])) throw ValidationException::withMessages(['tags' => __('filters.not_found')]);
        }
        return $d;
    }

    public function apply(Builder $q, array $d, User $user): Builder
    {
        $tz = $user->preferredTimezone();
        $now = CarbonImmutable::now($tz);
        $day = fn (CarbonImmutable $x) => [LocalDate::db($x->startOfDay()), LocalDate::db($x->endOfDay())];

        match ($d['state'] ?? 'open') {
            'open' => $q->whereNotIn('status', ['completed', 'cancelled']),
            'closed' => $q->whereIn('status', ['completed', 'cancelled']),
            default => null,
        };
        switch ($d['due'] ?? null) {
            case 'today': $q->whereBetween('deadline', $day($now)); break;
            case 'tomorrow': $q->whereBetween('deadline', $day($now->addDay())); break;
            case 'overdue': $q->whereNotNull('deadline')->where('deadline', '<', LocalDate::db($now)); break;
            case 'week': case 'upcoming': $q->whereBetween('deadline', [LocalDate::db($now), LocalDate::db($now->addDays(7)->endOfDay())]); break;
            case 'none': $q->whereNull('deadline'); break;
        }
        if (!empty($d['priority'])) $q->whereIn('priority', $d['priority']);
        if (!empty($d['tags'])) {
            if (($d['tag_mode'] ?? 'any') === 'all') {
                foreach ($d['tags'] as $id) $q->whereHas('tags', fn ($t) => $t->whereKey($id));
            } else {
                $q->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $d['tags']));
            }
        }
        foreach (['project_id', 'goal_id', 'area_id'] as $k) if (isset($d[$k])) $q->where($k, $d[$k]);
        if (!empty($d['no_project'])) $q->whereNull('project_id');
        if (isset($d['min_minutes'])) $q->where('estimated_minutes', '>=', $d['min_minutes']);
        if (isset($d['max_minutes'])) $q->where('estimated_minutes', '>', 0)->where('estimated_minutes', '<=', $d['max_minutes']); // 0 = no estimate
        if (isset($d['min_importance'])) $q->where('importance', '>=', $d['min_importance']);
        if (array_key_exists('blocked', $d)) {
            // Blocked = marked blocked, or waiting on a task that is not finished yet.
            $blocked = fn ($w) => $w->where('status', 'blocked')->orWhereExists(fn ($e) => $e->selectRaw('1')->from('task_dependencies as td')
                ->join('tasks as dep', 'dep.id', '=', 'td.depends_on_task_id')
                ->whereColumn('td.task_id', 'tasks.id')->whereNotIn('dep.status', ['completed', 'cancelled']));
            $d['blocked'] ? $q->where($blocked) : $q->whereNot($blocked);
        }
        if (!empty($d['text'])) {
            $like = '%'.addcslashes($d['text'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('title', 'ilike', $like)->orWhere('description', 'ilike', $like));
        }
        return $q;
    }

    /**
     * Natural language → definition, through AI. The answer is only a proposal: it is validated
     * with the same rules as a hand-made filter and is never executed by the server on its own.
     */
    public function fromText(string $text, User $user): array
    {
        $tags = Tag::query()->orderBy('name')->limit(100)->get(['id', 'name'])->toArray();
        $projects = Project::query()->open()->latest('id')->limit(60)->get(['id', 'title'])->toArray();
        $goals = Goal::query()->open()->latest('id')->limit(40)->get(['id', 'title'])->toArray();
        $schema = 'Keys (all optional): state open|closed|all; due today|tomorrow|overdue|week|none; priority array of p0 (critical) p1 (high) p2 (normal) p3 (low); '
            .'tags array of tag ids; tag_mode any|all; project_id; goal_id; no_project boolean; min_minutes / max_minutes (estimated duration); '
            .'min_importance 0-100 (contribution to goals; "important" ≈ 70); blocked boolean; text (words to search in title).';
        $ent = app(\App\Services\Billing\Entitlements::class);
        $ent->ensureFeature($user, 'ai_planner');
        $ent->consume($user, 'ai_requests');
        try {
            $r = \App\Services\AI\AIProviderFactory::make()->chat([
                ['role' => 'system', 'content' => 'Convert the user request into a task filter JSON object. '.$schema.' Use only ids from the lists given; never invent ids. If something cannot be expressed, leave it out. Return only the JSON object.'],
                ['role' => 'user', 'content' => json_encode(['request' => $text, 'tags' => $tags, 'projects' => $projects, 'goals' => $goals], JSON_UNESCAPED_UNICODE)],
            ], ['temperature' => 0, 'response_format' => ['type' => 'json_object'], '_feature' => 'smart_filter']);
        } catch (\Throwable $e) {
            $ent->refund($user, 'ai_requests');
            throw $e;
        }
        $def = json_decode((string) ($r['choices'][0]['message']['content'] ?? ''), true);
        if (!is_array($def)) {
            throw ValidationException::withMessages(['text' => __('filters.ai_unclear')]);
        }
        $def = array_intersect_key($def, array_flip(self::KEYS)); // drop anything else the model added
        return $this->validate($def, $user);
    }
}
