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
    private const TZ = 'Asia/Tehran';
    private const PAGE = 8;
    /** Task fields used by PlannerService::recalculatePriority(). */
    private const PRIORITY_INPUTS = ['importance', 'goal_id', 'deadline', 'progress', 'estimated_minutes', 'status'];

    private ?User $user = null;

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

    public function start(string|int $chatId, string $username, ?string $code = null): void
    {
        if ($code !== null && $code !== '') {
            $userId = Cache::pull('telegram:link:'.strtoupper($code));
            $user = $userId ? User::find($userId) : null;
            if (!$user || !$user->is_active) {
                $this->telegram->sendMessage($chatId, 'کد اتصال نامعتبر یا منقضی شده است.');
                return;
            }
            if ($user->telegram_chat_id !== null && (string) $user->telegram_chat_id !== (string) $chatId) {
                $this->telegram->sendMessage($chatId, 'این حساب قبلاً به Telegram دیگری متصل شده است.');
                return;
            }
            $user->update([
                'telegram_chat_id' => (string) $chatId,
                'telegram_username' => $username !== '' ? $username : null,
                'telegram_linked_at' => now(),
            ]);
            $this->clearState($chatId);
            $this->telegram->sendMessage($chatId, "اتصال با موفقیت انجام شد. سلام {$user->name} 👋\n\n🤖 Haman Planner", $this->mainKeyboard());
            return;
        }

        $user = User::where('telegram_chat_id', (string) $chatId)->where('is_active', true)->first();
        if (!$user) {
            $this->telegram->sendMessage($chatId, $this->linkHelp());
            return;
        }
        $this->syncIdentity($user, $username);
        $this->clearState($chatId);
        $this->telegram->sendMessage($chatId, "سلام {$user->name} 👋\n\n🤖 Haman Planner\nیک بخش را انتخاب کن:", $this->mainKeyboard());
    }

    public function handleText(string|int $chatId, string $username, string $text): void
    {
        $user = $this->authorized($chatId, $username);
        if (!$user) {
            return;
        }
        $this->runAs($user, function () use ($chatId, $text): void {
            $text = trim($text);
            if (in_array(mb_strtolower($text), ['/cancel', '/menu', '/home', 'لغو'], true)) {
                $this->clearState($chatId);
                $this->show($chatId, null, "🤖 Haman Planner\n\nیک بخش را انتخاب کن:", $this->mainKeyboard());
                return;
            }
            $state = $this->state($chatId);
            match ($state['mode'] ?? null) {
                'search' => $this->runSearch($chatId, $text),
                'ai' => $this->runAI($chatId, $text),
                'wizard' => $this->wizardText($chatId, $state, $text),
                default => $this->show($chatId, null, "🤖 Haman Planner\n\nاز منوی دکمه‌ای انتخاب کن:", $this->mainKeyboard()),
            };
        }, $chatId, null);
    }

    public function handleCallback(string|int $chatId, string $username, string $callbackId, int $messageId, string $data): void
    {
        $user = $this->authorized($chatId, $username);
        if (!$user) {
            $this->answer($callbackId, 'دسترسی ندارید.');
            return;
        }
        $this->answer($callbackId);
        $this->runAs($user, fn () => $this->route($chatId, $messageId, $data), $chatId, $messageId);
    }

    /** Runs one Telegram update with the planner user context set, always clearing it afterwards. */
    private function runAs(User $user, callable $callback, string|int $chatId, ?int $messageId): void
    {
        $this->user = $user;
        PlannerUserContext::set((int) $user->id);
        try {
            $callback();
        } catch (\Throwable $e) {
            report($e);
            try {
                $this->show($chatId, $messageId, '❌ خطا در اجرای عملیات. دوباره تلاش کن.', [[$this->btn('🏠 خانه', 'home')]]);
            } catch (\Throwable) {
            }
        } finally {
            PlannerUserContext::clear();
            $this->user = null;
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

    private function authorized(string|int $chatId, string $username): ?User
    {
        $u = User::where('telegram_chat_id', (string) $chatId)->where('is_active', true)->first();
        if (!$u) {
            $this->telegram->sendMessage($chatId, 'دسترسی فعال نیست.'."\n\n".$this->linkHelp());
            return null;
        }
        $this->syncIdentity($u, $username);
        return $u;
    }

    private function linkHelp(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        return "این Telegram هنوز به حسابی در Haman Planner متصل نیست.\n\n"
            ."۱. در {$url}/register ثبت‌نام کن (یا اگر حساب داری وارد شو).\n"
            ."۲. در بخش «🔐 حساب و تلگرام» دکمه «ساخت کد اتصال Telegram» را بزن.\n"
            ."۳. کد را این‌جا به شکل /start CODE بفرست (یا روی دکمه اتصال یک‌کلیکی بزن).";
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
        return Carbon::now(self::TZ);
    }

    private function btn(string $text, string $data): array
    {
        return ['text' => mb_substr($text, 0, 60), 'callback_data' => $data];
    }

    private function back(string $to = 'home', string $label = '⬅️ بازگشت'): array
    {
        return [$this->btn($label, $to)];
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
            return Carbon::instance($v)->timezone(self::TZ)->format('Y-m-d H:i');
        }
        if (is_bool($v)) {
            return $v ? 'بله' : 'خیر';
        }
        if (is_array($v)) {
            return $this->readable($v);
        }
        return (string) $v;
    }

    private function dt(mixed $v, string $format = 'Y-m-d H:i'): string
    {
        if (!$v) {
            return '—';
        }
        return Carbon::parse($v)->timezone(self::TZ)->format($format);
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
            [$this->btn('◉ امروز', 'today'), $this->btn('✓ کارها', 'tasks')],
            [$this->btn('▣ Inbox', 'inbox'), $this->btn('⌕ جستجو', 'search')],
            [$this->btn('🏗 ساختار', 'structure')],
            [$this->btn('⚙ اجرا', 'execution')],
            [$this->btn('📊 تحلیل', 'analysis')],
            [$this->btn('🧠 دانش و AI', 'knowledge')],
        ];
    }

    // =====================================================================
    // Menus
    // =====================================================================

    private function home(string|int $c, ?int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, "🤖 Haman Planner\n\nیک بخش را انتخاب کن:", $this->mainKeyboard());
    }

    private function taskMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, '✓ کارها', [
            [$this->btn('➕ ایجاد کار', 'new:task')],
            [$this->btn('◉ امروز', 'today'), $this->btn('📆 فردا', 'tomorrow')],
            [$this->btn('▣ Inbox', 'inbox'), $this->btn('⌕ جستجو', 'search')],
            [$this->btn('📋 همه کارهای باز', 'ls:task:0')],
            $this->back(),
        ]);
    }

    private function structureMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, '🏗 ساختار', [
            [$this->btn('◈ حوزه‌ها', 'ls:area:0'), $this->btn('◎ اهداف', 'ls:goal:0')],
            [$this->btn('▤ پروژه‌ها', 'ls:project:0'), $this->btn('◇ Milestones', 'ls:milestone:0')],
            $this->back(),
        ]);
    }

    private function executionMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, '⚙ اجرا', [
            [$this->btn('☀ Daily Plan', 'daily'), $this->btn('□ تقویم', 'calendar')],
            [$this->btn('◷ Execution', 'xlogs'), $this->btn('⇄ Dependencies', 'dependencies')],
            [$this->btn('◌ یادآورها', 'ls:reminder:0'), $this->btn('⚠ Failure Reasons', 'failures')],
            $this->back(),
        ]);
    }

    private function analysisMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, '📊 تحلیل', [
            [$this->btn('↻ Reviews', 'reviews'), $this->btn('◫ Analytics', 'analytics')],
            [$this->btn('▥ Reports', 'report')],
            $this->back(),
        ]);
    }

    private function knowledgeMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, '🧠 دانش و AI', [
            [$this->btn('▤ Notes', 'ls:note:0'), $this->btn('◆ Decisions', 'ls:decision:0')],
            [$this->btn('✦ AI Planner', 'aiplanner'), $this->btn('✧ AI Interactions', 'aiinteractions')],
            [$this->btn('⌛ Pending Actions', 'pending'), $this->btn('◌ Activity Logs', 'activity')],
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
            'area' => ['class' => Area::class, 'label' => 'حوزه', 'plural' => '◈ حوزه‌ها', 'parent' => 'structure', 'title' => 'name'],
            'goal' => ['class' => Goal::class, 'label' => 'هدف', 'plural' => '◎ اهداف', 'parent' => 'structure', 'title' => 'title'],
            'project' => ['class' => Project::class, 'label' => 'پروژه', 'plural' => '▤ پروژه‌ها', 'parent' => 'structure', 'title' => 'title'],
            'milestone' => ['class' => Milestone::class, 'label' => 'Milestone', 'plural' => '◇ Milestones', 'parent' => 'structure', 'title' => 'title'],
            'task' => ['class' => Task::class, 'label' => 'کار', 'plural' => '✓ کارهای باز', 'parent' => 'tasks', 'title' => 'title'],
            'note' => ['class' => Note::class, 'label' => 'Note', 'plural' => '▤ Notes', 'parent' => 'knowledge', 'title' => 'title'],
            'decision' => ['class' => Decision::class, 'label' => 'Decision', 'plural' => '◆ Decisions', 'parent' => 'knowledge', 'title' => 'title'],
            'reminder' => ['class' => Reminder::class, 'label' => 'یادآور', 'plural' => '◌ یادآورها', 'parent' => 'execution', 'title' => 'scheduled_at'],
        ];
    }

    private function entity(string $e): ?array
    {
        return $this->entities()[$e] ?? null;
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
            $msg = (string) (($x->payload['message'] ?? null) ?: ($x->task?->title ?? 'یادآور'));
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
                $f('name', 'نام', 'text', ['req' => true]),
                $f('type', 'نوع', 'enum', ['custom' => true]),
                $f('status', 'وضعیت', 'enum', ['custom' => true]),
                $f('description', 'توضیحات', 'long'),
                $f('sort_order', 'ترتیب', 'int'),
            ],
            'goal' => [
                $f('title', 'عنوان', 'text', ['req' => true]),
                $f('description', 'توضیحات', 'long'),
                $f('area_id', 'حوزه', 'ref', ['ref' => 'area']),
                $f('status', 'وضعیت', 'enum', ['custom' => true]),
                $f('importance', 'اهمیت (0-100)', 'level'),
                $f('weight', 'وزن', 'num'),
                $f('start_date', 'تاریخ شروع', 'date'),
                $f('target_date', 'تاریخ هدف', 'date'),
                $f('success_criteria', 'معیار موفقیت', 'long'),
                $f('progress', 'پیشرفت %', 'pct', ['create' => false]),
            ],
            'project' => [
                $f('title', 'عنوان', 'text', ['req' => true]),
                $f('description', 'توضیحات', 'long'),
                $f('goal_id', 'هدف', 'ref', ['ref' => 'goal']),
                $f('status', 'وضعیت', 'enum', ['custom' => true]),
                $f('importance', 'اهمیت (0-100)', 'level'),
                $f('weight', 'وزن', 'num'),
                $f('estimated_minutes', 'زمان برآوردی (دقیقه)', 'int'),
                $f('start_date', 'تاریخ شروع', 'date'),
                $f('target_date', 'تاریخ هدف', 'date'),
                $f('progress', 'پیشرفت %', 'pct', ['create' => false]),
            ],
            'milestone' => [
                $f('title', 'عنوان', 'text', ['req' => true]),
                $f('project_id', 'پروژه', 'ref', ['ref' => 'project', 'req' => true]),
                $f('status', 'وضعیت', 'enum', ['custom' => true]),
                $f('weight', 'وزن', 'num'),
                $f('target_date', 'تاریخ هدف', 'date'),
                $f('progress', 'پیشرفت %', 'pct', ['create' => false]),
            ],
            'task' => [
                $f('title', 'عنوان', 'text', ['req' => true]),
                $f('description', 'توضیحات', 'long'),
                $f('area_id', 'حوزه', 'ref', ['ref' => 'area']),
                $f('goal_id', 'هدف', 'ref', ['ref' => 'goal']),
                $f('project_id', 'پروژه', 'ref', ['ref' => 'project']),
                $f('milestone_id', 'Milestone', 'ref', ['ref' => 'milestone']),
                $f('status', 'وضعیت', 'enum'),
                $f('priority', 'اولویت', 'enum'),
                $f('importance', 'اهمیت (0-100)', 'level'),
                $f('weight', 'وزن', 'num'),
                $f('estimated_minutes', 'زمان برآوردی (دقیقه)', 'int'),
                $f('deadline', 'مهلت', 'datetime'),
                $f('planned_start', 'شروع برنامه', 'datetime'),
                $f('planned_end', 'پایان برنامه', 'datetime'),
                $f('energy_level', 'انرژی لازم (0-100)', 'level'),
                $f('focus_level', 'تمرکز لازم (0-100)', 'level'),
                $f('progress', 'پیشرفت %', 'pct', ['create' => false]),
                $f('failure_reason', 'علت شکست', 'enum', ['create' => false]),
            ],
            'note' => [
                $f('title', 'عنوان', 'text', ['req' => true]),
                $f('content', 'متن', 'long', ['req' => true]),
                $f('area_id', 'حوزه', 'ref', ['ref' => 'area']),
                $f('goal_id', 'هدف', 'ref', ['ref' => 'goal']),
                $f('project_id', 'پروژه', 'ref', ['ref' => 'project']),
                $f('task_id', 'کار', 'ref', ['ref' => 'task']),
            ],
            'decision' => [
                $f('title', 'عنوان', 'text', ['req' => true]),
                $f('decision', 'تصمیم', 'long', ['req' => true]),
                $f('rationale', 'دلیل', 'long'),
                $f('area_id', 'حوزه', 'ref', ['ref' => 'area']),
                $f('decided_at', 'زمان تصمیم (پیش‌فرض: اکنون)', 'datetime', ['nonnull' => true]),
            ],
            'reminder' => [
                $f('task_id', 'کار', 'ref', ['ref' => 'task']),
                $f('type', 'نوع', 'enum'),
                $f('scheduled_at', 'زمان یادآوری', 'datetime', ['req' => true]),
                $f('message', 'پیام', 'long'),
                $f('status', 'وضعیت', 'enum', ['create' => false, 'nonnull' => true]),
            ],
            'exec' => [
                $f('task_id', 'کار', 'ref', ['ref' => 'task', 'req' => true]),
                $f('started_at', 'زمان شروع (پیش‌فرض: اکنون)', 'datetime'),
                $f('ended_at', 'زمان پایان (یا رد کن و مدت را وارد کن)', 'datetime'),
                $f('duration_minutes', 'مدت (دقیقه)', 'int'),
                $f('focus_level', 'سطح تمرکز (0-100)', 'level'),
                $f('energy_level', 'سطح انرژی (0-100)', 'level'),
                $f('result', 'نتیجه', 'text'),
                $f('blocker', 'مانع', 'long'),
                $f('notes', 'یادداشت', 'long'),
            ],
            'dep' => [
                $f('task_id', 'کار', 'ref', ['ref' => 'task', 'req' => true]),
                $f('depends_on_task_id', 'وابسته به کار', 'ref', ['ref' => 'task', 'req' => true]),
                $f('type', 'نوع وابستگی', 'enum', ['req' => true]),
            ],
            'sched' => [
                $f('task_id', 'کار', 'ref', ['ref' => 'task', 'req' => true]),
                $f('starts_at', 'شروع', 'datetime', ['req' => true]),
                $f('ends_at', 'پایان', 'datetime', ['req' => true]),
            ],
            'dplan' => [
                $f('plan_date', 'تاریخ برنامه (پیش‌فرض: امروز)', 'date'),
                $f('available_minutes', 'دقایق در دسترس', 'int'),
                $f('planned_minutes', 'دقایق برنامه‌ریزی‌شده', 'int'),
                $f('completed_minutes', 'دقایق تکمیل‌شده', 'int'),
                $f('buffer_minutes', 'دقایق بافر', 'int'),
                $f('focus_level', 'تمرکز (0-100)', 'level'),
                $f('energy_level', 'انرژی (0-100)', 'level'),
                $f('notes', 'یادداشت', 'long'),
            ],
            'fail' => [
                $f('task_id', 'کار', 'ref', ['ref' => 'task', 'req' => true]),
                $f('reason', 'علت شکست', 'enum', ['req' => true]),
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
        $pairs = fn (array $values) => array_map(fn ($v) => [(string) $v, (string) $v], array_values(array_unique(array_filter($values, fn ($v) => $v !== null && $v !== ''))));
        $inUse = fn (string $class, string $col) => $this->owned($class)->whereNotNull($col)->distinct()->limit(10)->pluck($col)->all();

        $taskStatus = [
            'inbox' => '📥 Inbox', 'planned' => '🗓 planned', 'ready' => '✅ ready', 'in_progress' => '▶️ in_progress',
            'blocked' => '⛔ blocked', 'waiting' => '⏳ waiting', 'completed' => '✔️ completed', 'cancelled' => '🚫 cancelled', 'deferred' => '⏸ deferred',
        ];

        return match ("$form.$key") {
            'task.status' => array_map(fn (TaskStatus $s) => [$s->value, $taskStatus[$s->value] ?? $s->value], TaskStatus::cases()),
            'task.priority' => [['p0', 'P0 — فوری'], ['p1', 'P1 — بالا'], ['p2', 'P2 — متوسط'], ['p3', 'P3 — پایین']],
            'task.failure_reason', 'fail.reason' => $this->failureOptions(),
            'area.type' => $pairs(array_merge(['custom'], $inUse(Area::class, 'type'))),
            'area.status' => $pairs(array_merge(['active'], $inUse(Area::class, 'status'))),
            'goal.status' => $pairs(array_merge(['active', 'in_progress', 'completed', 'cancelled'], $inUse(Goal::class, 'status'))),
            'project.status' => $pairs(array_merge(['active', 'in_progress', 'completed', 'cancelled'], $inUse(Project::class, 'status'))),
            'milestone.status' => $pairs(array_merge(['pending', 'completed'], $inUse(Milestone::class, 'status'))),
            'reminder.type' => $pairs(array_merge(['telegram'], $inUse(Reminder::class, 'type'))),
            'reminder.status' => $pairs(['pending', 'sent', 'failed', 'cancelled']),
            'dep.type' => [['requires', 'requires — نیاز دارد به'], ['blocks', 'blocks — مسدود می‌کند'], ['related', 'related — مرتبط']],
            default => [],
        };
    }

    private function failureOptions(): array
    {
        $rows = FailureReason::query()->orderBy('severity')->orderBy('code')->get(['code', 'name']);
        if ($rows->isNotEmpty()) {
            return $rows->map(fn ($r) => [(string) $r->code, $r->code.' — '.$r->name])->all();
        }
        // Same codes as the web planner's failure form when the lookup table is not seeded.
        return array_map(fn ($c) => [$c, $c], ['no_time', 'poor_estimation', 'unexpected_work', 'low_energy', 'distraction', 'too_difficult', 'unclear_task', 'waiting', 'blocked', 'wrong_priority', 'context_switching', 'personal_issue', 'technical_problem', 'other']);
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
                'task' => ' — '.strtoupper((string) $x->priority).' · '.$x->status,
                'goal', 'project', 'milestone' => ' — '.(float) $x->progress.'%',
                'reminder' => ' — '.$x->status,
                default => '',
            };
            return '• '.$this->titleOf($e, $x).$suffix;
        })->implode("\n");

        $text = $def['plural']." ({$total})\n\n".($items->isEmpty() ? 'موردی وجود ندارد.' : $lines);
        $kb = [[$this->btn('➕ ایجاد '.$def['label'], 'new:'.$e)]];
        foreach ($items as $x) {
            $kb[] = [$this->btn('👁 '.$this->titleOf($e, $x), "v:$e:{$x->id}")];
        }
        $nav = [];
        if ($page > 0) {
            $nav[] = $this->btn('◀️ قبلی', "ls:$e:".($page - 1));
        }
        if (($page + 1) * self::PAGE < $total) {
            $nav[] = $this->btn('بعدی ▶️', "ls:$e:".($page + 1));
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
            $this->show($c, $m, 'مورد پیدا نشد یا به شما تعلق ندارد.', [$this->back($def['parent'] ?? 'home')]);
            return;
        }
        $text = ($notice ? $notice."\n\n" : '').'📌 '.$def['label'].' #'.$x->id."\n\n".$this->describe($e, $x);
        $kb = [];
        if ($e === 'task') {
            if (!in_array($x->status, ['completed', 'cancelled'], true)) {
                $kb[] = [$this->btn('✅ انجام شد', 'done:'.$id), $this->btn('⏸ تعویق', 'defer:'.$id), $this->btn('🚫 لغو', 'tcancel:'.$id)];
            }
            $kb[] = [$this->btn('◷ ثبت اجرا', 'texec:'.$id), $this->btn('□ زمان‌بندی', 'tsched:'.$id)];
            $kb[] = [$this->btn('⇄ وابستگی‌ها', 'deps:'.$id), $this->btn('⏰ یادآور', 'trem:'.$id), $this->btn('⚠ شکست', 'fail:'.$id)];
        }
        if ($e === 'reminder' && $x->status === 'pending') {
            $kb[] = [$this->btn('🚫 لغو یادآور', 'rcancel:'.$id)];
        }
        $kb[] = [$this->btn('✏️ ویرایش', "ed:$e:$id"), $this->btn('🗑 حذف', "del:$e:$id")];
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
            } elseif ($f['t'] === 'date') {
                $v = $this->dt($v, 'Y-m-d');
            } elseif ($f['t'] === 'datetime') {
                $v = $this->dt($v);
            }
            $out[] = $f['l'].': '.$this->fmt($v);
        }
        if ($e === 'task') {
            $out[] = 'زمان واقعی: '.(int) $x->actual_minutes.' دقیقه';
            if ($x->completed_at) {
                $out[] = 'تکمیل: '.$this->dt($x->completed_at);
            }
        }
        if (in_array($e, ['goal', 'project'], true) && $x->health) {
            $out[] = 'سلامت: '.$x->health;
        }
        if ($e === 'reminder' && (int) $x->attempts > 0) {
            $out[] = 'تلاش‌ها: '.$x->attempts.'/'.$x->max_attempts;
        }
        return $out ? implode("\n", $out) : 'اطلاعاتی ثبت نشده.';
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
            $this->show($c, $m, 'مورد پیدا نشد یا به شما تعلق ندارد.', [$this->back()]);
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
        $this->show($c, $m, '✏️ ویرایش '.$this->entity($e)['label'].' «'.$this->titleOf($e, $x)."»\n\nیک فیلد را انتخاب کن:", $kb);
    }

    private function beginEditField(string|int $c, int $m, string $e, int $id, string $field): void
    {
        $x = $this->findOwned($e, $id);
        $def = $this->fieldDef($e, $field);
        if (!$x || !$def || ($def['edit'] ?? true) === false) {
            $this->show($c, $m, 'فیلد یا مورد نامعتبر است.', [$this->back($x ? "v:$e:$id" : 'home')]);
            return;
        }
        $this->startWizard($c, $m, 'edit', $e, $id, [], [$field]);
    }

    private function deleteConfirm(string|int $c, int $m, string $e, int $id): void
    {
        $x = $this->findOwned($e, $id);
        if (!$x) {
            $this->show($c, $m, 'مورد پیدا نشد یا به شما تعلق ندارد.', [$this->back()]);
            return;
        }
        $warn = match ($e) {
            'project' => "\n\n⚠️ Milestoneهای این پروژه هم حذف می‌شوند.",
            'milestone', 'area', 'goal' => "\n\nموارد وابسته حذف نمی‌شوند؛ فقط ارتباطشان خالی می‌شود.",
            'task' => "\n\n⚠️ لاگ‌های اجرا و وابستگی‌های این کار هم حذف می‌شوند.",
            default => '',
        };
        $this->show($c, $m, '⚠️ حذف '.$this->entity($e)['label'].' «'.$this->titleOf($e, $x).'»؟'.$warn, [
            [$this->btn('🗑 بله، حذف شود', "delok:$e:$id")],
            [$this->btn('❌ خیر', "v:$e:$id")],
        ]);
    }

    private function deleteEntity(string|int $c, int $m, string $e, int $id): void
    {
        $x = $this->findOwned($e, $id);
        if (!$x) {
            $this->show($c, $m, 'مورد پیدا نشد یا به شما تعلق ندارد.', [$this->back()]);
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
            $this->show($c, $m, 'کار پیدا نشد یا به شما تعلق ندارد.', [$this->back('tasks')]);
            return;
        }
        $this->planner->complete($t);
        $this->progress->recalculateFromTask($t);
        $this->entityView($c, $m, 'task', $id, '✅ کار انجام شد.');
    }

    private function deferTask(string|int $c, int $m, int $id): void
    {
        $t = $this->ownedTask($id);
        if (!$t) {
            $this->show($c, $m, 'کار پیدا نشد یا به شما تعلق ندارد.', [$this->back('tasks')]);
            return;
        }
        $this->planner->defer($t);
        $this->entityView($c, $m, 'task', $id, '⏸ کار به تعویق افتاد.');
    }

    private function cancelTask(string|int $c, int $m, int $id): void
    {
        $t = $this->ownedTask($id);
        if (!$t) {
            $this->show($c, $m, 'کار پیدا نشد یا به شما تعلق ندارد.', [$this->back('tasks')]);
            return;
        }
        $t->update(['status' => 'cancelled', 'completed_at' => null]);
        $this->planner->recalculatePriority($t);
        $this->progress->recalculateFromTask($t);
        $this->entityView($c, $m, 'task', $id, '🚫 کار لغو شد.');
    }

    private function cancelReminder(string|int $c, int $m, int $id): void
    {
        $r = $this->findOwned('reminder', $id);
        if (!$r) {
            $this->show($c, $m, 'یادآور پیدا نشد یا به شما تعلق ندارد.', [$this->back('execution')]);
            return;
        }
        $r->update(['status' => 'cancelled']);
        $this->entityView($c, $m, 'reminder', $id, '🚫 یادآور لغو شد.');
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
        return '• '.($when !== '' ? $when.' ' : '').$t->title.' — '.strtoupper((string) $t->priority).' · '.(float) $t->progress.'%';
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
            '◉ امروز — '.$day->format('Y-m-d'),
            '',
            '✓ کارهای امروز ('.$tasks->count().'):',
            $tasks->isEmpty() ? 'کاری برای امروز برنامه‌ریزی نشده.' : $tasks->map(fn (Task $t) => $this->taskLine($t))->implode("\n"),
            $overdue->isNotEmpty() ? "\n⚠️ عقب‌افتاده (".$overdue->count()."):\n".$overdue->map(fn (Task $t) => '• '.$t->title.' — مهلت '.$this->dt($t->deadline, 'm/d'))->implode("\n") : null,
            $blocks->isNotEmpty() ? "\n□ بلوک‌های زمانی:\n".$blocks->map(fn ($b) => '• '.$this->dt($b->starts_at, 'H:i').'–'.$this->dt($b->ends_at, 'H:i').' '.($b->task?->title ?? 'Focus'))->implode("\n") : null,
            "\n✔️ انجام‌شده امروز: ".$done,
        ]);
        $kb = $this->taskButtons($tasks->concat($overdue)->take(15));
        $kb[] = [$this->btn('➕ ایجاد کار', 'new:task'), $this->btn('📆 فردا', 'tomorrow')];
        $kb[] = $this->back();
        $this->show($c, $m, $text, $kb);
    }

    private function tomorrow(string|int $c, int $m): void
    {
        $this->clearState($c);
        $day = $this->now()->addDay();
        $tasks = $this->dayTasks($day);
        $text = '📆 فردا — '.$day->format('Y-m-d')."\n\n".($tasks->isEmpty() ? 'کاری برای فردا برنامه‌ریزی نشده.' : $tasks->map(fn (Task $t) => $this->taskLine($t))->implode("\n"));
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
        $text = "▣ Inbox ({$total})\n\n".($items->isEmpty() ? 'Inbox خالی است.' : $items->map(fn (Task $t) => '• '.$t->title)->implode("\n"));
        $kb = $this->taskButtons($items);
        $nav = [];
        if ($page > 0) $nav[] = $this->btn('◀️ قبلی', 'inbox:'.($page - 1));
        if (($page + 1) * self::PAGE < $total) $nav[] = $this->btn('بعدی ▶️', 'inbox:'.($page + 1));
        $kb[] = $nav;
        $kb[] = [$this->btn('➕ ایجاد کار', 'new:task')];
        $kb[] = $this->back('tasks');
        $this->show($c, $m, $text, $kb);
    }

    // ---------------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------------

    private function beginSearch(string|int $c, int $m): void
    {
        $this->putState($c, ['mode' => 'search', 'mid' => $m]);
        $this->show($c, $m, "⌕ جستجو\n\nعبارت جستجو را بنویس (حداقل ۲ حرف):", [$this->back('home', '❌ لغو')]);
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
            $this->show($c, null, 'عبارت جستجو باید حداقل ۲ حرف باشد. دوباره بنویس:', [$this->back('home', '❌ لغو')]);
            return;
        }
        $this->clearState($c);
        $groups = $this->searchFor(mb_substr($q, 0, 100));
        $text = '⌕ نتایج «'.$q."»\n";
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
            $text .= "\nنتیجه‌ای پیدا نشد.";
        }
        $kb[] = [$this->btn('⌕ جستجوی دوباره', 'search'), $this->btn('🏠 خانه', 'home')];
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
                $this->show($c, $m, 'مورد انتخاب‌شده پیدا نشد یا به شما تعلق ندارد.', [$this->back()]);
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
            'create' => '➕ ایجاد '.$this->entity($s['entity'])['label'],
            'edit' => '✏️ ویرایش '.$this->entity($s['entity'])['label'],
            'exec' => '◷ ثبت Execution',
            'dep' => '⇄ ایجاد Dependency',
            'sched' => '□ ایجاد بلوک زمانی',
            'dplan' => '☀ ثبت Daily Plan',
            'fail' => '⚠ ثبت علت شکست',
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
            $head .= 'مقدار فعلی: '.$this->fmt($cur)."\n\n";
        }

        switch ($f['t']) {
            case 'ref':
                $items = $this->refChoices($s, $f);
                $text = $head.'انتخاب '.$f['l'].($required ? ' (الزامی)' : '').':'.($s['filter'] ? "\nفیلتر: «{$s['filter']}»" : '')."\n(برای فیلتر، بخشی از عنوان را بنویس)";
                if ($items->isEmpty()) {
                    $text .= "\n\nموردی پیدا نشد.".($f['ref'] === 'project' && $required ? ' ابتدا یک پروژه بساز.' : '');
                }
                foreach ($items as $x) {
                    $kb[] = [$this->btn('• '.$this->titleOf($f['ref'], $x), 'wz:p:'.$x->id)];
                }
                break;
            case 'enum':
                $opts = $this->options($form, $key);
                $s['opts'] = array_column($opts, 0);
                $this->putState($c, $s);
                $text = $head.'انتخاب '.$f['l'].':'.(($f['custom'] ?? false) ? "\n(یا مقدار دلخواه را بنویس)" : '');
                $row = [];
                foreach ($opts as $i => [$value, $label]) {
                    $row[] = $this->btn($label, 'wz:o:'.$i);
                    if (count($row) === 2) { $kb[] = $row; $row = []; }
                }
                $kb[] = $row;
                break;
            case 'date':
                $text = $head.$f['l'].' را وارد کن:'."\nقالب: 2026-09-25 یا 1405-07-03";
                $kb[] = [$this->btn('امروز', 'wz:q:today'), $this->btn('فردا', 'wz:q:tomorrow'), $this->btn('+۷ روز', 'wz:q:week')];
                break;
            case 'datetime':
                $text = $head.$f['l'].' را وارد کن:'."\nقالب: 2026-09-25 14:30 یا 1405-07-03 14:30 یا فقط 14:30 یا «فردا 10:00»";
                $kb[] = [$this->btn('اکنون', 'wz:q:now'), $this->btn('+۱ ساعت', 'wz:q:hour'), $this->btn('فردا ۹:۰۰', 'wz:q:tom9')];
                break;
            case 'level':
            case 'pct':
                $text = $head.$f['l'].' را وارد کن (عدد 0 تا 100):';
                $kb[] = array_map(fn ($v) => $this->btn((string) $v, 'wz:q:'.$v), $f['t'] === 'pct' ? [0, 25, 50, 75, 100] : [25, 50, 75, 100]);
                break;
            case 'int':
            case 'num':
                $text = $head.$f['l'].' را وارد کن (عدد):';
                break;
            default:
                $text = $head.$f['l'].' را بنویس'.($required ? ' (الزامی)' : '').':';
        }

        $nav = [];
        if (!$required) {
            $nav[] = $isEdit ? $this->btn('🧹 خالی کردن', 'wz:skip') : ($f['t'] === 'ref' ? $this->btn('∅ بدون مقدار', 'wz:null') : $this->btn('⏭ رد کردن', 'wz:skip'));
        }
        if (!$isEdit && $s['step'] > 0) {
            $nav[] = $this->btn('⬅️ قبلی', 'wz:back');
        }
        $kb[] = $nav;
        if (!$isEdit && $this->requiredDone($s) && $s['step'] < count($s['steps'])) {
            $kb[] = [$this->btn('✅ پایان و بازبینی', 'wz:fin')];
        }
        $kb[] = [$this->btn('❌ لغو', 'wz:x')];

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
            $this->show($c, $m, '⌛ این مرحله منقضی شده است. دوباره شروع کن.', $this->mainKeyboard());
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
                    $this->askStep($c, $m, $s, 'فیلدهای الزامی هنوز کامل نشده‌اند.');
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
                    $this->askStep($c, $m, $s, 'این فیلد الزامی است.');
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
                    $this->askStep($c, $m, $s, 'مورد انتخاب‌شده پیدا نشد یا به شما تعلق ندارد.');
                    return;
                }
                if ($f['k'] === 'depends_on_task_id' && (int) $picked->id === (int) ($s['data']['task_id'] ?? 0)) {
                    $this->askStep($c, $m, $s, 'یک کار نمی‌تواند به خودش وابسته باشد.');
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
                    $this->askStep($c, $m, $s, 'گزینه نامعتبر است.');
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
            $this->show($c, null, 'برای ثبت، دکمه «✅ ثبت» را بزن یا لغو کن.', [[$this->btn('✅ ثبت', 'wz:ok')], [$this->btn('❌ لغو', 'wz:x')]]);
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
                $this->askStep($c, null, $s, 'از دکمه‌ها یکی را انتخاب کن.');
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
            return [false, null, 'مقدار خالی است.'];
        }
        switch ($f['t']) {
            case 'int':
                return ctype_digit($v) && strlen($v) < 9 ? [true, (int) $v, null] : [false, null, 'یک عدد صحیح مثبت وارد کن.'];
            case 'num':
                return is_numeric($v) && (float) $v >= 0 && (float) $v < 1000000 ? [true, (float) $v, null] : [false, null, 'یک عدد معتبر (مثبت) وارد کن.'];
            case 'level':
            case 'pct':
                return is_numeric($v) && (float) $v >= 0 && (float) $v <= 100 ? [true, $f['t'] === 'pct' ? (float) $v : (int) round((float) $v), null] : [false, null, 'عددی بین 0 تا 100 وارد کن.'];
            case 'date':
                $d = $this->parseDateTime($v, false);
                return $d ? [true, $d->format('Y-m-d'), null] : [false, null, 'تاریخ نامعتبر است. مثال: 2026-09-25 یا 1405-07-03'];
            case 'datetime':
                $d = $this->parseDateTime($v, true);
                return $d ? [true, $d->format('Y-m-d H:i'), null] : [false, null, 'زمان نامعتبر است. مثال: 2026-09-25 14:30 یا 14:30'];
            case 'text':
                return [true, mb_substr($v, 0, 255), null];
            default:
                return [true, mb_substr($v, 0, 10000), null];
        }
    }

    private function normalizeDigits(string $v): string
    {
        return strtr($v, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
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
            $date = Carbon::create($y, $mo, $d, 0, 0, 0, self::TZ);
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
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0);
        $months = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12 && $gd > $months[$gm]; $gm++) {
            $gd -= $months[$gm];
        }
        return [$gy, $gm, $gd];
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
        $text = $this->wizardTitle($s)."\n\n✅ خلاصه برای تأیید:\n\n".($lines ? implode("\n", $lines) : '—');
        $mid = $this->show($c, $m, $text, [
            [$this->btn('✅ ثبت', 'wz:ok')],
            [$this->btn('⬅️ قبلی', 'wz:back'), $this->btn('❌ لغو', 'wz:x')],
        ]);
        if ($mid) {
            $s['mid'] = $mid;
            $this->putState($c, $s);
        }
    }

    /** Converts a wizard datetime string (Asia/Tehran) into an app-timezone Carbon for storage. */
    private function toStorage(?string $v): ?Carbon
    {
        return $v === null ? null : Carbon::parse($v, self::TZ)->timezone(config('app.timezone'));
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
                $this->show($c, $m, '⚠️ یکی از موارد انتخاب‌شده دیگر در دسترس نیست. عملیات لغو شد.', [$this->back($this->cancelTarget($s))]);
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
            $this->jumpTo($c, $m, $s, 'planned_end', 'پایان برنامه نمی‌تواند قبل از شروع باشد.');
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
        $this->entityView($c, $m, $e, (int) $x->id, '✅ '.$this->entity($e)['label'].' ثبت شد.');
    }

    private function commitEdit(string|int $c, ?int $m, array $s): void
    {
        $e = $s['entity'];
        $x = $this->findOwned($e, (int) $s['id']);
        if (!$x) {
            $this->clearState($c);
            $this->show($c, $m, 'مورد پیدا نشد یا به شما تعلق ندارد.', [$this->back()]);
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
                $this->askStep($c, $m, $s, 'پایان برنامه نمی‌تواند قبل از شروع باشد.');
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
        $this->entityView($c, $m, $e, (int) $x->id, '✅ ذخیره شد.');
    }

    private function commitExecution(string|int $c, ?int $m, array $s): void
    {
        $d = $s['data'];
        $task = $this->ownedTask((int) ($d['task_id'] ?? 0));
        if (!$task) {
            $this->clearState($c);
            $this->show($c, $m, 'کار پیدا نشد یا به شما تعلق ندارد.', [$this->back('xlogs')]);
            return;
        }
        $start = $this->toStorage($d['started_at'] ?? null) ?? now();
        $end = $this->toStorage($d['ended_at'] ?? null);
        if ($end && $end->lt($start)) {
            $this->jumpTo($c, $m, $s, 'ended_at', 'زمان پایان نمی‌تواند قبل از شروع باشد.');
            return;
        }
        $payload = ['started_at' => $start, 'ended_at' => $end];
        foreach (['duration_minutes', 'focus_level', 'energy_level', 'result', 'blocker', 'notes'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null) $payload[$k] = $d[$k];
        }
        $log = $this->planner->logTime($task, $payload);
        $this->clearState($c);
        $this->executionView($c, $m, (int) $log->id, '✅ اجرا ثبت شد ('.$log->duration_minutes.' دقیقه).');
    }

    private function commitDependency(string|int $c, ?int $m, array $s): void
    {
        $d = $s['data'];
        $task = $this->ownedTask((int) ($d['task_id'] ?? 0));
        $dep = $this->ownedTask((int) ($d['depends_on_task_id'] ?? 0));
        $types = array_column($this->options('dep', 'type'), 0);
        if (!$task || !$dep || !in_array($d['type'] ?? '', $types, true)) {
            $this->clearState($c);
            $this->show($c, $m, 'اطلاعات وابستگی نامعتبر است.', [$this->back('dependencies')]);
            return;
        }
        try {
            $this->dependencies->add($task, $dep, (string) $d['type']);
        } catch (\RuntimeException $e) {
            $this->clearState($c);
            $msg = str_contains($e->getMessage(), 'cycle') ? 'این وابستگی یک حلقه ایجاد می‌کند.' : 'یک کار نمی‌تواند به خودش وابسته باشد.';
            $this->show($c, $m, '⚠️ '.$msg, [$this->back('dependencies')]);
            return;
        }
        $this->clearState($c);
        $this->taskDependencies($c, $m, (int) $task->id, '✅ وابستگی ثبت شد.');
    }

    private function commitSchedule(string|int $c, ?int $m, array $s): void
    {
        $d = $s['data'];
        $task = $this->ownedTask((int) ($d['task_id'] ?? 0));
        if (!$task) {
            $this->clearState($c);
            $this->show($c, $m, 'کار پیدا نشد یا به شما تعلق ندارد.', [$this->back('calendar')]);
            return;
        }
        $start = $this->toStorage($d['starts_at'] ?? null);
        $end = $this->toStorage($d['ends_at'] ?? null);
        if (!$start || !$end || !$end->gt($start)) {
            $this->jumpTo($c, $m, $s, 'ends_at', 'پایان باید بعد از شروع باشد.');
            return;
        }
        $plan = $this->owned(DailyPlan::class)->whereDate('plan_date', Carbon::parse($d['starts_at'], self::TZ)->toDateString())->first();
        $block = ScheduleBlock::create([
            'user_id' => $this->uid(),
            'daily_plan_id' => $plan?->id,
            'task_id' => $task->id,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
        $this->clearState($c);
        $this->scheduleView($c, $m, (int) $block->id, '✅ بلوک زمانی ثبت شد.');
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
        $this->daily($c, $m, $date, '✅ Daily Plan ذخیره شد.');
    }

    private function commitFailure(string|int $c, ?int $m, array $s): void
    {
        $task = $this->ownedTask((int) ($s['data']['task_id'] ?? 0));
        $reason = (string) ($s['data']['reason'] ?? '');
        if (!$task || !in_array($reason, array_column($this->failureOptions(), 0), true)) {
            $this->clearState($c);
            $this->show($c, $m, 'اطلاعات نامعتبر است.', [$this->back('failures')]);
            return;
        }
        $this->planner->logFailure($task, ['reason' => $reason]);
        $this->clearState($c);
        $this->entityView($c, $m, 'task', (int) $task->id, '⚠ علت شکست ثبت شد.');
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
        $lines = [($notice ? $notice."\n\n" : '').'☀ Daily Plan — '.$date, ''];
        if ($plan) {
            $lines[] = 'در دسترس: '.$plan->available_minutes.' دقیقه';
            $lines[] = 'برنامه‌ریزی‌شده: '.$plan->planned_minutes.' دقیقه';
            $lines[] = 'تکمیل‌شده: '.$plan->completed_minutes.' دقیقه';
            $lines[] = 'بافر: '.$plan->buffer_minutes.' دقیقه';
            $lines[] = 'تمرکز: '.$this->fmt($plan->focus_level).' | انرژی: '.$this->fmt($plan->energy_level);
            if ($plan->notes) $lines[] = 'یادداشت: '.$plan->notes;
            $blocks = $this->owned(ScheduleBlock::class)->with('task')->where('daily_plan_id', $plan->id)->orderBy('starts_at')->get();
            if ($blocks->isNotEmpty()) {
                $lines[] = "\nبلوک‌ها:";
                foreach ($blocks as $b) $lines[] = '• '.$this->dt($b->starts_at, 'H:i').'–'.$this->dt($b->ends_at, 'H:i').' '.($b->task?->title ?? 'Focus');
            }
        } else {
            $lines[] = 'برای این روز برنامه‌ای ثبت نشده.';
        }
        if ($recent->isNotEmpty()) {
            $lines[] = "\nبرنامه‌های اخیر:";
            foreach ($recent as $r) $lines[] = '• '.$r->plan_date->format('Y-m-d').' — '.$r->planned_minutes.'/'.$r->available_minutes.' دقیقه';
        }
        $this->show($c, $m, implode("\n", $lines), [
            [$this->btn($plan ? '✏️ ویرایش برنامه امروز' : '➕ ثبت برنامه امروز', 'dplan:today')],
            [$this->btn('➕ برنامه برای تاریخ دیگر', 'dplan')],
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
        $lines = ['□ تقویم — امروز و ۷ روز آینده', ''];
        $kb = [];
        for ($i = 0; $i < 8; $i++) {
            $day = $from->copy()->addDays($i);
            $items = $blocks->filter(fn ($b) => Carbon::parse($b->starts_at)->timezone(self::TZ)->isSameDay($day));
            $lines[] = ($i === 0 ? '◉ امروز ' : '').$day->format('D Y-m-d').':';
            if ($items->isEmpty()) {
                $lines[] = '   —';
            }
            foreach ($items as $b) {
                $lines[] = '   '.$this->dt($b->starts_at, 'H:i').'–'.$this->dt($b->ends_at, 'H:i').' '.($b->task?->title ?? 'Focus');
                if (count($kb) < 12) $kb[] = [$this->btn($this->dt($b->starts_at, 'm/d H:i').' '.($b->task?->title ?? 'Focus'), 'sb:'.$b->id)];
            }
        }
        $kb[] = [$this->btn('➕ بلوک زمانی', 'newsched')];
        $kb[] = $this->back('execution');
        $this->show($c, $m, implode("\n", $lines), $kb);
    }

    private function scheduleView(string|int $c, ?int $m, int $id, ?string $notice = null): void
    {
        $b = $id > 0 ? $this->owned(ScheduleBlock::class)->with('task')->find($id) : null;
        if (!$b) {
            $this->show($c, $m, 'بلوک پیدا نشد یا به شما تعلق ندارد.', [$this->back('calendar')]);
            return;
        }
        $text = $this->lines([
            $notice ? $notice."\n" : null,
            '□ بلوک زمانی #'.$b->id,
            'کار: '.($b->task?->title ?? '—'),
            'شروع: '.$this->dt($b->starts_at),
            'پایان: '.$this->dt($b->ends_at),
            'وضعیت: '.$b->status,
            'منبع: '.$b->source,
        ]);
        $kb = [];
        if ($b->task_id) $kb[] = [$this->btn('📋 مشاهده کار', 'v:task:'.$b->task_id)];
        $kb[] = [$this->btn('🗑 حذف بلوک', 'sbdel:'.$b->id)];
        $kb[] = $this->back('calendar');
        $this->show($c, $m, $text, $kb);
    }

    private function scheduleDelete(string|int $c, int $m, int $id, bool $confirmed): void
    {
        $b = $id > 0 ? $this->owned(ScheduleBlock::class)->find($id) : null;
        if (!$b) {
            $this->show($c, $m, 'بلوک پیدا نشد یا به شما تعلق ندارد.', [$this->back('calendar')]);
            return;
        }
        if (!$confirmed) {
            $this->show($c, $m, '⚠️ این بلوک زمانی حذف شود؟', [[$this->btn('🗑 بله', 'sbdelok:'.$id)], [$this->btn('❌ خیر', 'sb:'.$id)]]);
            return;
        }
        $b->delete();
        $this->calendar($c, $m);
    }

    private function executionLogs(string|int $c, int $m): void
    {
        $this->clearState($c);
        $logs = $this->owned(ExecutionLog::class)->with('task')->latest('started_at')->limit(15)->get();
        $text = "◷ Execution\n\n".($logs->isEmpty() ? 'اجرایی ثبت نشده.' : $logs->map(fn ($x) => '• '.$this->dt($x->started_at, 'm/d H:i').' — '.($x->task?->title ?? 'Task').' — '.$x->duration_minutes.' دقیقه')->implode("\n"));
        $kb = [[$this->btn('➕ ثبت اجرا', 'newexec')]];
        foreach ($logs as $x) {
            $kb[] = [$this->btn($this->dt($x->started_at, 'm/d H:i').' '.($x->task?->title ?? 'Task'), 'xl:'.$x->id)];
        }
        $kb[] = $this->back('execution');
        $this->show($c, $m, $text, $kb);
    }

    private function executionView(string|int $c, ?int $m, int $id, ?string $notice = null): void
    {
        $x = $id > 0 ? $this->owned(ExecutionLog::class)->with('task')->find($id) : null;
        if (!$x) {
            $this->show($c, $m, 'لاگ اجرا پیدا نشد یا به شما تعلق ندارد.', [$this->back('xlogs')]);
            return;
        }
        $text = $this->lines([
            $notice ? $notice."\n" : null,
            '◷ Execution #'.$x->id,
            'کار: '.($x->task?->title ?? '—'),
            'شروع: '.$this->dt($x->started_at),
            'پایان: '.$this->dt($x->ended_at),
            'مدت: '.$x->duration_minutes.' دقیقه',
            'تمرکز: '.$this->fmt($x->focus_level).' | انرژی: '.$this->fmt($x->energy_level),
            $x->result ? 'نتیجه: '.$x->result : null,
            $x->blocker ? 'مانع: '.$x->blocker : null,
            $x->notes ? 'یادداشت: '.$x->notes : null,
        ]);
        $this->show($c, $m, $text, [
            [$this->btn('📋 مشاهده کار', 'v:task:'.$x->task_id), $this->btn('🗑 حذف', 'xldel:'.$x->id)],
            $this->back('xlogs'),
        ]);
    }

    private function executionDelete(string|int $c, int $m, int $id, bool $confirmed): void
    {
        $x = $id > 0 ? $this->owned(ExecutionLog::class)->find($id) : null;
        if (!$x) {
            $this->show($c, $m, 'لاگ اجرا پیدا نشد یا به شما تعلق ندارد.', [$this->back('xlogs')]);
            return;
        }
        if (!$confirmed) {
            $this->show($c, $m, '⚠️ این لاگ اجرا حذف شود؟', [[$this->btn('🗑 بله', 'xldelok:'.$id)], [$this->btn('❌ خیر', 'xl:'.$id)]]);
            return;
        }
        $x->delete();
        $this->executionLogs($c, $m);
    }

    private function depLabel(string $type): string
    {
        return ['requires' => 'نیاز دارد به', 'blocks' => 'مسدود می‌کند', 'related' => 'مرتبط با'][$type] ?? $type;
    }

    private function dependencyList(string|int $c, int $m): void
    {
        $this->clearState($c);
        $deps = $this->owned(TaskDependency::class)->with(['task', 'dependsOn'])->latest('id')->limit(15)->get();
        $text = "⇄ Dependencies\n\n".($deps->isEmpty() ? 'وابستگی‌ای ثبت نشده.' : $deps->map(fn ($d) => '• '.($d->task?->title ?? '—').' ← '.$this->depLabel($d->type).' → '.($d->dependsOn?->title ?? '—'))->implode("\n"));
        $kb = [[$this->btn('➕ ایجاد وابستگی', 'newdep')]];
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
            $this->show($c, $m, 'کار پیدا نشد یا به شما تعلق ندارد.', [$this->back('tasks')]);
            return;
        }
        $out = $this->owned(TaskDependency::class)->with('dependsOn')->where('task_id', $t->id)->get();
        $in = $this->owned(TaskDependency::class)->with('task')->where('depends_on_task_id', $t->id)->get();
        $text = $this->lines([
            $notice ? $notice."\n" : null,
            '⇄ وابستگی‌های «'.$t->title.'»',
            '',
            'این کار:',
            $out->isEmpty() ? '   —' : $out->map(fn ($d) => '   '.$this->depLabel($d->type).' «'.($d->dependsOn?->title ?? '—').'» ['.($d->dependsOn?->status ?? '—').']')->implode("\n"),
            '',
            'کارهایی که به این کار وابسته‌اند:',
            $in->isEmpty() ? '   —' : $in->map(fn ($d) => '   «'.($d->task?->title ?? '—').'» '.$this->depLabel($d->type).' این کار')->implode("\n"),
            '',
            'قابل شروع: '.($this->dependencies->canStart($t) ? 'بله' : 'خیر'),
        ]);
        $kb = [[$this->btn('➕ افزودن وابستگی', 'tdep:'.$t->id)]];
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
            $this->show($c, $m, 'وابستگی پیدا نشد یا به شما تعلق ندارد.', [$this->back('dependencies')]);
            return;
        }
        if (!$confirmed) {
            $this->show($c, $m, '⚠️ حذف وابستگی «'.($d->task?->title ?? '—').'» '.$this->depLabel($d->type).' «'.($d->dependsOn?->title ?? '—').'»؟', [
                [$this->btn('🗑 بله', 'depdelok:'.$id)], [$this->btn('❌ خیر', 'dependencies')],
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
            '⚠ Failure Reasons (مرجع سیستمی — فقط خواندنی)',
            '',
            $reasons->isEmpty() ? 'فهرست مرجع خالی است.' : $reasons->map(fn ($r) => '• '.$r->code.' — '.$r->name.' | severity '.$r->severity.' | preventable: '.$r->preventable)->implode("\n"),
            '',
            'آخرین شکست‌های کارهای شما:',
            $recent->isEmpty() ? '   —' : $recent->map(fn ($t) => '   '.$t->title.' — '.$t->failure_reason)->implode("\n"),
        ]);
        $this->show($c, $m, $text, [[$this->btn('⚠ ثبت شکست برای یک کار', 'fail')], $this->back('execution')]);
    }

    // =====================================================================
    // Analysis
    // =====================================================================

    private function reviewList(string|int $c, int $m): void
    {
        $this->clearState($c);
        $items = $this->owned(Review::class)->latest('period_end')->latest('id')->limit(10)->get();
        $text = "↻ Reviews\n\n".($items->isEmpty() ? 'Review ثبت نشده.' : $items->map(fn ($r) => '• '.$r->type.' — '.$r->period_start?->format('Y-m-d').' تا '.$r->period_end?->format('Y-m-d'))->implode("\n"));
        $kb = [[$this->btn('➕ Review روزانه', 'revgen:daily'), $this->btn('➕ Review هفتگی', 'revgen:weekly')]];
        foreach ($items as $r) {
            $kb[] = [$this->btn($r->type.' '.$r->period_end?->format('Y-m-d'), 'rv:'.$r->id)];
        }
        $kb[] = $this->back('analysis');
        $this->show($c, $m, $text, $kb);
    }

    private function reviewView(string|int $c, ?int $m, int $id, ?string $notice = null): void
    {
        $r = $id > 0 ? $this->owned(Review::class)->find($id) : null;
        if (!$r) {
            $this->show($c, $m, 'Review پیدا نشد یا به شما تعلق ندارد.', [$this->back('reviews')]);
            return;
        }
        $metrics = is_array($r->metrics_json) ? $r->metrics_json : [];
        $actions = collect(is_array($r->actions_json) ? $r->actions_json : [])
            ->map(fn ($a) => is_array($a) ? '• ['.($a['priority'] ?? '-').'] '.($a['action'] ?? $this->readable($a)) : '• '.$a)->implode("\n");
        $text = $this->lines([
            $notice ? $notice."\n" : null,
            '↻ Review '.$r->type.' — '.$r->period_start?->format('Y-m-d').' تا '.$r->period_end?->format('Y-m-d'),
            '',
            (string) $r->summary,
            '',
            $this->metricLines($metrics),
            '',
            'اقدامات پیشنهادی:',
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
        $to = Carbon::now(self::TZ);
        $from = $type === 'daily' ? $to->copy()->startOfDay() : $to->copy()->subDays(6)->startOfDay();
        $metrics = $this->analytics->summary($from->copy()->timezone(config('app.timezone')), $to->copy()->timezone(config('app.timezone')), $this->uid());
        $review = $this->reviews->generate($type, $from, $to, $metrics);
        if ((int) $review->user_id !== $this->uid()) {
            $review->forceFill(['user_id' => $this->uid()])->save();
        }
        $this->reviewView($c, $m, (int) $review->id, '✅ Review ساخته شد.');
    }

    private function metricLines(array $x): string
    {
        $labels = [
            'tasks_created' => 'کارهای ایجادشده', 'tasks_completed' => 'کارهای تکمیل‌شده', 'completion_rate' => 'نرخ تکمیل %',
            'estimated_minutes' => 'زمان برآوردی (دقیقه)', 'actual_minutes' => 'زمان واقعی (دقیقه)', 'estimation_error_percent' => 'خطای برآورد %',
            'execution_minutes' => 'زمان اجرا (دقیقه)', 'execution_sessions' => 'جلسات اجرا', 'overdue_open_tasks' => 'کارهای باز عقب‌افتاده',
            'tasks_with_failures' => 'کارهای دارای شکست', 'scheduled_tasks' => 'کارهای زمان‌بندی‌شده', 'deep_work_minutes' => 'کار عمیق (دقیقه)',
            'velocity_completed_tasks_per_day' => 'سرعت (کار در روز)', 'active_goals' => 'اهداف فعال', 'average_goal_progress' => 'میانگین پیشرفت اهداف %',
            'overload_rate_percent' => 'نرخ روزهای پربار %', 'failure_rate_percent' => 'نرخ شکست %', 'blockers' => 'موانع',
        ];
        $out = [];
        foreach ($labels as $k => $l) {
            if (array_key_exists($k, $x) && $x[$k] !== null) $out[] = $l.': '.$x[$k];
        }
        foreach (['goal_health' => 'سلامت اهداف', 'priority_distribution' => 'توزیع اولویت', 'failure_reasons' => 'علل شکست'] as $k => $l) {
            if (!empty($x[$k]) && is_array($x[$k])) $out[] = $l.': '.$this->readable($x[$k]);
        }
        return implode("\n", $out);
    }

    private function analyticsView(string|int $c, int $m): void
    {
        $this->clearState($c);
        $to = Carbon::now(config('app.timezone'));
        $x = $this->analytics->summary($to->copy()->subDays(30), $to, $this->uid());
        $this->show($c, $m, "◫ Analytics (۳۰ روز اخیر)\n\n".$this->metricLines($x), [$this->back('analysis')]);
    }

    private function reportsMenu(string|int $c, int $m): void
    {
        $this->clearState($c);
        $this->show($c, $m, "▥ Reports\n\nبازه گزارش را انتخاب کن:", [
            [$this->btn('☀ روزانه', 'rday'), $this->btn('📆 هفتگی', 'rweek'), $this->btn('🗓 ماهانه', 'rmonth')],
            $this->back('analysis'),
        ]);
    }

    private function report(string|int $c, int $m, string $period): void
    {
        $to = Carbon::now(self::TZ);
        $from = match ($period) {
            'week' => $to->copy()->startOfWeek(Carbon::SATURDAY),
            'month' => $to->copy()->startOfMonth(),
            default => $to->copy()->startOfDay(),
        };
        $x = $this->analytics->summary($from->copy()->timezone(config('app.timezone')), $to->copy()->timezone(config('app.timezone')), $this->uid());
        $title = ['day' => '☀ گزارش روزانه', 'week' => '📆 گزارش هفتگی', 'month' => '🗓 گزارش ماهانه'][$period] ?? 'گزارش';
        $text = $this->lines([
            $title,
            $from->format('Y-m-d').' تا '.$to->format('Y-m-d H:i'),
            '',
            'ایجادشده: '.($x['tasks_created'] ?? 0),
            'تکمیل‌شده: '.($x['tasks_completed'] ?? 0),
            'نرخ تکمیل: '.($x['completion_rate'] ?? 0).'%',
            'زمان واقعی: '.($x['actual_minutes'] ?? 0).' دقیقه',
            'زمان اجرا: '.($x['execution_minutes'] ?? 0).' دقیقه ('.($x['execution_sessions'] ?? 0).' جلسه)',
            'کار عمیق: '.($x['deep_work_minutes'] ?? 0).' دقیقه',
            'عقب‌افتاده باز: '.($x['overdue_open_tasks'] ?? 0),
            'کارهای دارای شکست: '.($x['tasks_with_failures'] ?? 0),
        ]);
        $this->show($c, $m, $text, [
            [$this->btn('روزانه', 'rday'), $this->btn('هفتگی', 'rweek'), $this->btn('ماهانه', 'rmonth')],
            $this->back('analysis'),
        ]);
    }

    // =====================================================================
    // Knowledge & AI
    // =====================================================================

    private function beginAI(string|int $c, int $m): void
    {
        $this->putState($c, ['mode' => 'ai', 'mid' => $m]);
        $this->show($c, $m, "✦ AI Planner\n\nهدف یا سؤالت برای برنامه‌ریزی را بنویس (مثلاً «امروز روی چه کارهایی تمرکز کنم؟»).\n\nAI فقط پیشنهاد می‌دهد و هیچ تغییری در داده‌ها ایجاد نمی‌کند.", [$this->back('knowledge', '❌ لغو')]);
    }

    private function runAI(string|int $c, string $text): void
    {
        $text = mb_substr(trim($text), 0, 500);
        if ($text === '') {
            $this->show($c, null, 'متن درخواست خالی است. دوباره بنویس:', [$this->back('knowledge', '❌ لغو')]);
            return;
        }
        $this->clearState($c);
        $mid = $this->show($c, null, '⏳ AI Planner در حال تحلیل داده‌های شماست…');
        try {
            $response = app(AIPlannerService::class)->recommend(['focus' => $text], $this->uid());
        } catch (\Throwable $e) {
            report($e);
            $this->show($c, $mid, '❌ AI Planner در حال حاضر در دسترس نیست. بعداً دوباره تلاش کن.', [[$this->btn('✦ تلاش دوباره', 'aiplanner')], $this->back('knowledge')]);
            return;
        }
        $this->show($c, $mid, $this->formatAI($response['result'] ?? []), [[$this->btn('✦ درخواست دیگر', 'aiplanner')], $this->back('knowledge')]);
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
            '✦ AI Planner',
            '',
            '📝 خلاصه:',
            $block($result['summary'] ?? null),
            '',
            '⚠️ ریسک‌ها:',
            $block($result['risks'] ?? null),
            '',
            '💡 پیشنهادها:',
            $block($result['recommendations'] ?? null),
            '',
            'ℹ️ این‌ها فقط پیشنهاد هستند؛ هیچ تغییری در داده‌های Planner اعمال نشده است.',
        ]);
    }

    private function aiInteractions(string|int $c, int $m): void
    {
        $this->clearState($c);
        $items = $this->owned(AiInteraction::class)->latest('id')->limit(10)->get();
        $text = "✧ AI Interactions\n\n".($items->isEmpty() ? 'تعاملی ثبت نشده.' : $items->map(function (AiInteraction $x) {
            $in = is_array($x->input_payload) ? $x->input_payload : [];
            $outp = is_array($x->output_payload) ? $x->output_payload : [];
            $summary = $outp['summary'] ?? ($outp['raw'] ?? null);
            return $this->lines([
                '• '.$this->dt($x->created_at, 'm/d H:i').' — '.($x->intent ?: '—').' — '.$x->status,
                '   '.$x->provider.($x->model ? ' / '.$x->model : '').($x->confidence !== null ? ' — confidence '.$x->confidence : ''),
                isset($in['focus']) ? '   درخواست: '.mb_substr((string) $in['focus'], 0, 120) : null,
                $summary !== null ? '   خلاصه: '.mb_substr(is_array($summary) ? $this->readable($summary) : (string) $summary, 0, 160) : null,
            ]);
        })->implode("\n\n"));
        $this->show($c, $m, $text, [$this->back('knowledge')]);
    }

    private function pendingActions(string|int $c, int $m): void
    {
        $this->clearState($c);
        $items = $this->owned(PendingAction::class)->latest('id')->limit(15)->get();
        $text = "⌛ Pending Actions (فقط خواندنی)\n\n".($items->isEmpty() ? 'اقدام در انتظاری وجود ندارد.' : $items->map(function (PendingAction $x) {
            $payload = is_array($x->payload) ? $x->payload : [];
            $args = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : $payload;
            $brief = collect($args)->filter(fn ($v) => is_scalar($v) && $v !== '')->take(4)->map(fn ($v, $k) => $k.': '.mb_substr((string) $v, 0, 40))->implode(' | ');
            return $this->lines([
                '• #'.$x->id.' '.$x->intent.' — '.$x->status,
                '   ایجاد: '.$this->dt($x->created_at, 'm/d H:i').($x->expires_at ? ' | انقضا: '.$this->dt($x->expires_at, 'm/d H:i') : ''),
                $brief !== '' ? '   '.$brief : null,
            ]);
        })->implode("\n"));
        $this->show($c, $m, $text, [$this->back('knowledge')]);
    }

    private function activity(string|int $c, int $m): void
    {
        $this->clearState($c);
        $items = $this->owned(ActivityLog::class)->latest('created_at')->latest('id')->limit(20)->get();
        $text = "◌ Activity Logs\n\n".($items->isEmpty() ? 'فعالیتی ثبت نشده.' : $items->map(fn (ActivityLog $x) => '• '.$this->dt($x->created_at, 'm/d H:i').' — '.$x->action.' — '.class_basename((string) $x->entity_type).($x->entity_id !== null ? ' #'.$x->entity_id : ''))->implode("\n"));
        $this->show($c, $m, $text, [$this->back('knowledge')]);
    }
}
