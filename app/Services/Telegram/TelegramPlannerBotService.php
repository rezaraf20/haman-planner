<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use App\Domain\Planner\TaskStatus;
use App\Models\ActivityLog;
use App\Models\AiInteraction;
use App\Models\Area;
use App\Models\DailyPlan;
use App\Models\Decision;
use App\Models\ExecutionLog;
use App\Models\FailureReason;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Note;
use App\Models\PendingAction;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Review;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\AI\AIPlannerService;
use App\Services\Planner\AnalyticsService;
use App\Services\Planner\DependencyService;
use App\Services\Planner\PlannerService;
use App\Services\Planner\ProgressPropagationService;
use App\Services\Planner\ReviewService;
use App\Exceptions\PlanLimitReached;
use App\Services\Analytics\ProductEvents;
use App\Services\Billing\Entitlements;
use App\Support\LocalDate;
use App\Support\Locales;
use App\Support\PlannerUserContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Button-driven Telegram front-end for Haman Planner.
 *
 * Every user-owned query is scoped to the authenticated Telegram user (user_id).
 * IDs coming from callback_data are never trusted without an ownership check.
 */
final class TelegramPlannerBotService
{
    private const TTL = 1800;
    private const PAGE = 8;
    /** Task fields used by PlannerService::recalculatePriority(). */
    private const PRIORITY_INPUTS = ['importance', 'goal_id', 'deadline', 'progress', 'estimated_minutes', 'status'];

    private ?User $user = null;

    /** Translate a bot message in the current (user's) locale. */
    private function t(string $key, array $replace = []): string
    {
        return (string) __('bot.'.$key, $replace);
    }

    private function tz(): string
    {
        return $this->user?->preferredTimezone() ?? (string) config('app.timezone');
    }

    /** Runs $fn in $locale and restores the previous locale. */
    private function inLocale(string $locale, callable $fn): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $fn();
        } finally {
            app()->setLocale($previous);
        }
    }

    /** Locale for a chat that has no linked account yet: Telegram's language hint, else the default. */
    private function guestLocale(?string $languageCode): string
    {
        return Locales::normalize($languageCode) ?? Locales::DEFAULT;
    }

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PlannerService $planner,
        private readonly ProgressPropagationService $progress,
        private readonly DependencyService $dependencies,
        private readonly AnalyticsService $analytics,
        private readonly ReviewService $reviews,
    ) {}

    // =====================================================================
    // Entry points
    // =====================================================================

    public function start(string|int $chatId, string $username, ?string $code = null, ?string $languageCode = null): void
    {
        if ($code !== null && $code !== '') {
            $userId = Cache::pull('telegram:link:'.strtoupper($code));
            $user = $userId ? User::find($userId) : null;
            if (!$user || !$user->is_active) {
                $this->inLocale($this->guestLocale($languageCode), fn () => $this->telegram->sendMessage($chatId, $this->t('link_invalid')));
                return;
            }
            if ($user->telegram_chat_id !== null && (string) $user->telegram_chat_id !== (string) $chatId) {
                $this->inLocale($user->preferredLocale(), fn () => $this->telegram->sendMessage($chatId, $this->t('link_other_chat')));
                return;
            }
            $user->update([
                'telegram_chat_id' => (string) $chatId,
                'telegram_username' => $username !== '' ? $username : null,
                'telegram_linked_at' => now(),
            ]);
            ProductEvents::record($user, ProductEvents::TELEGRAM_CONNECTED, [], true);
            $this->clearState($chatId);
            $this->inLocale($user->preferredLocale(), fn () => $this->telegram->sendMessage($chatId, $this->t('linked', ['name' => $user->name]), $this->mainKeyboard()));
            return;
        }

        $user = User::where('telegram_chat_id', (string) $chatId)->where('is_active', true)->first();
        if (!$user) {
            $this->inLocale($this->guestLocale($languageCode), fn () => $this->telegram->sendMessage($chatId, $this->linkHelp()));
            return;
        }
        $this->syncIdentity($user, $username);
        $this->clearState($chatId);
        $this->inLocale($user->preferredLocale(), fn () => $this->telegram->sendMessage($chatId, $this->t('hello', ['name' => $user->name]), $this->mainKeyboard()));
    }

    public function handleText(string|int $chatId, string $username, string $text, ?string $languageCode = null): void
    {
        $user = $this->authorized($chatId, $username, $languageCode);
        if (!$user) {
            return;
        }
        $this->runAs($user, function () use ($chatId, $text): void {
            $text = trim($text);
            if (in_array(mb_strtolower($text), array_merge(['/cancel', '/menu', '/home'], (array) __('bot.cancel_words')), true)) {
                $this->clearState($chatId);
                $this->show($chatId, null, $this->t('home'), $this->mainKeyboard());
                return;
            }
            $state = $this->state($chatId);
            match ($state['mode'] ?? null) {
                'search' => $this->runSearch($chatId, $text),
                'ai' => $this->runAI($chatId, $text),
                'wizard' => $this->wizardText($chatId, $state, $text),
                default => $this->show($chatId, null, $this->t('use_menu'), $this->mainKeyboard()),
            };
        }, $chatId, null);
    }

    public function handleCallback(string|int $chatId, string $username, string $callbackId, int $messageId, string $data, ?string $languageCode = null): void
    {
        $user = $this->authorized($chatId, $username, $languageCode);
        if (!$user) {
            $this->inLocale($this->guestLocale($languageCode), fn () => $this->answer($callbackId, $this->t('no_access')));
            return;
        }
        $this->answer($callbackId);
        $this->runAs($user, fn () => $this->route($chatId, $messageId, $data), $chatId, $messageId);
    }

    /** Runs one Telegram update with the planner user context set, always clearing it afterwards. */
    private function runAs(User $user, callable $callback, string|int $chatId, ?int $messageId): void
    {
        $this->user = $user;
        $previousLocale = app()->getLocale();
        app()->setLocale($user->preferredLocale());
        PlannerUserContext::set((int) $user->id);
        try {
            if (!app(Entitlements::class)->canUse($user, 'telegram')) {
                throw new PlanLimitReached('feature_telegram');
            }
            $callback();
        } catch (PlanLimitReached $e) {
            $this->clearState($chatId);
            try {
                $this->show($chatId, $messageId, $e->userMessage().$this->t('upgrade_hint', ['url' => route('billing.index')]), [[$this->btn($this->t('btn.home'), 'home')]]);
            } catch (\Throwable) {
            }
        } catch (\Throwable $e) {
            report($e);
            try {
                $this->show($chatId, $messageId, $this->t('error'), [[$this->btn($this->t('btn.home'), 'home')]]);
            } catch (\Throwable) {
            }
        } finally {
            PlannerUserContext::clear();
            $this->user = null;
            app()->setLocale($previousLocale);
        }
    }

    private function answer(string $callbackId, ?string $text = null): void
    {
        try {
            $this->telegram->answerCallback($callbackId, $text);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function route(string|int $c, int $m, string $data): void
    {
        $p = explode(':', $data);
        $a = $p[0] ?? 'home';
        $s1 = $p[1] ?? '';
        $i1 = $this->int($p[1] ?? null);
        $i2 = $this->int($p[2] ?? null);

        match ($a) {
            'home' => $this->home($c, $m),
            'today' => $this->today($c, $m),
            'tomorrow' => $this->tomorrow($c, $m),
            'tasks' => $this->taskMenu($c, $m),
            'inbox' => $this->inbox($c, $m, $i1),
            'search' => $this->beginSearch($c, $m),
            'structure' => $this->structureMenu($c, $m),
            'execution' => $this->executionMenu($c, $m),
            'analysis' => $this->analysisMenu($c, $m),
            'knowledge' => $this->knowledgeMenu($c, $m),

            'ls' => $this->entityList($c, $m, $s1, $i2),
            'v' => $this->entityView($c, $m, $s1, $i2),
            'ed' => $this->editMenu($c, $m, $s1, $i2),
            'f' => $this->beginEditField($c, $m, $s1, $i2, $p[3] ?? ''),
            'del' => $this->deleteConfirm($c, $m, $s1, $i2),
            'delok' => $this->deleteEntity($c, $m, $s1, $i2),
            'new' => $this->beginCreate($c, $m, $s1),

            'done' => $this->completeTask($c, $m, $i1),
            'defer' => $this->deferTask($c, $m, $i1),
            'tcancel' => $this->cancelTask($c, $m, $i1),
            'fail' => $this->startWizard($c, $m, 'fail', null, null, $i1 ? ['task_id' => $i1] : []),
            'texec' => $this->startWizard($c, $m, 'exec', null, null, ['task_id' => $i1]),
            'tsched' => $this->startWizard($c, $m, 'sched', null, null, ['task_id' => $i1]),
            'tdep' => $this->startWizard($c, $m, 'dep', null, null, ['task_id' => $i1]),
            'trem' => $this->startWizard($c, $m, 'create', 'reminder', null, ['task_id' => $i1]),
            'deps' => $this->taskDependencies($c, $m, $i1),
            'rcancel' => $this->cancelReminder($c, $m, $i1),

            'wz' => $this->wizardCallback($c, $m, $s1, $p[2] ?? ''),

            'daily' => $this->daily($c, $m),
            'dplan' => $this->startWizard($c, $m, 'dplan', null, null, $s1 === 'today' ? ['plan_date' => $this->now()->toDateString()] : []),
            'calendar' => $this->calendar($c, $m),
            'sb' => $this->scheduleView($c, $m, $i1),
            'sbdel' => $this->scheduleDelete($c, $m, $i1, false),
            'sbdelok' => $this->scheduleDelete($c, $m, $i1, true),
            'newsched' => $this->startWizard($c, $m, 'sched'),
            'xlogs' => $this->executionLogs($c, $m),
            'xl' => $this->executionView($c, $m, $i1),
            'xldel' => $this->executionDelete($c, $m, $i1, false),
            'xldelok' => $this->executionDelete($c, $m, $i1, true),
            'newexec' => $this->startWizard($c, $m, 'exec'),
            'dependencies' => $this->dependencyList($c, $m),
            'newdep' => $this->startWizard($c, $m, 'dep'),
            'depdel' => $this->dependencyDelete($c, $m, $i1, false),
            'depdelok' => $this->dependencyDelete($c, $m, $i1, true),
            'failures' => $this->failures($c, $m),

            'reviews' => $this->reviewList($c, $m),
            'rv' => $this->reviewView($c, $m, $i1),
            'revgen' => $this->generateReview($c, $m, $s1),
            'analytics' => $this->analyticsView($c, $m),
            'report' => $this->reportsMenu($c, $m),
            'rday' => $this->report($c, $m, 'day'),
            'rweek' => $this->report($c, $m, 'week'),
            'rmonth' => $this->report($c, $m, 'month'),

            'aiplanner' => $this->beginAI($c, $m),
            'aiinteractions' => $this->aiInteractions($c, $m),
            'pending' => $this->pendingActions($c, $m),
            'activity' => $this->activity($c, $m),
            'noop' => null,
            default => $this->home($c, $m),
        };
    }

    // =====================================================================
    // Auth, state & rendering helpers
    // =====================================================================

    private function authorized(string|int $chatId, string $username, ?string $languageCode = null): ?User
    {
        $u = User::where('telegram_chat_id', (string) $chatId)->where('is_active', true)->first();
        if (!$u) {
            $this->inLocale($this->guestLocale($languageCode), fn () => $this->telegram->sendMessage($chatId, $this->t('not_active')."\n\n".$this->linkHelp()));
            return null;
        }
        $this->syncIdentity($u, $username);
        try {
            $u->markSeen();
        } catch (\Throwable) {
        }
        return $u;
    }

    private function linkHelp(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        return $this->t('link_help', ['url' => $url]);
    }

    private function syncIdentity(User $u, string $username): void
    {
        $value = $username !== '' ? $username : null;
        if ($u->telegram_username !== $value) {
            $u->update(['telegram_username' => $value]);
        }
    }

    private function uid(): int
    {
        if (!$this->user) {
            throw new \LogicException('Telegram planner user context is missing.');
        }
        return (int) $this->user->id;
    }

    /** Ownership-scoped query for a user-owned model class. */
    private function owned(string $class): Builder
    {
        return $class::query()->where((new $class())->qualifyColumn('user_id'), $this->uid());
    }

    private function key(string|int $chatId): string
    {
        return 'telegram:planner:state:'.$chatId;
    }

    /** State is bound to both the chat and the user; a mismatching user discards it. */
    private function state(string|int $chatId): array
    {
        $s = Cache::get($this->key($chatId), []);
        if (!is_array($s) || ($s['uid'] ?? null) !== $this->user?->id) {
            return [];
        }
        return $s;
    }

    private function putState(string|int $chatId, array $s): void
    {
        $s['uid'] = $this->uid();
        Cache::put($this->key($chatId), $s, now()->addSeconds(self::TTL));
    }

    private function clearState(string|int $chatId): void
    {
        Cache::forget($this->key($chatId));
    }

    private function int(mixed $v): int
    {
        return is_string($v) && ctype_digit($v) && strlen($v) < 18 ? (int) $v : 0;
    }

    private function now(): Carbon
    {
        return Carbon::now($this->tz());
    }

    private function btn(string $text, string $data): array
    {
        return ['text' => mb_substr($text, 0, 60), 'callback_data' => $data];
    }

    private function back(string $to = 'home', ?string $label = null): array
    {
        return [$this->btn($label ?? $this->t('btn.back'), $to)];
    }

    /** Normalizes to a valid 2D inline keyboard: single buttons become rows, empty rows are dropped. */
    private function kb(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            if (isset($row['text'])) {
                $row = [$row];
            }
            $row = array_values(array_filter($row, fn ($b) => is_array($b) && isset($b['text'], $b['callback_data'])));
            if ($row !== []) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Edits the given message, or sends a new message; returns the resulting message id. */
    private function show(string|int $c, ?int $m, string $text, array $keyboard = []): ?int
    {
        $text = mb_substr($text, 0, 4000);
        $keyboard = $this->kb($keyboard);
        if ($m) {
            try {
                $this->telegram->editMessage($c, $m, $text, $keyboard);
                return $m;
            } catch (\Throwable) {
                // Fall through to a fresh message (e.g. message too old or unchanged).
            }
        }
        $r = $this->telegram->sendMessage($c, $text, $keyboard);
        $id = (int) ($r['result']['message_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function fmt(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if ($v instanceof \DateTimeInterface) {
            return LocalDate::dateTime($v, null, $this->tz());
        }
        if (is_bool($v)) {
            return __($v ? 'common.yes' : 'common.no');
        }
        if (is_array($v)) {
            return $this->readable($v);
        }
        return (string) $v;
    }

    /** Locale-aware display (Jalali + Persian digits for fa) in the user's timezone. */
    private function dt(mixed $v, string $format = 'Y-m-d H:i'): string
    {
        if (!$v) {
            return '—';
        }
        $tz = $this->tz();
        return match ($format) {
            'Y-m-d' => LocalDate::date($v, null, $tz),
            'H:i' => LocalDate::time($v, null, $tz),
            'm/d H:i' => LocalDate::short($v, null, $tz),
            'm/d' => LocalDate::short($v, null, $tz, false),
            default => LocalDate::dateTime($v, null, $tz),
        };
    }

    /** Human-readable flattening of arrays (no raw JSON dumps). */
    private function readable(mixed $v, int $depth = 0): string
    {
        if (!is_array($v)) {
            return is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        if ($v === []) {
            return '—';
        }
        $parts = [];
        foreach ($v as $k => $x) {
            $val = is_array($x) ? ($depth < 1 ? $this->readable($x, $depth + 1) : '…') : $this->readable($x, $depth + 1);
            $parts[] = is_int($k) ? $val : $k.'='.$val;
            if (count($parts) >= 8) {
                $parts[] = '…';
                break;
            }
        }
        return implode(', ', $parts);
    }

    private function lines(array $lines): string
    {
        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    private function mainKeyboard(): array
    {
        return [
            [$this->btn($this->t('menu.today'), 'today'), $this->btn($this->t('menu.tasks'), 'tasks')],
            [$this->btn($this->t('menu.inbox'), 'inbox'), $this->btn($this->t('menu.search'), 'search')],
            [$this->btn($this->t('menu.structure'), 'structure')],
            [$this->btn($this->t('menu.execution'), 'execution')],
            [$this->btn($this->t('menu.analysis'), 'analysis')],
            [$this->btn($this->t('menu.knowledge'), 'knowledge')],
        ];
    }

    // =====================================================================
    // Menus
    // =====================================================================

    private function home(string|int $c, ?int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, $this->t('home'), $this->mainKeyboard());
    }

    private function taskMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, $this->t('section.tasks'), [
            [$this->btn($this->t('menu.new_task'), 'new:task')],
            [$this->btn($this->t('menu.today'), 'today'), $this->btn($this->t('menu.tomorrow'), 'tomorrow')],
            [$this->btn($this->t('menu.inbox'), 'inbox'), $this->btn($this->t('menu.search'), 'search')],
            [$this->btn($this->t('menu.all_open'), 'ls:task:0')],
            $this->back(),
        ]);
    }

    private function structureMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, $this->t('section.structure'), [
            [$this->btn($this->t('menu.areas'), 'ls:area:0'), $this->btn($this->t('menu.goals'), 'ls:goal:0')],
            [$this->btn($this->t('menu.projects'), 'ls:project:0'), $this->btn($this->t('menu.milestones'), 'ls:milestone:0')],
            $this->back(),
        ]);
    }

    private function executionMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, $this->t('section.execution'), [
            [$this->btn($this->t('menu.daily'), 'daily'), $this->btn($this->t('menu.calendar'), 'calendar')],
            [$this->btn($this->t('menu.worklog'), 'xlogs'), $this->btn($this->t('menu.dependencies'), 'dependencies')],
            [$this->btn($this->t('menu.reminders'), 'ls:reminder:0'), $this->btn($this->t('menu.failures'), 'failures')],
            $this->back(),
        ]);
    }

    private function analysisMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, $this->t('section.analysis'), [
            [$this->btn($this->t('menu.reviews'), 'reviews'), $this->btn($this->t('menu.analytics'), 'analytics')],
            [$this->btn($this->t('menu.reports'), 'report')],
            $this->back(),
        ]);
    }

    private function knowledgeMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, $this->t('section.knowledge'), [
            [$this->btn($this->t('menu.notes'), 'ls:note:0'), $this->btn($this->t('menu.decisions'), 'ls:decision:0')],
            [$this->btn($this->t('menu.ai'), 'aiplanner'), $this->btn($this->t('menu.ai_history'), 'aiinteractions')],
            [$this->btn($this->t('menu.pending'), 'pending'), $this->btn($this->t('menu.activity'), 'activity')],
            $this->back(),
        ]);
    }

    // =====================================================================
    // Entity registry
    // =====================================================================

    /** @return array<string,array{class:class-string<Model>,label:string,plural:string,parent:string,title:string}> */
    private function entities(): array
    {
        return [
            'area' => ['class' => Area::class, 'parent' => 'structure', 'title' => 'name'],
            'goal' => ['class' => Goal::class, 'parent' => 'structure', 'title' => 'title'],
            'project' => ['class' => Project::class, 'parent' => 'structure', 'title' => 'title'],
            'milestone' => ['class' => Milestone::class, 'parent' => 'structure', 'title' => 'title'],
            'task' => ['class' => Task::class, 'parent' => 'tasks', 'title' => 'title'],
            'note' => ['class' => Note::class, 'parent' => 'knowledge', 'title' => 'title'],
            'decision' => ['class' => Decision::class, 'parent' => 'knowledge', 'title' => 'title'],
            'reminder' => ['class' => Reminder::class, 'parent' => 'execution', 'title' => 'scheduled_at'],
        ];
    }

    /** Entity definition with its localized singular label and list heading. */
    private function entity(string $e): ?array
    {
        $def = $this->entities()[$e] ?? null;
        return $def === null ? null : $def + ['label' => (string) __('planner.entities.'.$e), 'plural' => $this->t('plural.'.$e)];
    }

    private function findOwned(string $e, int $id): ?Model
    {
        $def = $this->entity($e);
        if (!$def || $id <= 0) {
            return null;
        }
        return $this->owned($def['class'])->find($id);
    }

    private function titleOf(string $e, Model $x): string
    {
        if ($e === 'reminder') {
            $msg = (string) (($x->payload['message'] ?? null) ?: ($x->task?->title ?? __('planner.entities.reminder')));
            return $this->dt($x->scheduled_at, 'm/d H:i').' — '.mb_substr($msg, 0, 40);
        }
        $field = $this->entity($e)['title'] ?? 'title';
        return (string) ($x->{$field} ?? ('#'.$x->id));
    }

    /**
     * Field definitions for a form (entity create/edit or an action flow).
     * t: text|long|int|num|level|pct|date|datetime|enum|ref ; req: required on create ;
     * ref: referenced entity ; create/edit: availability ; custom: enum accepts typed values.
     */
    private function fields(string $form): array
    {
        $f = fn (string $k, string $l, string $t, array $o = []) => ['k' => $k, 'l' => $l, 't' => $t] + $o;
        return match ($form) {
            'area' => [
                $f('name', $this->t('fields.name'), 'text', ['req' => true]),
                $f('type', $this->t('fields.type'), 'enum', ['custom' => true]),
                $f('status', $this->t('fields.status'), 'enum', ['custom' => true]),
                $f('description', $this->t('fields.description'), 'long'),
                $f('sort_order', $this->t('fields.sort_order'), 'int'),
            ],
            'goal' => [
                $f('title', $this->t('fields.title'), 'text', ['req' => true]),
                $f('description', $this->t('fields.description'), 'long'),
                $f('area_id', $this->t('fields.area_id'), 'ref', ['ref' => 'area']),
                $f('status', $this->t('fields.status'), 'enum', ['custom' => true]),
                $f('importance', $this->t('fields.importance'), 'level'),
                $f('weight', $this->t('fields.weight'), 'num'),
                $f('start_date', $this->t('fields.start_date'), 'date'),
                $f('target_date', $this->t('fields.target_date'), 'date'),
                $f('success_criteria', $this->t('fields.success_criteria'), 'long'),
                $f('progress', $this->t('fields.progress'), 'pct', ['create' => false]),
            ],
            'project' => [
                $f('title', $this->t('fields.title'), 'text', ['req' => true]),
                $f('description', $this->t('fields.description'), 'long'),
                $f('goal_id', $this->t('fields.goal_id'), 'ref', ['ref' => 'goal']),
                $f('status', $this->t('fields.status'), 'enum', ['custom' => true]),
                $f('importance', $this->t('fields.importance'), 'level'),
                $f('weight', $this->t('fields.weight'), 'num'),
                $f('estimated_minutes', $this->t('fields.estimated_minutes'), 'int'),
                $f('start_date', $this->t('fields.start_date'), 'date'),
                $f('target_date', $this->t('fields.target_date'), 'date'),
                $f('progress', $this->t('fields.progress'), 'pct', ['create' => false]),
            ],
            'milestone' => [
                $f('title', $this->t('fields.title'), 'text', ['req' => true]),
                $f('project_id', $this->t('fields.project_id'), 'ref', ['ref' => 'project', 'req' => true]),
                $f('status', $this->t('fields.status'), 'enum', ['custom' => true]),
                $f('weight', $this->t('fields.weight'), 'num'),
                $f('target_date', $this->t('fields.target_date'), 'date'),
                $f('progress', $this->t('fields.progress'), 'pct', ['create' => false]),
            ],
            'task' => [
                $f('title', $this->t('fields.title'), 'text', ['req' => true]),
                $f('description', $this->t('fields.description'), 'long'),
                $f('area_id', $this->t('fields.area_id'), 'ref', ['ref' => 'area']),
                $f('goal_id', $this->t('fields.goal_id'), 'ref', ['ref' => 'goal']),
                $f('project_id', $this->t('fields.project_id'), 'ref', ['ref' => 'project']),
                $f('milestone_id', $this->t('fields.milestone_id'), 'ref', ['ref' => 'milestone']),
                $f('status', $this->t('fields.status'), 'enum'),
                $f('priority', $this->t('fields.priority'), 'enum'),
                $f('importance', $this->t('fields.importance'), 'level'),
                $f('weight', $this->t('fields.weight'), 'num'),
                $f('estimated_minutes', $this->t('fields.estimated_minutes'), 'int'),
                $f('deadline', $this->t('fields.deadline'), 'datetime'),
                $f('planned_start', $this->t('fields.planned_start'), 'datetime'),
                $f('planned_end', $this->t('fields.planned_end'), 'datetime'),
                $f('energy_level', $this->t('fields.energy_needed'), 'level'),
                $f('focus_level', $this->t('fields.focus_needed'), 'level'),
                $f('progress', $this->t('fields.progress'), 'pct', ['create' => false]),
                $f('failure_reason', $this->t('fields.failure_reason'), 'enum', ['create' => false]),
            ],
            'note' => [
                $f('title', $this->t('fields.title'), 'text', ['req' => true]),
                $f('content', $this->t('fields.content'), 'long', ['req' => true]),
                $f('area_id', $this->t('fields.area_id'), 'ref', ['ref' => 'area']),
                $f('goal_id', $this->t('fields.goal_id'), 'ref', ['ref' => 'goal']),
                $f('project_id', $this->t('fields.project_id'), 'ref', ['ref' => 'project']),
                $f('task_id', $this->t('fields.task_id'), 'ref', ['ref' => 'task']),
            ],
            'decision' => [
                $f('title', $this->t('fields.title'), 'text', ['req' => true]),
                $f('decision', $this->t('fields.decision'), 'long', ['req' => true]),
                $f('rationale', $this->t('fields.rationale'), 'long'),
                $f('area_id', $this->t('fields.area_id'), 'ref', ['ref' => 'area']),
                $f('decided_at', $this->t('fields.decided_at'), 'datetime', ['nonnull' => true]),
            ],
            'reminder' => [
                $f('task_id', $this->t('fields.task_id'), 'ref', ['ref' => 'task']),
                $f('type', $this->t('fields.type'), 'enum'),
                $f('scheduled_at', $this->t('fields.scheduled_at'), 'datetime', ['req' => true]),
                $f('message', $this->t('fields.message'), 'long'),
                $f('status', $this->t('fields.status'), 'enum', ['create' => false, 'nonnull' => true]),
            ],
            'exec' => [
                $f('task_id', $this->t('fields.task_id'), 'ref', ['ref' => 'task', 'req' => true]),
                $f('started_at', $this->t('fields.exec_started'), 'datetime'),
                $f('ended_at', $this->t('fields.exec_ended'), 'datetime'),
                $f('duration_minutes', $this->t('fields.duration'), 'int'),
                $f('focus_level', $this->t('fields.focus_level'), 'level'),
                $f('energy_level', $this->t('fields.energy_level'), 'level'),
                $f('result', $this->t('fields.result'), 'text'),
                $f('blocker', $this->t('fields.blocker'), 'long'),
                $f('notes', $this->t('fields.notes'), 'long'),
            ],
            'dep' => [
                $f('task_id', $this->t('fields.task_id'), 'ref', ['ref' => 'task', 'req' => true]),
                $f('depends_on_task_id', $this->t('fields.depends_on'), 'ref', ['ref' => 'task', 'req' => true]),
                $f('type', $this->t('fields.dep_type'), 'enum', ['req' => true]),
            ],
            'sched' => [
                $f('task_id', $this->t('fields.task_id'), 'ref', ['ref' => 'task', 'req' => true]),
                $f('starts_at', $this->t('fields.starts_at'), 'datetime', ['req' => true]),
                $f('ends_at', $this->t('fields.ends_at'), 'datetime', ['req' => true]),
            ],
            'dplan' => [
                $f('plan_date', $this->t('fields.plan_date'), 'date'),
                $f('available_minutes', $this->t('fields.available_minutes'), 'int'),
                $f('planned_minutes', $this->t('fields.planned_minutes'), 'int'),
                $f('completed_minutes', $this->t('fields.completed_minutes'), 'int'),
                $f('buffer_minutes', $this->t('fields.buffer_minutes'), 'int'),
                $f('focus_level', $this->t('fields.plan_focus'), 'level'),
                $f('energy_level', $this->t('fields.plan_energy'), 'level'),
                $f('notes', $this->t('fields.notes'), 'long'),
            ],
            'fail' => [
                $f('task_id', $this->t('fields.task_id'), 'ref', ['ref' => 'task', 'req' => true]),
                $f('reason', $this->t('fields.failure_reason'), 'enum', ['req' => true]),
            ],
            default => [],
        };
    }

    private function fieldDef(string $form, string $key): ?array
    {
        foreach ($this->fields($form) as $f) {
            if ($f['k'] === $key) {
                return $f;
            }
        }
        return null;
    }

    /** Allowed values [value,label] — taken from the domain/migrations or values already in use. */
    private function options(string $form, string $key): array
    {
        $inUse = fn (string $class, string $col) => $this->owned($class)->whereNotNull($col)->distinct()->limit(10)->pluck($col)->all();

        $icons = ['inbox' => '📥', 'planned' => '🗓', 'ready' => '✅', 'in_progress' => '▶️', 'blocked' => '⛔', 'waiting' => '⏳', 'completed' => '✔️', 'cancelled' => '🚫', 'deferred' => '⏸'];
        $taskStatus = [];
        foreach ($icons as $value => $icon) {
            $taskStatus[$value] = $icon.' '.__('planner.task_status.'.$value);
        }
        $pairs = fn (array $values) => array_map(fn ($v) => [(string) $v, $this->enumLabel((string) $v)], array_values(array_unique(array_filter($values, fn ($v) => $v !== null && $v !== ''))));

        return match ("$form.$key") {
            'task.status' => array_map(fn (TaskStatus $s) => [$s->value, $taskStatus[$s->value] ?? $s->value], TaskStatus::cases()),
            'task.priority' => array_map(fn ($p) => [$p, (string) __('planner.priority.'.$p)], ['p0', 'p1', 'p2', 'p3']),
            'task.failure_reason', 'fail.reason' => $this->failureOptions(),
            'area.type' => $pairs(array_merge(['custom'], $inUse(Area::class, 'type'))),
            'area.status' => $pairs(array_merge(['active'], $inUse(Area::class, 'status'))),
            'goal.status' => $pairs(array_merge(['active', 'in_progress', 'completed', 'cancelled'], $inUse(Goal::class, 'status'))),
            'project.status' => $pairs(array_merge(['active', 'in_progress', 'completed', 'cancelled'], $inUse(Project::class, 'status'))),
            'milestone.status' => $pairs(array_merge(['pending', 'completed'], $inUse(Milestone::class, 'status'))),
            'reminder.type' => $pairs(array_merge(['telegram'], $inUse(Reminder::class, 'type'))),
            'reminder.status' => $pairs(['pending', 'sent', 'failed', 'cancelled']),
            'dep.type' => array_map(fn ($d) => [$d, $d.' — '.__('planner.dependency_type.'.$d)], ['requires', 'blocks', 'related']),
            default => [],
        };
    }

    /** Human label for a stored enum value (translated when known, raw otherwise). */
    private function enumLabel(string $value): string
    {
        foreach (['planner.task_status.', 'planner.generic_status.', 'planner.failure_reason.'] as $prefix) {
            $label = __($prefix.$value);
            if ($label !== $prefix.$value) {
                return (string) $label;
            }
        }
        return $value;
    }

    private function failureOptions(): array
    {
        $rows = FailureReason::query()->orderBy('severity')->orderBy('code')->get(['code', 'name']);
        if ($rows->isNotEmpty()) {
            return $rows->map(fn ($r) => [(string) $r->code, $this->failureName((string) $r->code, (string) $r->name)])->all();
        }
        // Same codes as the web planner's failure form when the lookup table is not seeded.
        return array_map(fn ($c) => [$c, $this->failureName($c, $c)], ['no_time', 'poor_estimation', 'unexpected_work', 'low_energy', 'distraction', 'too_difficult', 'unclear_task', 'waiting', 'blocked', 'wrong_priority', 'context_switching', 'personal_issue', 'technical_problem', 'other']);
    }

    private function failureName(string $code, string $fallback): string
    {
        $label = __('planner.failure_reason.'.$code);
        return $label === 'planner.failure_reason.'.$code ? $fallback : (string) $label;
    }

    // =====================================================================
    // Lists & views
    // =====================================================================

    private function listQuery(string $e): Builder
    {
        $q = $this->owned($this->entity($e)['class']);
        return match ($e) {
            'task' => $q->whereNotIn('status', ['completed', 'cancelled'])
                ->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")
                ->orderBy('deadline')->orderByDesc('id'),
            'reminder' => $q->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('scheduled_at'),
            'area' => $q->orderBy('sort_order')->orderBy('id'),
            default => $q->latest('id'),
        };
    }

    private function entityList(string|int $c, int $m, string $e, int $page = 0): void
    {
        $def = $this->entity($e);
        if (!$def) {
            $this->home($c, $m);
            return;
        }
        $this->clearState($c);
        $query = $this->listQuery($e);
        $total = (clone $query)->count();
        $items = $query->skip($page * self::PAGE)->take(self::PAGE)->get();

        $lines = $items->map(function (Model $x) use ($e) {
            $suffix = match ($e) {
                'task' => ' — '.strtoupper((string) $x->priority).' · '.$this->enumLabel((string) $x->status),
                'goal', 'project', 'milestone' => ' — '.(float) $x->progress.'%',
                'reminder' => ' — '.$this->enumLabel((string) $x->status),
                default => '',
            };
            return '• '.$this->titleOf($e, $x).$suffix;
        })->implode("\n");

        $text = $def['plural'].' ('.LocalDate::number($total).")\n\n".($items->isEmpty() ? $this->t('empty') : $lines);
        $kb = [[$this->btn($this->t('create_entity', ['entity' => $def['label']]), 'new:'.$e)]];
        foreach ($items as $x) {
            $kb[] = [$this->btn('👁 '.$this->titleOf($e, $x), "v:$e:{$x->id}")];
        }
        $nav = [];
        if ($page > 0) {
            $nav[] = $this->btn($this->t('btn.prev_page'), "ls:$e:".($page - 1));
        }
        if (($page + 1) * self::PAGE < $total) {
            $nav[] = $this->btn($this->t('btn.next_page'), "ls:$e:".($page + 1));
        }
        $kb[] = $nav;
        $kb[] = $this->back($def['parent']);
        $this->show($c, $m, $text, $kb);
    }

    private function entityView(string|int $c, ?int $m, string $e, int $id, ?string $notice = null): void
    {
        $def = $this->entity($e);
        $x = $this->findOwned($e, $id);
        if (!$def || !$x) {
            $this->show($c, $m, $this->t('not_owned'), [$this->back($def['parent'] ?? 'home')]);
            return;
        }
        $text = ($notice ? $notice."\n\n" : '').$this->t('item_heading', ['entity' => $def['label'], 'id' => $x->id])."\n\n".$this->describe($e, $x);
        $kb = [];
        if ($e === 'task') {
            if (!in_array($x->status, ['completed', 'cancelled'], true)) {
                $kb[] = [$this->btn($this->t('btn.done'), 'done:'.$id), $this->btn($this->t('btn.defer'), 'defer:'.$id), $this->btn($this->t('btn.cancel_task'), 'tcancel:'.$id)];
            }
            $kb[] = [$this->btn($this->t('btn.log_work'), 'texec:'.$id), $this->btn($this->t('btn.schedule'), 'tsched:'.$id)];
            $kb[] = [$this->btn($this->t('btn.deps'), 'deps:'.$id), $this->btn($this->t('btn.remind'), 'trem:'.$id), $this->btn($this->t('btn.slipped'), 'fail:'.$id)];
        }
        if ($e === 'reminder' && $x->status === 'pending') {
            $kb[] = [$this->btn($this->t('btn.cancel_reminder'), 'rcancel:'.$id)];
        }
        $kb[] = [$this->btn($this->t('btn.edit'), "ed:$e:$id"), $this->btn($this->t('btn.delete'), "del:$e:$id")];
        $kb[] = $this->back("ls:$e:0");
        $this->show($c, $m, $text, $kb);
    }

    private function describe(string $e, Model $x): string
    {
        $out = [];
        foreach ($this->fields($e) as $f) {
            $v = $e === 'reminder' && $f['k'] === 'message' ? ($x->payload['message'] ?? null) : $x->{$f['k']};
            if ($v === null || $v === '') {
                continue;
            }
            if ($f['t'] === 'ref') {
                $v = $this->refTitle($f['ref'], (int) $v);
            } elseif ($f['t'] === 'enum') {
                $v = $this->displayValue($e, $f, $v);
            } elseif ($f['t'] === 'date') {
                $v = $this->dt($v, 'Y-m-d');
            } elseif ($f['t'] === 'datetime') {
                $v = $this->dt($v);
            }
            $out[] = $f['l'].': '.$this->fmt($v);
        }
        if ($e === 'task') {
            $out[] = $this->t('time_spent', ['minutes' => LocalDate::number((int) $x->actual_minutes)]);
            if ($x->completed_at) {
                $out[] = $this->t('completed_on', ['date' => $this->dt($x->completed_at)]);
            }
        }
        if (in_array($e, ['goal', 'project'], true) && $x->health) {
            $out[] = $this->t('health', ['health' => $this->enumLabel((string) $x->health)]);
        }
        if ($e === 'reminder' && (int) $x->attempts > 0) {
            $out[] = $this->t('attempts', ['n' => $x->attempts, 'max' => $x->max_attempts]);
        }
        return $out ? implode("\n", $out) : $this->t('no_details');
    }

    private function refTitle(string $ref, int $id): string
    {
        $x = $this->findOwned($ref, $id);
        return $x ? $this->titleOf($ref, $x) : '#'.$id;
    }

    private function editMenu(string|int $c, int $m, string $e, int $id): void
    {
        $x = $this->findOwned($e, $id);
        if (!$x) {
            $this->show($c, $m, $this->t('not_owned'), [$this->back()]);
            return;
        }
        $this->clearState($c);
        $kb = [];
        $row = [];
        foreach ($this->fields($e) as $f) {
            if (($f['edit'] ?? true) === false) {
                continue;
            }
            $row[] = $this->btn('✏️ '.$f['l'], "f:$e:$id:".$f['k']);
            if (count($row) === 2) {
                $kb[] = $row;
                $row = [];
            }
        }
        $kb[] = $row;
        $kb[] = $this->back("v:$e:$id");
        $this->show($c, $m, $this->t('edit_menu', ['entity' => $this->entity($e)['label'], 'title' => $this->titleOf($e, $x)]), $kb);
    }

    private function beginEditField(string|int $c, int $m, string $e, int $id, string $field): void
    {
        $x = $this->findOwned($e, $id);
        $def = $this->fieldDef($e, $field);
        if (!$x || !$def || ($def['edit'] ?? true) === false) {
            $this->show($c, $m, $this->t('invalid_field'), [$this->back($x ? "v:$e:$id" : 'home')]);
            return;
        }
        $this->startWizard($c, $m, 'edit', $e, $id, [], [$field]);
    }

    private function deleteConfirm(string|int $c, int $m, string $e, int $id): void
    {
        $x = $this->findOwned($e, $id);
        if (!$x) {
            $this->show($c, $m, $this->t('not_owned'), [$this->back()]);
            return;
        }
        $warn = match ($e) {
            'project' => $this->t('warn_project'),
            'milestone', 'area', 'goal' => $this->t('warn_detach'),
            'task' => $this->t('warn_task'),
            default => '',
        };
        $this->show($c, $m, $this->t('confirm_delete', ['entity' => $this->entity($e)['label'], 'title' => $this->titleOf($e, $x)]).$warn, [
            [$this->btn($this->t('btn.yes_delete'), "delok:$e:$id")],
            [$this->btn($this->t('btn.no'), "v:$e:$id")],
        ]);
    }

    private function deleteEntity(string|int $c, int $m, string $e, int $id): void
    {
        $x = $this->findOwned($e, $id);
        if (!$x) {
            $this->show($c, $m, $this->t('not_owned'), [$this->back()]);
            return;
        }
        DB::transaction(function () use ($e, $x): void {
            if ($e === 'task') {
                $goal = $x->goal()->first();
                $project = $x->project()->first();
                $milestone = $x->milestone()->first();
                $x->delete();
                if ($milestone) $this->progress->milestone($milestone);
                if ($project) $this->progress->project($project);
                if ($goal) $this->progress->goal($goal);
                return;
            }
            $project = $e === 'milestone' ? $x->project()->first() : null;
            $goal = $e === 'project' ? $x->goal()->first() : null;
            $x->delete();
            if ($project) $this->progress->project($project);
            if ($goal) $this->progress->goal($goal);
        });
        $this->entityList($c, $m, $e, 0);
    }

    // ---------------------------------------------------------------------
    // Task actions
    // ---------------------------------------------------------------------

    private function ownedTask(int $id): ?Task
    {
        return $id > 0 ? $this->owned(Task::class)->find($id) : null;
    }

    private function completeTask(string|int $c, int $m, int $id): void
    {
        $t = $this->ownedTask($id);
        if (!$t) {
            $this->show($c, $m, $this->t('task_not_found'), [$this->back('tasks')]);
            return;
        }
        $this->planner->complete($t);
        $this->progress->recalculateFromTask($t);
        $this->entityView($c, $m, 'task', $id, $this->t('task_done'));
    }

    private function deferTask(string|int $c, int $m, int $id): void
    {
        $t = $this->ownedTask($id);
        if (!$t) {
            $this->show($c, $m, $this->t('task_not_found'), [$this->back('tasks')]);
            return;
        }
        $this->planner->defer($t);
        $this->entityView($c, $m, 'task', $id, $this->t('task_deferred'));
    }

    private function cancelTask(string|int $c, int $m, int $id): void
    {
        $t = $this->ownedTask($id);
        if (!$t) {
            $this->show($c, $m, $this->t('task_not_found'), [$this->back('tasks')]);
            return;
        }
        $t->update(['status' => 'cancelled', 'completed_at' => null]);
        $this->planner->recalculatePriority($t);
        $this->progress->recalculateFromTask($t);
        $this->entityView($c, $m, 'task', $id, $this->t('task_canceled'));
    }

    private function cancelReminder(string|int $c, int $m, int $id): void
    {
        $r = $this->findOwned('reminder', $id);
        if (!$r) {
            $this->show($c, $m, $this->t('reminder_not_found'), [$this->back('execution')]);
            return;
        }
        $r->update(['status' => 'cancelled']);
        $this->entityView($c, $m, 'reminder', $id, $this->t('reminder_canceled'));
    }

    private function taskButtons(iterable $tasks): array
    {
        $kb = [];
        foreach ($tasks as $t) {
            $kb[] = [$this->btn(($t->status === 'completed' ? '✔️ ' : '📋 ').$t->title, 'v:task:'.$t->id)];
        }
        return $kb;
    }

    private function taskLine(Task $t): string
    {
        $when = $t->planned_start ? $this->dt($t->planned_start, 'H:i') : ($t->deadline ? '⏰'.$this->dt($t->deadline, 'H:i') : '');
        return '• '.($when !== '' ? $when.' ' : '').$t->title.' — '.strtoupper((string) $t->priority).' · '.LocalDate::number((float) $t->progress).'%';
    }

    private function dayTasks(Carbon $day): Collection
    {
        $from = $day->copy()->startOfDay()->timezone(config('app.timezone'));
        $to = $day->copy()->endOfDay()->timezone(config('app.timezone'));
        return $this->owned(Task::class)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where(fn ($q) => $q->whereBetween('planned_start', [$from, $to])->orWhereBetween('deadline', [$from, $to]))
            ->orderByRaw('COALESCE(planned_start, deadline)')
            ->limit(30)->get();
    }

    private function today(string|int $c, int $m): void
    {
        $this->clearState($c);
        $day = $this->now();
        $tasks = $this->dayTasks($day);
        $overdue = $this->owned(Task::class)->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('deadline')->where('deadline', '<', $day->copy()->startOfDay()->timezone(config('app.timezone')))
            ->orderBy('deadline')->limit(10)->get();
        $blocks = $this->owned(ScheduleBlock::class)->with('task')
            ->whereBetween('starts_at', [$day->copy()->startOfDay()->timezone(config('app.timezone')), $day->copy()->endOfDay()->timezone(config('app.timezone'))])
            ->orderBy('starts_at')->limit(15)->get();
        $done = $this->owned(Task::class)->where('status', 'completed')
            ->whereBetween('completed_at', [$day->copy()->startOfDay()->timezone(config('app.timezone')), $day->copy()->endOfDay()->timezone(config('app.timezone'))])->count();

        $text = $this->lines([
            $this->t('today_title', ['date' => LocalDate::date($day, null, $this->tz())]),
            '',
            $this->t('today_tasks', ['count' => LocalDate::number($tasks->count())]),
            $tasks->isEmpty() ? $this->t('today_empty') : $tasks->map(fn (Task $t) => $this->taskLine($t))->implode("\n"),
            $overdue->isNotEmpty() ? $this->t('overdue', ['count' => LocalDate::number($overdue->count())])."\n".$overdue->map(fn (Task $t) => '• '.$t->title.' — '.$this->t('due_short', ['date' => $this->dt($t->deadline, 'm/d')]))->implode("\n") : null,
            $blocks->isNotEmpty() ? $this->t('blocks')."\n".$blocks->map(fn ($b) => '• '.$this->dt($b->starts_at, 'H:i').'–'.$this->dt($b->ends_at, 'H:i').' '.($b->task?->title ?? $this->t('focus_block')))->implode("\n") : null,
            $this->t('done_today', ['count' => LocalDate::number($done)]),
        ]);
        $kb = $this->taskButtons($tasks->concat($overdue)->take(15));
        $kb[] = [$this->btn($this->t('menu.new_task'), 'new:task'), $this->btn($this->t('menu.tomorrow'), 'tomorrow')];
        $kb[] = $this->back();
        $this->show($c, $m, $text, $kb);
    }

    private function tomorrow(string|int $c, int $m): void
    {
        $this->clearState($c);
        $day = $this->now()->addDay();
        $tasks = $this->dayTasks($day);
        $text = $this->t('tomorrow_title', ['date' => LocalDate::date($day, null, $this->tz())])."\n\n".($tasks->isEmpty() ? $this->t('tomorrow_empty') : $tasks->map(fn (Task $t) => $this->taskLine($t))->implode("\n"));
        $kb = $this->taskButtons($tasks);
        $kb[] = $this->back('tasks');
        $this->show($c, $m, $text, $kb);
    }

    private function inbox(string|int $c, int $m, int $page = 0): void
    {
        $this->clearState($c);
        $q = $this->owned(Task::class)->where('status', 'inbox');
        $total = (clone $q)->count();
        $items = $q->latest('id')->skip($page * self::PAGE)->take(self::PAGE)->get();
        $text = $this->t('inbox_title', ['count' => LocalDate::number($total)])."\n\n".($items->isEmpty() ? $this->t('inbox_empty') : $items->map(fn (Task $t) => '• '.$t->title)->implode("\n"));
        $kb = $this->taskButtons($items);
        $nav = [];
        if ($page > 0) $nav[] = $this->btn($this->t('btn.prev_page'), 'inbox:'.($page - 1));
        if (($page + 1) * self::PAGE < $total) $nav[] = $this->btn($this->t('btn.next_page'), 'inbox:'.($page + 1));
        $kb[] = $nav;
        $kb[] = [$this->btn($this->t('menu.new_task'), 'new:task')];
        $kb[] = $this->back('tasks');
        $this->show($c, $m, $text, $kb);
    }

    // ---------------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------------

    private function beginSearch(string|int $c, int $m): void
    {
        $this->putState($c, ['mode' => 'search', 'mid' => $m]);
        $this->show($c, $m, $this->t('search_prompt'), [$this->back('home', $this->t('btn.cancel'))]);
    }

    private function like(string $q): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
    }

    /** @return array<string,Collection> grouped, user-scoped results */
    public function searchFor(string $q): array
    {
        $like = $this->like($q);
        $cols = [
            'task' => ['title', 'description'], 'goal' => ['title', 'description'], 'project' => ['title', 'description'],
            'note' => ['title', 'content'], 'decision' => ['title', 'decision', 'rationale'],
            'area' => ['name', 'description'], 'milestone' => ['title'],
        ];
        $out = [];
        foreach ($cols as $e => $columns) {
            $out[$e] = $this->owned($this->entity($e)['class'])
                ->where(function ($w) use ($columns, $like) {
                    foreach ($columns as $col) {
                        $w->orWhere($col, 'ilike', $like);
                    }
                })
                ->latest('id')->limit(5)->get();
        }
        return $out;
    }

    private function runSearch(string|int $c, string $q): void
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            $this->show($c, null, $this->t('search_short'), [$this->back('home', $this->t('btn.cancel'))]);
            return;
        }
        $this->clearState($c);
        $groups = $this->searchFor(mb_substr($q, 0, 100));
        $text = $this->t('search_results', ['q' => $q])."\n";
        $kb = [];
        $found = 0;
        foreach ($groups as $e => $items) {
            if ($items->isEmpty()) {
                continue;
            }
            $found += $items->count();
            $text .= "\n".$this->entity($e)['plural'].":\n".$items->map(fn ($x) => '• '.$this->titleOf($e, $x))->implode("\n")."\n";
            foreach ($items as $x) {
                $kb[] = [$this->btn($this->entity($e)['label'].': '.$this->titleOf($e, $x), "v:$e:{$x->id}")];
            }
        }
        if ($found === 0) {
            $text .= $this->t('no_results');
        }
        $kb[] = [$this->btn($this->t('btn.search_again'), 'search'), $this->btn($this->t('btn.home'), 'home')];
        $this->show($c, null, $text, $kb);
    }

    // =====================================================================
    // Generic wizard (create / edit / action flows)
    // =====================================================================

    private function beginCreate(string|int $c, int $m, string $e): void
    {
        if (!$this->entity($e)) {
            $this->home($c, $m);
            return;
        }
        $this->startWizard($c, $m, 'create', $e);
    }

    /** form for field definitions: entity key for create/edit, flow key otherwise. */
    private function formOf(array $s): string
    {
        return in_array($s['flow'], ['create', 'edit'], true) ? $s['entity'] : $s['flow'];
    }

    private function startWizard(string|int $c, int $m, string $flow, ?string $entity = null, ?int $id = null, array $preset = [], ?array $only = null): void
    {
        $form = in_array($flow, ['create', 'edit'], true) ? (string) $entity : $flow;
        $defs = $this->fields($form);
        if ($defs === []) {
            $this->home($c, $m);
            return;
        }
        // Preset references must belong to the current user.
        foreach ($preset as $k => $v) {
            $def = $this->fieldDef($form, $k);
            if ($def && $def['t'] === 'ref' && !$this->findOwned($def['ref'], (int) $v)) {
                $this->show($c, $m, $this->t('ref_not_owned'), [$this->back()]);
                return;
            }
        }
        $steps = [];
        foreach ($defs as $f) {
            if ($only !== null && !in_array($f['k'], $only, true)) continue;
            if ($only === null && ($f['create'] ?? true) === false) continue;
            if (array_key_exists($f['k'], $preset)) continue;
            $steps[] = $f['k'];
        }
        $s = ['mode' => 'wizard', 'flow' => $flow, 'entity' => $entity, 'id' => $id, 'steps' => $steps, 'step' => 0, 'data' => $preset, 'mid' => $m, 'filter' => null];
        $this->putState($c, $s);
        $this->askStep($c, $m, $s);
    }

    private function wizardTitle(array $s): string
    {
        return match ($s['flow']) {
            'create', 'edit' => $this->t('wz.'.$s['flow'], ['entity' => $this->entity($s['entity'])['label']]),
            'exec', 'dep', 'sched', 'dplan', 'fail' => $this->t('wz.'.$s['flow']),
            default => 'Wizard',
        };
    }

    private function cancelTarget(array $s): string
    {
        return match ($s['flow']) {
            'create' => 'ls:'.$s['entity'].':0',
            'edit' => 'v:'.$s['entity'].':'.$s['id'],
            'exec' => 'xlogs',
            'dep' => 'dependencies',
            'sched' => 'calendar',
            'dplan' => 'daily',
            'fail' => 'failures',
            default => 'home',
        };
    }

    private function requiredDone(array $s): bool
    {
        foreach ($this->fields($this->formOf($s)) as $f) {
            if (($f['req'] ?? false) && !array_key_exists($f['k'], $s['data'])) {
                return false;
            }
        }
        return true;
    }

    private function askStep(string|int $c, ?int $m, array $s, ?string $error = null): void
    {
        $key = $s['steps'][$s['step']] ?? null;
        if ($key === null) {
            if ($s['flow'] === 'edit') {
                $this->commit($c, $m, $s);
            } else {
                $this->summary($c, $m, $s);
            }
            return;
        }
        $form = $this->formOf($s);
        $f = $this->fieldDef($form, $key);
        $isEdit = $s['flow'] === 'edit';
        $required = ($f['req'] ?? false) || ($isEdit && ($f['nonnull'] ?? false));
        $progress = $isEdit ? '' : ' ('.($s['step'] + 1).'/'.count($s['steps']).')';
        $head = $this->wizardTitle($s).$progress."\n\n".($error ? '⚠️ '.$error."\n\n" : '');
        $kb = [];

        if ($isEdit) {
            $current = $this->findOwned($s['entity'], (int) $s['id']);
            $cur = $current ? ($s['entity'] === 'reminder' && $key === 'message' ? ($current->payload['message'] ?? null) : $current->{$key}) : null;
            if ($f['t'] === 'ref' && $cur) $cur = $this->refTitle($f['ref'], (int) $cur);
            $head .= $this->t('current_value', ['value' => $f['t'] === 'enum' ? $this->displayValue($form, $f, $cur) : ($f['t'] === 'datetime' && $cur ? $this->dt($cur) : $this->fmt($cur))])."\n\n";
        }

        switch ($f['t']) {
            case 'ref':
                $items = $this->refChoices($s, $f);
                $text = $head.$this->t('pick', ['label' => $f['l'], 'req' => $required ? $this->t('required_suffix') : ''])
                    .($s['filter'] ? $this->t('filter_line', ['filter' => $s['filter']]) : '').$this->t('filter_hint');
                if ($items->isEmpty()) {
                    $text .= $this->t('none_found').($f['ref'] === 'project' && $required ? $this->t('make_project_first') : '');
                }
                foreach ($items as $x) {
                    $kb[] = [$this->btn('• '.$this->titleOf($f['ref'], $x), 'wz:p:'.$x->id)];
                }
                break;
            case 'enum':
                $opts = $this->options($form, $key);
                $s['opts'] = array_column($opts, 0);
                $this->putState($c, $s);
                $text = $head.$this->t('pick', ['label' => $f['l'], 'req' => '']).(($f['custom'] ?? false) ? $this->t('custom_hint') : '');
                $row = [];
                foreach ($opts as $i => [$value, $label]) {
                    $row[] = $this->btn($label, 'wz:o:'.$i);
                    if (count($row) === 2) { $kb[] = $row; $row = []; }
                }
                $kb[] = $row;
                break;
            case 'date':
                $text = $head.$this->t('enter_date', ['label' => $f['l']]);
                $kb[] = [$this->btn($this->t('quick.today'), 'wz:q:today'), $this->btn($this->t('quick.tomorrow'), 'wz:q:tomorrow'), $this->btn($this->t('quick.week'), 'wz:q:week')];
                break;
            case 'datetime':
                $text = $head.$this->t('enter_datetime', ['label' => $f['l']]);
                $kb[] = [$this->btn($this->t('quick.now'), 'wz:q:now'), $this->btn($this->t('quick.hour'), 'wz:q:hour'), $this->btn($this->t('quick.tom9'), 'wz:q:tom9')];
                break;
            case 'level':
            case 'pct':
                $text = $head.$this->t('enter_level', ['label' => $f['l']]);
                $kb[] = array_map(fn ($v) => $this->btn((string) $v, 'wz:q:'.$v), $f['t'] === 'pct' ? [0, 25, 50, 75, 100] : [25, 50, 75, 100]);
                break;
            case 'int':
            case 'num':
                $text = $head.$this->t('enter_number', ['label' => $f['l']]);
                break;
            default:
                $text = $head.$this->t('enter_text', ['label' => $f['l'], 'req' => $required ? $this->t('required_suffix') : '']);
        }

        $nav = [];
        if (!$required) {
            $nav[] = $isEdit ? $this->btn($this->t('btn.clear'), 'wz:skip') : ($f['t'] === 'ref' ? $this->btn($this->t('btn.no_value'), 'wz:null') : $this->btn($this->t('btn.skip'), 'wz:skip'));
        }
        if (!$isEdit && $s['step'] > 0) {
            $nav[] = $this->btn($this->t('btn.wz_back'), 'wz:back');
        }
        $kb[] = $nav;
        if (!$isEdit && $this->requiredDone($s) && $s['step'] < count($s['steps'])) {
            $kb[] = [$this->btn($this->t('btn.finish'), 'wz:fin')];
        }
        $kb[] = [$this->btn($this->t('btn.cancel'), 'wz:x')];

        $mid = $this->show($c, $m, $text, $kb);
        if ($mid && $mid !== ($s['mid'] ?? null)) {
            $s = $this->state($c) ?: $s;
            $s['mid'] = $mid;
            $this->putState($c, $s);
        }
    }

    private function refChoices(array $s, array $f): Collection
    {
        $q = $this->owned($this->entity($f['ref'])['class']);
        $titleCol = $this->entity($f['ref'])['title'];
        if ($f['ref'] === 'task') {
            $q->whereNotIn('status', ['completed', 'cancelled']);
            if ($f['k'] === 'depends_on_task_id' && !empty($s['data']['task_id'])) {
                $q->whereKeyNot((int) $s['data']['task_id']);
            }
        }
        if ($f['ref'] === 'milestone') {
            $projectId = $s['data']['project_id'] ?? null;
            if ($projectId === null && $s['flow'] === 'edit' && $s['entity'] === 'task') {
                $projectId = $this->findOwned('task', (int) $s['id'])?->project_id;
            }
            if ($projectId) {
                $q->where('project_id', (int) $projectId);
            }
        }
        if (!empty($s['filter'])) {
            $q->where($titleCol, 'ilike', $this->like((string) $s['filter']));
        }
        return $q->latest('id')->limit(12)->get();
    }

    private function wizardCallback(string|int $c, int $m, string $op, string $arg): void
    {
        $s = $this->state($c);
        if (($s['mode'] ?? null) !== 'wizard') {
            $this->show($c, $m, $this->t('expired'), $this->mainKeyboard());
            return;
        }
        $s['mid'] = $m;
        $key = $s['steps'][$s['step']] ?? null;
        $form = $this->formOf($s);
        $f = $key ? $this->fieldDef($form, $key) : null;

        switch ($op) {
            case 'x':
                $target = $this->cancelTarget($s);
                $this->clearState($c);
                $this->route($c, $m, $target);
                return;
            case 'back':
                if ($s['step'] > 0) {
                    $s['step']--;
                    unset($s['data'][$s['steps'][$s['step']]]);
                }
                $s['filter'] = null;
                $this->putState($c, $s);
                $this->askStep($c, $m, $s);
                return;
            case 'fin':
                if (!$this->requiredDone($s)) {
                    $this->askStep($c, $m, $s, $this->t('required_missing'));
                    return;
                }
                $s['step'] = count($s['steps']);
                $this->putState($c, $s);
                $this->summary($c, $m, $s);
                return;
            case 'ok':
                if ($key !== null) {
                    $this->askStep($c, $m, $s);
                    return;
                }
                $this->commit($c, $m, $s);
                return;
        }

        if (!$f) {
            $this->askStep($c, $m, $s);
            return;
        }
        $isEdit = $s['flow'] === 'edit';
        $required = ($f['req'] ?? false) || ($isEdit && ($f['nonnull'] ?? false));

        switch ($op) {
            case 'skip':
            case 'null':
                if ($required) {
                    $this->askStep($c, $m, $s, $this->t('field_required'));
                    return;
                }
                if ($isEdit || $op === 'null') {
                    $s['data'][$key] = null;
                } else {
                    unset($s['data'][$key]);
                }
                $this->advance($c, $m, $s);
                return;
            case 'p':
                if ($f['t'] !== 'ref') break;
                $picked = $this->findOwned($f['ref'], $this->int($arg));
                if (!$picked) {
                    $this->askStep($c, $m, $s, $this->t('ref_not_owned'));
                    return;
                }
                if ($f['k'] === 'depends_on_task_id' && (int) $picked->id === (int) ($s['data']['task_id'] ?? 0)) {
                    $this->askStep($c, $m, $s, $this->t('self_dependency'));
                    return;
                }
                $s['data'][$key] = (int) $picked->id;
                $this->advance($c, $m, $s);
                return;
            case 'o':
                if ($f['t'] !== 'enum') break;
                $opts = $s['opts'] ?? array_column($this->options($form, $key), 0);
                $i = $this->int($arg);
                if (!ctype_digit($arg) || !isset($opts[$i])) {
                    $this->askStep($c, $m, $s, $this->t('invalid_option'));
                    return;
                }
                $s['data'][$key] = $opts[$i];
                $this->advance($c, $m, $s);
                return;
            case 'q':
                [$ok, $value, $err] = $this->parse($f, $this->quick($arg));
                if (!$ok) {
                    $this->askStep($c, $m, $s, $err);
                    return;
                }
                $s['data'][$key] = $value;
                $this->advance($c, $m, $s);
                return;
        }
        $this->askStep($c, $m, $s);
    }

    private function quick(string $code): string
    {
        $now = $this->now();
        return match ($code) {
            'now' => $now->format('Y-m-d H:i'),
            'hour' => $now->copy()->addHour()->format('Y-m-d H:i'),
            'tom9' => $now->copy()->addDay()->format('Y-m-d').' 09:00',
            'today' => $now->format('Y-m-d'),
            'tomorrow' => $now->copy()->addDay()->format('Y-m-d'),
            'week' => $now->copy()->addWeek()->format('Y-m-d'),
            default => $code,
        };
    }

    private function wizardText(string|int $c, array $s, string $text): void
    {
        $key = $s['steps'][$s['step']] ?? null;
        if ($key === null) {
            $this->show($c, null, $this->t('press_save'), [[$this->btn($this->t('btn.save'), 'wz:ok')], [$this->btn($this->t('btn.cancel'), 'wz:x')]]);
            return;
        }
        $form = $this->formOf($s);
        $f = $this->fieldDef($form, $key);
        if ($f['t'] === 'ref') {
            $s['filter'] = mb_substr($text, 0, 50);
            $this->putState($c, $s);
            $this->askStep($c, null, $s);
            return;
        }
        if ($f['t'] === 'enum' && !($f['custom'] ?? false)) {
            $opts = array_column($this->options($form, $key), 0);
            if (!in_array($text, $opts, true)) {
                $this->askStep($c, null, $s, $this->t('use_buttons'));
                return;
            }
        }
        [$ok, $value, $err] = $this->parse($f, $text);
        if (!$ok) {
            $this->askStep($c, null, $s, $err);
            return;
        }
        $s['data'][$key] = $value;
        $this->advance($c, null, $s);
    }

    private function advance(string|int $c, ?int $m, array $s): void
    {
        $s['step']++;
        $s['filter'] = null;
        unset($s['opts']);
        $this->putState($c, $s);
        $this->askStep($c, $m, $s);
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    private function parse(array $f, string $raw): array
    {
        $v = trim($this->normalizeDigits($raw));
        if ($v === '') {
            return [false, null, $this->t('empty_value')];
        }
        switch ($f['t']) {
            case 'int':
                return ctype_digit($v) && strlen($v) < 9 ? [true, (int) $v, null] : [false, null, $this->t('invalid_int')];
            case 'num':
                return is_numeric($v) && (float) $v >= 0 && (float) $v < 1000000 ? [true, (float) $v, null] : [false, null, $this->t('invalid_num')];
            case 'level':
            case 'pct':
                return is_numeric($v) && (float) $v >= 0 && (float) $v <= 100 ? [true, $f['t'] === 'pct' ? (float) $v : (int) round((float) $v), null] : [false, null, $this->t('invalid_level')];
            case 'date':
                $d = $this->parseDateTime($v, false);
                return $d ? [true, $d->format('Y-m-d'), null] : [false, null, $this->t('invalid_date')];
            case 'datetime':
                $d = $this->parseDateTime($v, true);
                return $d ? [true, $d->format('Y-m-d H:i'), null] : [false, null, $this->t('invalid_datetime')];
            case 'text':
                return [true, mb_substr($v, 0, 255), null];
            default:
                return [true, mb_substr($v, 0, 10000), null];
        }
    }

    private function normalizeDigits(string $v): string
    {
        return LocalDate::latinDigits($v);
    }

    /** Parses Gregorian or Jalali (year < 1700) input in Asia/Tehran. */
    private function parseDateTime(string $v, bool $withTime): ?Carbon
    {
        $v = str_replace('/', '-', mb_strtolower(trim($v)));
        $now = $this->now();
        $time = null;
        if (preg_match('/(\d{1,2}):(\d{2})$/u', $v, $tm)) {
            $time = [(int) $tm[1], (int) $tm[2]];
            $v = trim(mb_substr($v, 0, mb_strlen($v) - mb_strlen($tm[0])));
            if ($time[0] > 23 || $time[1] > 59) return null;
        }
        if (in_array($v, ['now', 'اکنون', 'الان'], true)) {
            return $now->copy()->second(0);
        }
        if ($v === '' || in_array($v, ['today', 'امروز'], true)) {
            if ($v === '' && $time === null) return null;
            $date = $now->copy();
        } elseif (in_array($v, ['tomorrow', 'فردا'], true)) {
            $date = $now->copy()->addDay();
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $dm)) {
            [$y, $mo, $d] = [(int) $dm[1], (int) $dm[2], (int) $dm[3]];
            if ($y < 1700) {
                if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
                [$y, $mo, $d] = $this->jalaliToGregorian($y, $mo, $d);
            }
            if (!checkdate($mo, $d, $y)) return null;
            $date = Carbon::create($y, $mo, $d, 0, 0, 0, $this->tz());
        } else {
            return null;
        }
        if ($time !== null) {
            return $date->setTime($time[0], $time[1]);
        }
        return $withTime ? $date->setTime(9, 0) : $date->startOfDay();
    }

    /** @return array{0:int,1:int,2:int} */
    private function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        return LocalDate::jalaliToGregorian($jy, $jm, $jd);
    }

    private function displayValue(string $form, array $f, mixed $v): string
    {
        if ($v === null) return '—';
        if ($f['t'] === 'ref') return $this->refTitle($f['ref'], (int) $v);
        if ($f['t'] === 'enum') {
            foreach ($this->options($form, $f['k']) as [$value, $label]) {
                if ($value === (string) $v) return $label;
            }
        }
        return $this->fmt($v);
    }

    private function summary(string|int $c, ?int $m, array $s): void
    {
        $form = $this->formOf($s);
        $lines = [];
        foreach ($this->fields($form) as $f) {
            if (array_key_exists($f['k'], $s['data']) && $s['data'][$f['k']] !== null) {
                $lines[] = $f['l'].': '.$this->displayValue($form, $f, $s['data'][$f['k']]);
            }
        }
        $text = $this->wizardTitle($s).$this->t('summary').($lines ? implode("\n", $lines) : '—');
        $mid = $this->show($c, $m, $text, [
            [$this->btn($this->t('btn.save'), 'wz:ok')],
            [$this->btn($this->t('btn.wz_back'), 'wz:back'), $this->btn($this->t('btn.cancel'), 'wz:x')],
        ]);
        if ($mid) {
            $s['mid'] = $mid;
            $this->putState($c, $s);
        }
    }

    /** Converts a wizard datetime string (Asia/Tehran) into an app-timezone Carbon for storage. */
    private function toStorage(?string $v): ?Carbon
    {
        return $v === null ? null : Carbon::parse($v, $this->tz())->timezone(config('app.timezone'));
    }

    /** Casts wizard data into model attributes for the given form. */
    private function attributes(string $form, array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $f = $this->fieldDef($form, $k);
            if (!$f) continue;
            $out[$k] = $f['t'] === 'datetime' && $v !== null ? $this->toStorage((string) $v) : $v;
        }
        return $out;
    }

    private function jumpTo(string|int $c, ?int $m, array $s, string $key, string $error): void
    {
        $idx = array_search($key, $s['steps'], true);
        if ($idx === false) {
            $this->clearState($c);
            $this->show($c, $m, '⚠️ '.$error, [$this->back($this->cancelTarget($s))]);
            return;
        }
        $s['step'] = $idx;
        unset($s['data'][$key]);
        $this->putState($c, $s);
        $this->askStep($c, $m, $s, $error);
    }

    private function commit(string|int $c, ?int $m, array $s): void
    {
        $form = $this->formOf($s);
        $d = $s['data'];
        // Re-verify ownership of every referenced record at commit time.
        foreach ($d as $k => $v) {
            $f = $this->fieldDef($form, $k);
            if ($f && $f['t'] === 'ref' && $v !== null && !$this->findOwned($f['ref'], (int) $v)) {
                $this->clearState($c);
                $this->show($c, $m, $this->t('ref_gone'), [$this->back($this->cancelTarget($s))]);
                return;
            }
        }

        match ($s['flow']) {
            'create' => $this->commitCreate($c, $m, $s),
            'edit' => $this->commitEdit($c, $m, $s),
            'exec' => $this->commitExecution($c, $m, $s),
            'dep' => $this->commitDependency($c, $m, $s),
            'sched' => $this->commitSchedule($c, $m, $s),
            'dplan' => $this->commitDailyPlan($c, $m, $s),
            'fail' => $this->commitFailure($c, $m, $s),
            default => $this->home($c, $m),
        };
    }

    private function commitCreate(string|int $c, ?int $m, array $s): void
    {
        $e = $s['entity'];
        $attr = $this->attributes($e, $s['data']);
        $uid = $this->uid();

        if ($e === 'task' && isset($attr['planned_start'], $attr['planned_end']) && $attr['planned_end']->lt($attr['planned_start'])) {
            $this->jumpTo($c, $m, $s, 'planned_end', $this->t('end_before_start'));
            return;
        }

        $x = DB::transaction(function () use ($e, $attr, $uid, $c) {
            $attr['user_id'] = $uid;
            switch ($e) {
                case 'task':
                    $priority = $attr['priority'] ?? null;
                    $attr['status'] ??= 'inbox';
                    $task = $this->planner->createTask($attr);
                    if ($priority !== null && $task->priority !== $priority) {
                        $task->update(['priority' => $priority]); // explicit user choice wins over the computed priority
                    }
                    $this->progress->recalculateFromTask($task);
                    return $task;
                case 'reminder':
                    return Reminder::create([
                        'user_id' => $uid,
                        'task_id' => $attr['task_id'] ?? null,
                        'type' => $attr['type'] ?? 'telegram',
                        'scheduled_at' => $attr['scheduled_at'],
                        'status' => 'pending',
                        'payload' => ['chat_id' => (string) $c, 'message' => $attr['message'] ?? null],
                    ]);
                case 'decision':
                    $attr['decided_at'] ??= now();
                    return Decision::create($attr);
                default:
                    $model = $this->entity($e)['class']::create($attr);
                    if ($e === 'milestone' && $model->project) $this->progress->project($model->project);
                    if ($e === 'project' && $model->goal) $this->progress->goal($model->goal);
                    return $model;
            }
        });
        $this->clearState($c);
        $this->entityView($c, $m, $e, (int) $x->id, $this->t('created', ['entity' => $this->entity($e)['label']]));
    }

    private function commitEdit(string|int $c, ?int $m, array $s): void
    {
        $e = $s['entity'];
        $x = $this->findOwned($e, (int) $s['id']);
        if (!$x) {
            $this->clearState($c);
            $this->show($c, $m, $this->t('not_owned'), [$this->back()]);
            return;
        }
        $field = $s['steps'][0];
        $attr = $this->attributes($e, [$field => $s['data'][$field] ?? null]);

        if ($e === 'reminder' && $field === 'message') {
            $payload = is_array($x->payload) ? $x->payload : [];
            $payload['message'] = $attr['message'];
            $payload['chat_id'] ??= (string) $c;
            $x->update(['payload' => $payload]);
        } elseif ($e === 'task') {
            $start = $field === 'planned_start' ? $attr[$field] : $x->planned_start;
            $end = $field === 'planned_end' ? $attr[$field] : $x->planned_end;
            if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start))) {
                $s['step'] = 0;
                $this->putState($c, $s);
                $this->askStep($c, $m, $s, $this->t('end_before_start'));
                return;
            }
            $old = ['goal' => $x->goal_id, 'project' => $x->project_id, 'milestone' => $x->milestone_id];
            if ($field === 'status') {
                $attr['completed_at'] = null;
            }
            $x->update($attr);
            if ($x->status === 'completed') {
                $this->planner->complete($x);
            } elseif (in_array($field, self::PRIORITY_INPUTS, true)) {
                // Only fields that feed PriorityCalculator trigger a recalculation,
                // so an explicitly chosen priority is not silently overwritten.
                $this->planner->recalculatePriority($x);
            }
            $this->progress->recalculateFromTask($x);
            if ($old['milestone'] && $old['milestone'] !== $x->milestone_id && ($ms = $this->owned(Milestone::class)->find($old['milestone']))) $this->progress->milestone($ms);
            if ($old['project'] && $old['project'] !== $x->project_id && ($pr = $this->owned(Project::class)->find($old['project']))) $this->progress->project($pr);
            if ($old['goal'] && $old['goal'] !== $x->goal_id && ($go = $this->owned(Goal::class)->find($old['goal']))) $this->progress->goal($go);
        } else {
            if ($e === 'reminder' && $field === 'scheduled_at' && in_array($x->status, ['failed'], true)) {
                $attr['status'] = 'pending';
                $attr['attempts'] = 0;
                $attr['next_attempt_at'] = null;
            }
            $x->update($attr);
            if ($e === 'milestone' && $x->project) $this->progress->project($x->project);
            if ($e === 'project' && $x->goal) $this->progress->goal($x->goal);
        }
        $this->clearState($c);
        $this->entityView($c, $m, $e, (int) $x->id, $this->t('saved'));
    }

    private function commitExecution(string|int $c, ?int $m, array $s): void
    {
        $d = $s['data'];
        $task = $this->ownedTask((int) ($d['task_id'] ?? 0));
        if (!$task) {
            $this->clearState($c);
            $this->show($c, $m, $this->t('task_not_found'), [$this->back('xlogs')]);
            return;
        }
        $start = $this->toStorage($d['started_at'] ?? null) ?? now();
        $end = $this->toStorage($d['ended_at'] ?? null);
        if ($end && $end->lt($start)) {
            $this->jumpTo($c, $m, $s, 'ended_at', $this->t('exec_end_before'));
            return;
        }
        $payload = ['started_at' => $start, 'ended_at' => $end];
        foreach (['duration_minutes', 'focus_level', 'energy_level', 'result', 'blocker', 'notes'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null) $payload[$k] = $d[$k];
        }
        $log = $this->planner->logTime($task, $payload);
        $this->clearState($c);
        $this->executionView($c, $m, (int) $log->id, $this->t('exec_logged', ['minutes' => LocalDate::number((int) $log->duration_minutes)]));
    }

    private function commitDependency(string|int $c, ?int $m, array $s): void
    {
        $d = $s['data'];
        $task = $this->ownedTask((int) ($d['task_id'] ?? 0));
        $dep = $this->ownedTask((int) ($d['depends_on_task_id'] ?? 0));
        $types = array_column($this->options('dep', 'type'), 0);
        if (!$task || !$dep || !in_array($d['type'] ?? '', $types, true)) {
            $this->clearState($c);
            $this->show($c, $m, $this->t('dep_invalid'), [$this->back('dependencies')]);
            return;
        }
        try {
            $this->dependencies->add($task, $dep, (string) $d['type']);
        } catch (\RuntimeException $e) {
            $this->clearState($c);
            $msg = str_contains($e->getMessage(), 'cycle') ? $this->t('dep_cycle') : $this->t('self_dependency');
            $this->show($c, $m, '⚠️ '.$msg, [$this->back('dependencies')]);
            return;
        }
        $this->clearState($c);
        $this->taskDependencies($c, $m, (int) $task->id, $this->t('dep_saved'));
    }

    private function commitSchedule(string|int $c, ?int $m, array $s): void
    {
        $d = $s['data'];
        $task = $this->ownedTask((int) ($d['task_id'] ?? 0));
        if (!$task) {
            $this->clearState($c);
            $this->show($c, $m, $this->t('task_not_found'), [$this->back('calendar')]);
            return;
        }
        $start = $this->toStorage($d['starts_at'] ?? null);
        $end = $this->toStorage($d['ends_at'] ?? null);
        if (!$start || !$end || !$end->gt($start)) {
            $this->jumpTo($c, $m, $s, 'ends_at', $this->t('sched_end_after'));
            return;
        }
        $plan = $this->owned(DailyPlan::class)->whereDate('plan_date', Carbon::parse($d['starts_at'], $this->tz())->toDateString())->first();
        $block = ScheduleBlock::create([
            'user_id' => $this->uid(),
            'daily_plan_id' => $plan?->id,
            'task_id' => $task->id,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
        $this->clearState($c);
        $this->scheduleView($c, $m, (int) $block->id, $this->t('sched_saved'));
    }

    private function commitDailyPlan(string|int $c, ?int $m, array $s): void
    {
        $d = $s['data'];
        $date = $d['plan_date'] ?? $this->now()->toDateString();
        unset($d['plan_date']);
        $values = array_filter($d, fn ($v) => $v !== null);
        $plan = $this->owned(DailyPlan::class)->whereDate('plan_date', $date)->first();
        if ($plan) {
            $plan->update($values);
        } else {
            DailyPlan::create(['user_id' => $this->uid(), 'plan_date' => $date] + $values + ['available_minutes' => 0, 'buffer_minutes' => 0]);
        }
        $this->clearState($c);
        $this->daily($c, $m, $date, $this->t('dplan_saved'));
    }

    private function commitFailure(string|int $c, ?int $m, array $s): void
    {
        $task = $this->ownedTask((int) ($s['data']['task_id'] ?? 0));
        $reason = (string) ($s['data']['reason'] ?? '');
        if (!$task || !in_array($reason, array_column($this->failureOptions(), 0), true)) {
            $this->clearState($c);
            $this->show($c, $m, $this->t('invalid_data'), [$this->back('failures')]);
            return;
        }
        $this->planner->logFailure($task, ['reason' => $reason]);
        $this->clearState($c);
        $this->entityView($c, $m, 'task', (int) $task->id, $this->t('failure_saved'));
    }

    // =====================================================================
    // Execution section
    // =====================================================================

    private function daily(string|int $c, int|null $m, ?string $date = null, ?string $notice = null): void
    {
        $this->clearState($c);
        $date ??= $this->now()->toDateString();
        $plan = $this->owned(DailyPlan::class)->whereDate('plan_date', $date)->first();
        $recent = $this->owned(DailyPlan::class)->whereDate('plan_date', '!=', $date)->latest('plan_date')->limit(5)->get();
        $lines = [($notice ? $notice."\n\n" : '').$this->t('daily_title', ['date' => LocalDate::date($date.' 12:00:00', null, $this->tz())]), ''];
        if ($plan) {
            $lines[] = $this->t('dp_available', ['minutes' => LocalDate::number((int) $plan->available_minutes)]);
            $lines[] = $this->t('dp_planned', ['minutes' => LocalDate::number((int) $plan->planned_minutes)]);
            $lines[] = $this->t('dp_completed', ['minutes' => LocalDate::number((int) $plan->completed_minutes)]);
            $lines[] = $this->t('dp_buffer', ['minutes' => LocalDate::number((int) $plan->buffer_minutes)]);
            $lines[] = $this->t('focus_energy', ['focus' => $this->fmt($plan->focus_level), 'energy' => $this->fmt($plan->energy_level)]);
            if ($plan->notes) $lines[] = $this->t('dp_notes', ['notes' => $plan->notes]);
            $blocks = $this->owned(ScheduleBlock::class)->with('task')->where('daily_plan_id', $plan->id)->orderBy('starts_at')->get();
            if ($blocks->isNotEmpty()) {
                $lines[] = $this->t('dp_blocks');
                foreach ($blocks as $b) $lines[] = '• '.$this->dt($b->starts_at, 'H:i').'–'.$this->dt($b->ends_at, 'H:i').' '.($b->task?->title ?? $this->t('focus_block'));
            }
        } else {
            $lines[] = $this->t('dp_none');
        }
        if ($recent->isNotEmpty()) {
            $lines[] = $this->t('dp_recent');
            foreach ($recent as $r) $lines[] = $this->t('dp_recent_line', ['date' => LocalDate::date($r->plan_date->format('Y-m-d').' 12:00:00', null, $this->tz()), 'planned' => LocalDate::number((int) $r->planned_minutes), 'available' => LocalDate::number((int) $r->available_minutes)]);
        }
        $this->show($c, $m, implode("\n", $lines), [
            [$this->btn($plan ? $this->t('dp_edit_today') : $this->t('dp_new_today'), 'dplan:today')],
            [$this->btn($this->t('dp_other'), 'dplan')],
            $this->back('execution'),
        ]);
    }

    private function calendar(string|int $c, int $m): void
    {
        $this->clearState($c);
        $from = $this->now()->startOfDay();
        $to = $from->copy()->addDays(8);
        $blocks = $this->owned(ScheduleBlock::class)->with('task')
            ->where('starts_at', '>=', $from->copy()->timezone(config('app.timezone')))
            ->where('starts_at', '<', $to->copy()->timezone(config('app.timezone')))
            ->orderBy('starts_at')->limit(60)->get();
        $lines = [$this->t('cal_title'), ''];
        $kb = [];
        for ($i = 0; $i < 8; $i++) {
            $day = $from->copy()->addDays($i);
            $items = $blocks->filter(fn ($b) => Carbon::parse($b->starts_at)->timezone($this->tz())->isSameDay($day));
            $lines[] = ($i === 0 ? $this->t('cal_today').' ' : '').LocalDate::weekday($day).' '.LocalDate::date($day, null, $this->tz()).':';
            if ($items->isEmpty()) {
                $lines[] = '   —';
            }
            foreach ($items as $b) {
                $lines[] = '   '.$this->dt($b->starts_at, 'H:i').'–'.$this->dt($b->ends_at, 'H:i').' '.($b->task?->title ?? $this->t('focus_block'));
                if (count($kb) < 12) $kb[] = [$this->btn($this->dt($b->starts_at, 'm/d H:i').' '.($b->task?->title ?? $this->t('focus_block')), 'sb:'.$b->id)];
            }
        }
        $kb[] = [$this->btn($this->t('new_block'), 'newsched')];
        $kb[] = $this->back('execution');
        $this->show($c, $m, implode("\n", $lines), $kb);
    }

    private function scheduleView(string|int $c, ?int $m, int $id, ?string $notice = null): void
    {
        $b = $id > 0 ? $this->owned(ScheduleBlock::class)->with('task')->find($id) : null;
        if (!$b) {
            $this->show($c, $m, $this->t('block_not_found'), [$this->back('calendar')]);
            return;
        }
        $text = $this->lines([
            $notice ? $notice."\n" : null,
            $this->t('block_title', ['id' => $b->id]),
            $this->t('lbl_task', ['v' => $b->task?->title ?? '—']),
            $this->t('lbl_start', ['v' => $this->dt($b->starts_at)]),
            $this->t('lbl_end', ['v' => $this->dt($b->ends_at)]),
            $this->t('lbl_status', ['v' => $this->enumLabel((string) $b->status)]),
            $this->t('lbl_source', ['v' => (string) $b->source]),
        ]);
        $kb = [];
        if ($b->task_id) $kb[] = [$this->btn($this->t('btn.view_task'), 'v:task:'.$b->task_id)];
        $kb[] = [$this->btn($this->t('delete_block'), 'sbdel:'.$b->id)];
        $kb[] = $this->back('calendar');
        $this->show($c, $m, $text, $kb);
    }

    private function scheduleDelete(string|int $c, int $m, int $id, bool $confirmed): void
    {
        $b = $id > 0 ? $this->owned(ScheduleBlock::class)->find($id) : null;
        if (!$b) {
            $this->show($c, $m, $this->t('block_not_found'), [$this->back('calendar')]);
            return;
        }
        if (!$confirmed) {
            $this->show($c, $m, $this->t('confirm_delete_block'), [[$this->btn($this->t('btn.yes'), 'sbdelok:'.$id)], [$this->btn($this->t('btn.no'), 'sb:'.$id)]]);
            return;
        }
        $b->delete();
        $this->calendar($c, $m);
    }

    private function executionLogs(string|int $c, int $m): void
    {
        $this->clearState($c);
        $logs = $this->owned(ExecutionLog::class)->with('task')->latest('started_at')->limit(15)->get();
        $text = $this->t('exec_title')."\n\n".($logs->isEmpty() ? $this->t('exec_empty') : $logs->map(fn ($x) => $this->t('exec_line', ['date' => $this->dt($x->started_at, 'm/d H:i'), 'task' => $x->task?->title ?? $this->t('task_fallback'), 'minutes' => LocalDate::number((int) $x->duration_minutes)]))->implode("\n"));
        $kb = [[$this->btn($this->t('log_work'), 'newexec')]];
        foreach ($logs as $x) {
            $kb[] = [$this->btn($this->dt($x->started_at, 'm/d H:i').' '.($x->task?->title ?? $this->t('task_fallback')), 'xl:'.$x->id)];
        }
        $kb[] = $this->back('execution');
        $this->show($c, $m, $text, $kb);
    }

    private function executionView(string|int $c, ?int $m, int $id, ?string $notice = null): void
    {
        $x = $id > 0 ? $this->owned(ExecutionLog::class)->with('task')->find($id) : null;
        if (!$x) {
            $this->show($c, $m, $this->t('exec_not_found'), [$this->back('xlogs')]);
            return;
        }
        $text = $this->lines([
            $notice ? $notice."\n" : null,
            $this->t('exec_view_title', ['id' => $x->id]),
            $this->t('lbl_task', ['v' => $x->task?->title ?? '—']),
            $this->t('lbl_start', ['v' => $this->dt($x->started_at)]),
            $this->t('lbl_end', ['v' => $this->dt($x->ended_at)]),
            $this->t('lbl_duration', ['minutes' => LocalDate::number((int) $x->duration_minutes)]),
            $this->t('focus_energy', ['focus' => $this->fmt($x->focus_level), 'energy' => $this->fmt($x->energy_level)]),
            $x->result ? $this->t('lbl_result', ['v' => $x->result]) : null,
            $x->blocker ? $this->t('lbl_blocker', ['v' => $x->blocker]) : null,
            $x->notes ? $this->t('lbl_notes', ['v' => $x->notes]) : null,
        ]);
        $this->show($c, $m, $text, [
            [$this->btn($this->t('btn.view_task'), 'v:task:'.$x->task_id), $this->btn($this->t('btn.delete'), 'xldel:'.$x->id)],
            $this->back('xlogs'),
        ]);
    }

    private function executionDelete(string|int $c, int $m, int $id, bool $confirmed): void
    {
        $x = $id > 0 ? $this->owned(ExecutionLog::class)->find($id) : null;
        if (!$x) {
            $this->show($c, $m, $this->t('exec_not_found'), [$this->back('xlogs')]);
            return;
        }
        if (!$confirmed) {
            $this->show($c, $m, $this->t('confirm_delete_exec'), [[$this->btn($this->t('btn.yes'), 'xldelok:'.$id)], [$this->btn($this->t('btn.no'), 'xl:'.$id)]]);
            return;
        }
        $x->delete();
        $this->executionLogs($c, $m);
    }

    private function depLabel(string $type): string
    {
        $label = __('planner.dependency_type.'.$type);
        return $label === 'planner.dependency_type.'.$type ? $type : (string) $label;
    }

    private function dependencyList(string|int $c, int $m): void
    {
        $this->clearState($c);
        $deps = $this->owned(TaskDependency::class)->with(['task', 'dependsOn'])->latest('id')->limit(15)->get();
        $text = $this->t('deps_title')."\n\n".($deps->isEmpty() ? $this->t('deps_empty') : $deps->map(fn ($d) => '• '.($d->task?->title ?? '—').' ← '.$this->depLabel($d->type).' → '.($d->dependsOn?->title ?? '—'))->implode("\n"));
        $kb = [[$this->btn($this->t('new_dep'), 'newdep')]];
        foreach ($deps as $d) {
            $kb[] = [$this->btn('🗑 '.($d->task?->title ?? '—').' → '.($d->dependsOn?->title ?? '—'), 'depdel:'.$d->id)];
        }
        $kb[] = $this->back('execution');
        $this->show($c, $m, $text, $kb);
    }

    private function taskDependencies(string|int $c, ?int $m, int $taskId, ?string $notice = null): void
    {
        $t = $this->ownedTask($taskId);
        if (!$t) {
            $this->show($c, $m, $this->t('task_not_found'), [$this->back('tasks')]);
            return;
        }
        $out = $this->owned(TaskDependency::class)->with('dependsOn')->where('task_id', $t->id)->get();
        $in = $this->owned(TaskDependency::class)->with('task')->where('depends_on_task_id', $t->id)->get();
        $text = $this->lines([
            $notice ? $notice."\n" : null,
            $this->t('task_deps_title', ['title' => $t->title]),
            '',
            $this->t('this_task'),
            $out->isEmpty() ? '   —' : $out->map(fn ($d) => '   '.$this->depLabel($d->type).' «'.($d->dependsOn?->title ?? '—').'» ['.$this->enumLabel((string) ($d->dependsOn?->status ?? '—')).']')->implode("\n"),
            '',
            $this->t('dependents'),
            $in->isEmpty() ? '   —' : $in->map(fn ($d) => $this->t('dependent_line', ['task' => $d->task?->title ?? '—', 'type' => $this->depLabel($d->type)]))->implode("\n"),
            '',
            $this->t('can_start', ['v' => __($this->dependencies->canStart($t) ? 'common.yes' : 'common.no')]),
        ]);
        $kb = [[$this->btn($this->t('add_dep'), 'tdep:'.$t->id)]];
        foreach ($out->concat($in) as $d) {
            $kb[] = [$this->btn('🗑 '.($d->task?->title ?? $t->title).' → '.($d->dependsOn?->title ?? $t->title), 'depdel:'.$d->id)];
        }
        $kb[] = $this->back('v:task:'.$t->id);
        $this->show($c, $m, $text, $kb);
    }

    private function dependencyDelete(string|int $c, int $m, int $id, bool $confirmed): void
    {
        $d = $id > 0 ? $this->owned(TaskDependency::class)->with(['task', 'dependsOn'])->find($id) : null;
        if (!$d) {
            $this->show($c, $m, $this->t('dep_not_found'), [$this->back('dependencies')]);
            return;
        }
        if (!$confirmed) {
            $this->show($c, $m, $this->t('confirm_delete_dep', ['a' => $d->task?->title ?? '—', 'type' => $this->depLabel($d->type), 'b' => $d->dependsOn?->title ?? '—']), [
                [$this->btn($this->t('btn.yes'), 'depdelok:'.$id)], [$this->btn($this->t('btn.no'), 'dependencies')],
            ]);
            return;
        }
        $d->delete();
        $this->dependencyList($c, $m);
    }

    private function failures(string|int $c, int $m): void
    {
        $this->clearState($c);
        $reasons = FailureReason::query()->orderByDesc('severity')->orderBy('code')->get();
        $recent = $this->owned(Task::class)->whereNotNull('failure_reason')->latest('updated_at')->limit(8)->get();
        $text = $this->lines([
            $this->t('failures_title'),
            '',
            $reasons->isEmpty() ? $this->t('failures_empty') : $reasons->map(fn ($r) => $this->t('failure_line', ['name' => $this->failureName((string) $r->code, (string) $r->name), 'severity' => LocalDate::number($r->severity), 'preventable' => $this->enumLabel((string) $r->preventable)]))->implode("\n"),
            '',
            $this->t('recent_failures'),
            $recent->isEmpty() ? '   —' : $recent->map(fn ($t) => '   '.$t->title.' — '.$this->failureName((string) $t->failure_reason, (string) $t->failure_reason))->implode("\n"),
        ]);
        $this->show($c, $m, $text, [[$this->btn($this->t('log_failure'), 'fail')], $this->back('execution')]);
    }

    // =====================================================================
    // Analysis
    // =====================================================================

    private function reviewList(string|int $c, int $m): void
    {
        $this->clearState($c);
        $items = $this->owned(Review::class)->latest('period_end')->latest('id')->limit(10)->get();
        $text = $this->t('reviews_title')."\n\n".($items->isEmpty() ? $this->t('reviews_empty') : $items->map(fn ($r) => '• '.$this->reviewType((string) $r->type).' — '.$this->reviewRange($r))->implode("\n"));
        $kb = [[$this->btn($this->t('new_daily_review'), 'revgen:daily'), $this->btn($this->t('new_weekly_review'), 'revgen:weekly')]];
        foreach ($items as $r) {
            $kb[] = [$this->btn($this->reviewType((string) $r->type).' '.$this->dt($r->period_end, 'm/d'), 'rv:'.$r->id)];
        }
        $kb[] = $this->back('analysis');
        $this->show($c, $m, $text, $kb);
    }

    private function reviewView(string|int $c, ?int $m, int $id, ?string $notice = null): void
    {
        $r = $id > 0 ? $this->owned(Review::class)->find($id) : null;
        if (!$r) {
            $this->show($c, $m, $this->t('review_not_found'), [$this->back('reviews')]);
            return;
        }
        $metrics = is_array($r->metrics_json) ? $r->metrics_json : [];
        $actions = collect(is_array($r->actions_json) ? $r->actions_json : [])
            ->map(fn ($a) => is_array($a) ? '• ['.($a['priority'] ?? '-').'] '.($a['action'] ?? $this->readable($a)) : '• '.$a)->implode("\n");
        $text = $this->lines([
            $notice ? $notice."\n" : null,
            $this->t('review_heading', ['type' => $this->reviewType((string) $r->type), 'range' => $this->reviewRange($r)]),
            '',
            (string) $r->summary,
            '',
            $this->metricLines($metrics),
            '',
            $this->t('suggested_actions'),
            $actions !== '' ? $actions : '—',
        ]);
        $this->show($c, $m, $text, [$this->back('reviews')]);
    }

    private function generateReview(string|int $c, int $m, string $type): void
    {
        if (!in_array($type, ['daily', 'weekly'], true)) {
            $this->reviewList($c, $m);
            return;
        }
        $to = Carbon::now($this->tz());
        $from = $type === 'daily' ? $to->copy()->startOfDay() : $to->copy()->subDays(6)->startOfDay();
        $metrics = $this->analytics->summary($from->copy()->timezone(config('app.timezone')), $to->copy()->timezone(config('app.timezone')), $this->uid());
        $review = $this->reviews->generate($type, $from, $to, $metrics);
        if ((int) $review->user_id !== $this->uid()) {
            $review->forceFill(['user_id' => $this->uid()])->save();
        }
        $this->reviewView($c, $m, (int) $review->id, $this->t('review_created'));
    }

    private function reviewType(string $type): string
    {
        $label = __('planner.review_type.'.$type);
        return $label === 'planner.review_type.'.$type ? $type : (string) $label;
    }

    private function reviewRange(Review $r): string
    {
        return $this->t('range', ['from' => $this->dt($r->period_start, 'Y-m-d'), 'to' => $this->dt($r->period_end, 'Y-m-d')]);
    }

    private function metricLines(array $x): string
    {
        $labels = [];
        foreach (['tasks_created', 'tasks_completed', 'completion_rate', 'estimated_minutes', 'actual_minutes', 'estimation_error_percent',
            'execution_minutes', 'execution_sessions', 'overdue_open_tasks', 'tasks_with_failures', 'scheduled_tasks', 'deep_work_minutes',
            'velocity_completed_tasks_per_day', 'active_goals', 'average_goal_progress', 'overload_rate_percent', 'failure_rate_percent', 'blockers'] as $k) {
            $labels[$k] = (string) __('planner.metrics.'.$k);
        }
        $out = [];
        foreach ($labels as $k => $l) {
            if (array_key_exists($k, $x) && $x[$k] !== null) $out[] = $l.': '.(is_numeric($x[$k]) ? LocalDate::number($x[$k]) : $x[$k]);
        }
        foreach (['goal_health', 'priority_distribution', 'failure_reasons'] as $k) {
            if (!empty($x[$k]) && is_array($x[$k])) {
                $parts = [];
                foreach ($x[$k] as $name => $value) {
                    $parts[] = ($k === 'failure_reasons' ? $this->failureName((string) $name, (string) $name) : $this->enumLabel((string) $name)).'='.(is_numeric($value) ? LocalDate::number($value) : $this->readable($value));
                }
                $out[] = __('planner.metrics.'.$k).': '.implode(', ', $parts);
            }
        }
        return implode("\n", $out);
    }

    private function analyticsView(string|int $c, int $m): void
    {
        $this->clearState($c);
        $to = Carbon::now(config('app.timezone'));
        $x = $this->analytics->summary($to->copy()->subDays(30), $to, $this->uid());
        $this->show($c, $m, $this->t('analytics_title')."\n\n".$this->metricLines($x), [$this->back('analysis')]);
    }

    private function reportsMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, $this->t('reports_prompt'), [
            [$this->btn($this->t('period.day'), 'rday'), $this->btn($this->t('period.week'), 'rweek'), $this->btn($this->t('period.month'), 'rmonth')],
            $this->back('analysis'),
        ]);
    }

    private function report(string|int $c, int $m, string $period): void
    {
        $to = Carbon::now($this->tz());
        $from = match ($period) {
            'week' => $to->copy()->startOfWeek(app()->getLocale() === 'fa' ? Carbon::SATURDAY : Carbon::MONDAY),
            'month' => $to->copy()->startOfMonth(),
            default => $to->copy()->startOfDay(),
        };
        $x = $this->analytics->summary($from->copy()->timezone(config('app.timezone')), $to->copy()->timezone(config('app.timezone')), $this->uid());
        $n = fn ($v) => LocalDate::number($v ?? 0);
        $text = $this->lines([
            $this->t('report_title.'.$period),
            $this->t('range', ['from' => LocalDate::date($from, null, $this->tz()), 'to' => LocalDate::dateTime($to, null, $this->tz())]),
            '',
            $this->t('rep_created', ['v' => $n($x['tasks_created'] ?? 0)]),
            $this->t('rep_completed', ['v' => $n($x['tasks_completed'] ?? 0)]),
            $this->t('rep_rate', ['v' => $n($x['completion_rate'] ?? 0)]),
            $this->t('rep_actual', ['v' => $n($x['actual_minutes'] ?? 0)]),
            $this->t('rep_exec', ['minutes' => $n($x['execution_minutes'] ?? 0), 'sessions' => $n($x['execution_sessions'] ?? 0)]),
            $this->t('rep_deep', ['v' => $n($x['deep_work_minutes'] ?? 0)]),
            $this->t('rep_overdue', ['v' => $n($x['overdue_open_tasks'] ?? 0)]),
            $this->t('rep_failed', ['v' => $n($x['tasks_with_failures'] ?? 0)]),
        ]);
        $this->show($c, $m, $text, [
            [$this->btn($this->t('period_short.day'), 'rday'), $this->btn($this->t('period_short.week'), 'rweek'), $this->btn($this->t('period_short.month'), 'rmonth')],
            $this->back('analysis'),
        ]);
    }

    // =====================================================================
    // Knowledge & AI
    // =====================================================================

    private function beginAI(string|int $c, int $m): void
    {
        $this->putState($c, ['mode' => 'ai', 'mid' => $m]);
        $this->show($c, $m, $this->t('ai_prompt'), [$this->back('knowledge', $this->t('btn.cancel'))]);
    }

    private function runAI(string|int $c, string $text): void
    {
        $text = mb_substr(trim($text), 0, 500);
        if ($text === '') {
            $this->show($c, null, $this->t('ai_empty'), [$this->back('knowledge', $this->t('btn.cancel'))]);
            return;
        }
        $this->clearState($c);
        $mid = $this->show($c, null, $this->t('ai_thinking'));
        try {
            $response = app(AIPlannerService::class)->recommend(['focus' => $text], $this->uid());
        } catch (PlanLimitReached $e) {
            $this->show($c, $mid, $e->userMessage().$this->t('upgrade_hint', ['url' => route('billing.index')]), [$this->back('knowledge')]);
            return;
        } catch (\Throwable $e) {
            report($e);
            $this->show($c, $mid, $this->t('ai_unavailable'), [[$this->btn($this->t('ai_retry'), 'aiplanner')], $this->back('knowledge')]);
            return;
        }
        $this->show($c, $mid, $this->formatAI($response['result'] ?? []), [[$this->btn($this->t('ai_again'), 'aiplanner')], $this->back('knowledge')]);
    }

    public function formatAI(array $result): string
    {
        $block = function (mixed $v): string {
            if ($v === null || $v === '' || $v === []) return '—';
            if (!is_array($v)) return (string) $v;
            $lines = [];
            foreach ($v as $item) {
                if (is_array($item)) {
                    $main = $item['title'] ?? $item['action'] ?? $item['recommendation'] ?? $item['risk'] ?? $item['description'] ?? null;
                    $rest = array_filter($item, fn ($x, $k) => !in_array($k, ['title', 'action', 'recommendation', 'risk', 'description'], true) && is_scalar($x) && $x !== '', ARRAY_FILTER_USE_BOTH);
                    $lines[] = '• '.($main !== null && is_scalar($main) ? $main : $this->readable($item)).($main !== null && $rest ? ' ('.$this->readable($rest).')' : '');
                } else {
                    $lines[] = '• '.$item;
                }
            }
            return implode("\n", $lines);
        };
        return $this->lines([
            $this->t('ai_title'),
            '',
            $this->t('ai_summary'),
            $block($result['summary'] ?? null),
            '',
            $this->t('ai_risks'),
            $block($result['risks'] ?? null),
            '',
            $this->t('ai_recs'),
            $block($result['recommendations'] ?? null),
            '',
            $this->t('ai_disclaimer'),
        ]);
    }

    private function aiInteractions(string|int $c, int $m): void
    {
        $this->clearState($c);
        $items = $this->owned(AiInteraction::class)->latest('id')->limit(10)->get();
        $text = $this->t('aih_title')."\n\n".($items->isEmpty() ? $this->t('aih_empty') : $items->map(function (AiInteraction $x) {
            $in = is_array($x->input_payload) ? $x->input_payload : [];
            $outp = is_array($x->output_payload) ? $x->output_payload : [];
            $summary = $outp['summary'] ?? ($outp['raw'] ?? null);
            return $this->lines([
                '• '.$this->dt($x->created_at, 'm/d H:i').' — '.($x->intent ?: '—').' — '.$this->enumLabel((string) $x->status),
                '   '.$x->provider.($x->model ? ' / '.$x->model : '').($x->confidence !== null ? ' — confidence '.$x->confidence : ''),
                isset($in['focus']) ? $this->t('aih_request', ['v' => mb_substr((string) $in['focus'], 0, 120)]) : null,
                $summary !== null ? $this->t('aih_summary', ['v' => mb_substr(is_array($summary) ? $this->readable($summary) : (string) $summary, 0, 160)]) : null,
            ]);
        })->implode("\n\n"));
        $this->show($c, $m, $text, [$this->back('knowledge')]);
    }

    private function pendingActions(string|int $c, int $m): void
    {
        $this->clearState($c);
        $items = $this->owned(PendingAction::class)->latest('id')->limit(15)->get();
        $text = $this->t('pending_title')."\n\n".($items->isEmpty() ? $this->t('pending_empty') : $items->map(function (PendingAction $x) {
            $payload = is_array($x->payload) ? $x->payload : [];
            $args = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : $payload;
            $brief = collect($args)->filter(fn ($v) => is_scalar($v) && $v !== '')->take(4)->map(fn ($v, $k) => $k.': '.mb_substr((string) $v, 0, 40))->implode(' | ');
            return $this->lines([
                '• #'.$x->id.' '.$x->intent.' — '.$this->enumLabel((string) $x->status),
                $this->t('pending_created', ['v' => $this->dt($x->created_at, 'm/d H:i')]).($x->expires_at ? $this->t('pending_expires', ['v' => $this->dt($x->expires_at, 'm/d H:i')]) : ''),
                $brief !== '' ? '   '.$brief : null,
            ]);
        })->implode("\n"));
        $this->show($c, $m, $text, [$this->back('knowledge')]);
    }

    private function activity(string|int $c, int $m): void
    {
        $this->clearState($c);
        $items = $this->owned(ActivityLog::class)->latest('created_at')->latest('id')->limit(20)->get();
        $text = $this->t('activity_title')."\n\n".($items->isEmpty() ? $this->t('activity_empty') : $items->map(fn (ActivityLog $x) => '• '.$this->dt($x->created_at, 'm/d H:i').' — '.$this->actionLabel((string) $x->action).' — '.class_basename((string) $x->entity_type).($x->entity_id !== null ? ' #'.$x->entity_id : ''))->implode("\n"));
        $this->show($c, $m, $text, [$this->back('knowledge')]);
    }

    private function actionLabel(string $action): string
    {
        $label = __('bot.actions.'.$action);
        return $label === 'bot.actions.'.$action ? $action : (string) $label;
    }
}
