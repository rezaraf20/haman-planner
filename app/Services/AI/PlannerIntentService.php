<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Models\PendingAction;
use App\Models\Task;
use App\Models\Goal;
use App\Models\Project;
use App\Models\Milestone;
use Illuminate\Database\Eloquent\Model;
use App\Services\Planner\ActivityLogger;
use App\Services\Planner\ConfirmationService;
use App\Services\Planner\PlannerService;
use App\Services\Planner\PlannerQueryService;
use App\Services\Telegram\TelegramService;
use RuntimeException;

final class PlannerIntentService
{
    public function __construct(
        private readonly AIProviderFactory $factory,
        private readonly PlannerService $planner,
        private readonly PlannerQueryService $queries,
        private readonly ConfirmationService $confirmations,
        private readonly TelegramService $telegram,
        private readonly EntityResolver $resolver,
        private readonly ActivityLogger $activity,
    ) {}

    public function handleText(string $text, string|int|null $chatId = null): array
    {
        if ($chatId !== null) {
            $command = mb_strtolower(trim($text));
            if (in_array($command, ['yes', 'y', 'ok', 'confirm', 'بله', 'تایید', 'تایید کن'], true)) {
                return $this->approve($chatId);
            }
            if (in_array($command, ['no', 'n', 'cancel', 'لغو', 'خیر'], true)) {
                return $this->reject($chatId);
            }
        }

        $command = mb_strtolower(trim($text));
        if ($chatId !== null && str_starts_with($command, '/')) {
            $parts = preg_split('/\\s+/', $command, 2);
            $commandName = $parts[0] ?? '';
            $argument = trim($parts[1] ?? '');
            $direct = match ($commandName) {
                '/start' => ['message' => 'Haman Planner آماده است. از /today، /tasks، /goals، /projects، /inbox، /review، /week، /report یا /search استفاده کن.'],
                '/today' => $this->queryPlan([], $chatId),
                '/tasks' => $this->queryTaskList($chatId),
                '/goals' => $this->queryGoalList($chatId),
                '/projects' => $this->queryProjectList($chatId),
                '/inbox' => $this->queryInbox($chatId),
                '/review' => $this->queryReview('DAILY_REVIEW', $chatId),
                '/week' => $this->queryReview('WEEKLY_REVIEW', $chatId),
                '/report' => $this->queryReport(['period' => 'month'], $chatId),
                '/search' => $this->querySearch($argument, $chatId),
                default => null,
            };
            if ($direct !== null) return $direct;
        }

        $context = [
            'current_datetime' => now('Asia/Tehran')->toIso8601String(),
            'timezone' => 'Asia/Tehran',
        ];

        $intent = $this->factory::make()->parseIntent($text, $context);
        $name = strtoupper((string) ($intent['intent'] ?? 'UNKNOWN'));
        $args = is_array($intent['arguments'] ?? null) ? $intent['arguments'] : [];
        $args = $this->normalizeArguments($args);

        return match ($name) {
            'CREATE_GOAL','UPDATE_GOAL','CREATE_PROJECT','UPDATE_PROJECT','CREATE_MILESTONE','UPDATE_MILESTONE','CREATE_TASK','UPDATE_TASK','COMPLETE_TASK','DEFER_TASK','CANCEL_TASK','LOG_PROGRESS','LOG_TIME','LOG_FAILURE','LOG_BLOCKER','ADD_REMINDER','SCHEDULE_TASK','RESCHEDULE_TASK' => $this->requestConfirmation($name, $args, $chatId),
            'QUERY_PLAN' => $this->queryPlan($args, $chatId),
            'QUERY_PROGRESS' => $this->queryProgress($args, $chatId),
            'QUERY_REPORT' => $this->queryReport($args, $chatId),
            'QUERY_GOAL' => $this->queryProgress($args, $chatId),
            'DAILY_REVIEW', 'WEEKLY_REVIEW' => $this->queryReview($name, $chatId),
            default => ['intent' => 'UNKNOWN', 'message' => 'I could not safely map this request to a planner action.'],
        };
    }

    private function queryTaskList(string|int $chatId): array
    {
        $result = $this->queries->taskSearch('');
        $result['tasks'] = Task::query()->whereNotIn('status', ['completed','cancelled'])->orderByDesc('priority')->limit(20)->get(['id','title','status','priority','progress'])->toArray();
        $message = count($result['tasks']).' کار باز در سیستم وجود دارد.';
        $this->telegram->sendMessage($chatId, $message);
        return ['intent' => 'QUERY_TASKS', 'result' => $result, 'message' => $message];
    }

    private function queryGoalList(string|int $chatId): array
    {
        $result = Goal::query()->whereNotIn('status', ['completed','cancelled'])->orderByDesc('importance')->limit(20)->get(['id','title','status','progress','health'])->toArray();
        $message = 'اهداف فعال: '.count($result);
        $this->telegram->sendMessage($chatId, $message);
        return ['intent' => 'QUERY_GOALS', 'result' => $result, 'message' => $message];
    }

    private function queryProjectList(string|int $chatId): array
    {
        $result = Project::query()->whereNotIn('status', ['completed','cancelled'])->orderByDesc('importance')->limit(20)->get(['id','title','status','progress','health'])->toArray();
        $message = 'پروژه‌های فعال: '.count($result);
        $this->telegram->sendMessage($chatId, $message);
        return ['intent' => 'QUERY_PROJECTS', 'result' => $result, 'message' => $message];
    }

    private function queryInbox(string|int $chatId): array
    {
        $result = Task::query()->where('status', 'inbox')->orderByDesc('id')->limit(20)->get(['id','title','priority','estimated_minutes'])->toArray();
        $message = 'Inbox: '.count($result).' کار.';
        $this->telegram->sendMessage($chatId, $message);
        return ['intent' => 'QUERY_INBOX', 'result' => $result, 'message' => $message];
    }

    private function querySearch(string $query, string|int $chatId): array
    {
        if ($query === '') {
            $message = 'عبارت جستجو را بعد از /search بنویس.';
            $this->telegram->sendMessage($chatId, $message);
            return ['intent' => 'QUERY_SEARCH', 'result' => [], 'message' => $message];
        }
        $result = $this->queries->taskSearch($query);
        $message = 'نتیجه جستجو: '.count($result['tasks'] ?? []).' کار.';
        $this->telegram->sendMessage($chatId, $message);
        return ['intent' => 'QUERY_SEARCH', 'result' => $result, 'message' => $message];
    }

    private function queryPlan(array $args, string|int|null $chatId): array
    {
        $result = $this->queries->today(max(1, (int) ($args['available_minutes'] ?? 480)));
        $message = $this->formatPlan($result);
        if ($chatId !== null) $this->telegram->sendMessage($chatId, $message);
        return ['intent' => 'QUERY_PLAN', 'result' => $result, 'message' => $message];
    }

    private function queryProgress(array $args, string|int|null $chatId): array
    {
        $result = $this->queries->progress(
            isset($args['goal_id']) ? (int) $args['goal_id'] : null,
            isset($args['project_id']) ? (int) $args['project_id'] : null,
        );
        $message = $this->formatProgress($result);
        if ($chatId !== null) $this->telegram->sendMessage($chatId, $message);
        return ['intent' => 'QUERY_PROGRESS', 'result' => $result, 'message' => $message];
    }

    private function queryReport(array $args, string|int|null $chatId): array
    {
        $result = $this->queries->report((string) ($args['period'] ?? 'month'));
        $message = sprintf("گزارش: %s کار ایجاد شد، %s کار تکمیل شد، نرخ تکمیل %s%%، %s دقیقه اجرا.",
            $result['tasks_created'] ?? 0, $result['tasks_completed'] ?? 0,
            $result['completion_rate'] ?? 0, $result['execution_minutes'] ?? 0);
        if ($chatId !== null) $this->telegram->sendMessage($chatId, $message);
        return ['intent' => 'QUERY_REPORT', 'result' => $result, 'message' => $message];
    }

    private function queryReview(string $intent, string|int|null $chatId): array
    {
        $result = $this->queries->report($intent === 'DAILY_REVIEW' ? 'day' : 'week');
        $message = sprintf("مرور %s: %s کار تکمیل شد، نرخ تکمیل %s%%، %s دقیقه اجرا.",
            $intent === 'DAILY_REVIEW' ? 'روزانه' : 'هفتگی',
            $result['tasks_completed'] ?? 0, $result['completion_rate'] ?? 0,
            $result['execution_minutes'] ?? 0);
        if ($chatId !== null) $this->telegram->sendMessage($chatId, $message);
        return ['intent' => $intent, 'result' => $result, 'message' => $message];
    }

    private function formatPlan(array $result): string
    {
        $lines = ["برنامه امروز — {$result['planned_minutes']} دقیقه"];
        foreach ($result['tasks'] as $task) $lines[] = "• [{$task['priority']}] {$task['title']} ({$task['estimated_minutes']} دقیقه)";
        if (!$result['tasks']) $lines[] = 'کار قابل برنامه‌ریزی پیدا نشد.';
        return implode("\n", $lines);
    }

    private function formatProgress(array $result): string
    {
        if (($result['found'] ?? false) === false) return (string) ($result['message'] ?? 'یافت نشد.');
        if (($result['type'] ?? '') === 'overview') return 'نمای کلی پیشرفت: '.count($result['goals']).' هدف و '.count($result['projects']).' پروژه فعال.';
        return sprintf("%s: %s%% — وضعیت: %s — سلامت: %s",
            $result['title'], $result['progress'], $result['status'] ?? '-', $result['health'] ?? '-');
    }

    private function requestConfirmation(string $intent, array $args, string|int|null $chatId): array
    {
        if ($chatId === null) {
            return ['intent' => $intent, 'confirmation_required' => true, 'message' => 'A confirmation channel is required for mutations.'];
        }

        if (in_array($intent, ['UPDATE_TASK','COMPLETE_TASK','DEFER_TASK','CANCEL_TASK','LOG_PROGRESS','LOG_TIME','LOG_FAILURE','LOG_BLOCKER','SCHEDULE_TASK','RESCHEDULE_TASK'], true)) {
            $resolution = $this->resolver->resolveWithStatus('task', $args);
            if (($resolution['status'] ?? null) === 'ambiguous') {
                $items = array_slice($resolution['candidates'] ?? [], 0, 5);
                $message = "چند کار مشابه پیدا شد؛ لطفاً یکی را انتخاب کن:\n".
                    collect($items)->values()->map(fn ($item, $i) => ($i + 1).'. '.$item['title'])->implode("\n");
                if ($chatId !== null) $this->telegram->sendMessage($chatId, $message);
                return ['intent' => $intent, 'confirmation_required' => false, 'ambiguous' => true, 'candidates' => $items, 'message' => $message];
            }
            if (($resolution['status'] ?? null) !== 'resolved') return ['intent' => $intent, 'confirmation_required' => false, 'message' => 'کار موردنظر پیدا نشد.'];
            $args['task_id'] = $resolution['model']->id;
        }
        if ($intent === 'ADD_REMINDER') {
            $args['chat_id'] = $args['chat_id'] ?? $chatId;
            if (empty($args['scheduled_at']) && empty($args['remind_at'])) {
                return ['intent' => $intent, 'confirmation_required' => false, 'message' => 'زمان یادآوری مشخص نشده است.'];
            }
            if (!empty($args['task']) || !empty($args['task_id'])) {
                $task = $this->resolver->resolveTask($args);
                if (!$task) return ['intent' => $intent, 'confirmation_required' => false, 'message' => 'کار موردنظر پیدا نشد یا نام آن مبهم است.'];
                $args['task_id'] = $task->id;
            }
        }
        if ($intent === 'CREATE_PROJECT' && (!empty($args['goal']) || !empty($args['goal_id']))) {
            $goal = $this->resolver->resolveGoal($args);
            if (!$goal) return ['intent' => $intent, 'confirmation_required' => false, 'message' => 'هدف پروژه پیدا نشد یا نام آن مبهم است.'];
            $args['goal_id'] = $goal->id;
        }

        if ($intent === 'CREATE_MILESTONE') {
            $project = $this->resolver->resolveProject($args);
            if (!$project) return ['intent' => $intent, 'confirmation_required' => false, 'message' => 'پروژه مایلستون پیدا نشد یا نام آن مبهم است.'];
            $args['project_id'] = $project->id;
        }

        foreach ([
            'UPDATE_GOAL' => ['resolver' => 'resolveGoal', 'key' => 'goal_id', 'message' => 'هدف موردنظر پیدا نشد یا نام آن مبهم است.'],
            'UPDATE_PROJECT' => ['resolver' => 'resolveProject', 'key' => 'project_id', 'message' => 'پروژه موردنظر پیدا نشد یا نام آن مبهم است.'],
            'UPDATE_MILESTONE' => ['resolver' => 'resolveMilestone', 'key' => 'milestone_id', 'message' => 'مایلستون موردنظر پیدا نشد یا نام آن مبهم است.'],
        ] as $mutation => $definition) {
            if ($intent !== $mutation) continue;
            $entity = $this->resolver->{$definition['resolver']}($args);
            if (!$entity) return ['intent' => $intent, 'confirmation_required' => false, 'message' => $definition['message']];
            $args[$definition['key']] = $entity->id;
        }

        $payload = ['arguments' => $args, 'resolved' => $this->resolvedReference($intent, $args)];
        $action = $this->confirmations->create($chatId, $intent, $payload);
        $summary = $this->summarize($intent, $args);

        $this->telegram->sendMessage($chatId, $summary."\n\nتأیید می‌کنی؟ (بله/خیر)\nاعتبار: ۱۰ دقیقه");

        return [
            'intent' => $intent,
            'confirmation_required' => true,
            'pending_action_id' => $action->id,
            'message' => $summary,
        ];
    }

    private function approve(string|int $chatId): array
    {
        $action = $this->confirmations->approve($chatId);
        if (! $action) {
            $this->telegram->sendMessage($chatId, 'درخواستی برای تأیید وجود ندارد یا منقضی شده است.');
            return ['confirmation' => 'none'];
        }

        try {
            $result = $this->execute($action);
            $this->telegram->sendMessage($chatId, $this->successMessage($action->intent, $result));
            return ['confirmation' => 'approved', 'result' => $result];
        } catch (\Throwable $e) {
            $action->update(['status' => 'failed']);
            throw $e;
        }
    }

    private function reject(string|int $chatId): array
    {
        $action = $this->confirmations->reject($chatId);
        if (! $action) {
            $this->telegram->sendMessage($chatId, 'درخواستی برای لغو وجود ندارد.');
            return ['confirmation' => 'none'];
        }

        $this->telegram->sendMessage($chatId, 'انجام نشد؛ درخواست لغو شد.');
        return ['confirmation' => 'rejected', 'pending_action_id' => $action->id];
    }

    private function execute(PendingAction $action): array
    {
        $args = (array) ($action->payload['arguments'] ?? []);
        $resolved = (array) ($action->payload['resolved'] ?? []);
        if ($resolved !== []) {
            $key = match ($resolved['type'] ?? null) {
                Task::class => 'task_id',
                Goal::class => 'goal_id',
                Project::class => 'project_id',
                Milestone::class => 'milestone_id',
                default => null,
            };
            if ($key !== null) $args[$key] = $resolved['id'];
        }

        return match ($action->intent) {
            'CREATE_GOAL' => ['goal' => $this->createGoal($args)->toArray()],
            'UPDATE_GOAL' => ['goal' => $this->updateGoal($args)->toArray()],
            'CREATE_PROJECT' => ['project' => $this->createProject($args)->toArray()],
            'UPDATE_PROJECT' => ['project' => $this->updateProject($args)->toArray()],
            'CREATE_MILESTONE' => ['milestone' => $this->createMilestone($args)->toArray()],
            'UPDATE_MILESTONE' => ['milestone' => $this->updateMilestone($args)->toArray()],
            'CREATE_TASK' => ['task' => $this->createTask($args)->toArray()],
            'UPDATE_TASK' => ['task' => $this->updateTask($args)->toArray()],
            'COMPLETE_TASK' => ['task' => $this->planner->complete($this->resolver->resolveTask($args) ?? throw new RuntimeException('Task could not be resolved.'))->toArray()],
            'DEFER_TASK' => ['task' => $this->planner->defer($this->resolver->resolveTask($args) ?? throw new RuntimeException('Task could not be resolved.'))->toArray()],
            'CANCEL_TASK' => ['task' => $this->updateTask($args + ['status' => 'cancelled'])->toArray()],
            'LOG_PROGRESS' => ['task' => $this->updateTask($args)->toArray()],
            'LOG_TIME' => ['execution_log' => $this->planner->logTime($this->resolver->resolveTask($args) ?? throw new RuntimeException('Task could not be resolved.'), $args)->toArray()],
            'LOG_FAILURE' => ['task' => $this->planner->logFailure($this->resolver->resolveTask($args) ?? throw new RuntimeException('Task could not be resolved.'), $args)->toArray()],
            'LOG_BLOCKER' => ['task' => $this->planner->logBlocker($this->resolver->resolveTask($args) ?? throw new RuntimeException('Task could not be resolved.'), $args)->toArray()],
            'ADD_REMINDER' => ['reminder' => $this->createReminder($args)->toArray()],
            'SCHEDULE_TASK' => ['task' => $this->scheduleTask($args)->toArray()],
            'RESCHEDULE_TASK' => ['task' => $this->scheduleTask($args)->toArray()],
            default => throw new RuntimeException('Unsupported pending action.'),
        };
    }

    private function createTask(array $args): Task
    {
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Task title is required.');

        return $this->planner->createTask([
            'title' => $title,
            'description' => $args['description'] ?? null,
            'importance' => (int) ($args['importance'] ?? 50),
            'estimated_minutes' => max(0, (int) ($args['estimated_minutes'] ?? 30)),
            'deadline' => $args['deadline'] ?? null,
            'planned_start' => $args['planned_start'] ?? null,
            'planned_end' => $args['planned_end'] ?? null,
            'status' => 'inbox',
        ]);
    }

    private function normalizeArguments(array $args): array
    {
        if (!empty($args['planned_start'])) {
            $args['planned_start'] = $this->normalizePlannedDateTime((string) $args['planned_start']);
            return $args;
        }

        if (!empty($args['scheduled_at'])) {
            $args['planned_start'] = $this->normalizePlannedDateTime((string) $args['scheduled_at']);
            unset($args['scheduled_at']);
            return $args;
        }

        $date = $args['scheduled_date'] ?? $args['date'] ?? null;
        $time = $args['scheduled_time'] ?? $args['time'] ?? null;

        if ($date !== null && $time !== null) {
            $normalizedDate = $this->normalizeRelativeDate((string) $date);
            $normalizedTime = $this->normalizeTime((string) $time);

            if ($normalizedDate !== null && $normalizedTime !== null) {
                $args['planned_start'] = $normalizedDate.' '.$normalizedTime.':00';
            }
        }

        return $args;
    }

    private function normalizePlannedDateTime(string $value): ?string
    {
        $value = trim($this->normalizeDigits($value));
        if ($value === '') return null;

        try {
            return \Carbon\Carbon::parse($value, 'Asia/Tehran')->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function normalizeRelativeDate(string $value): ?string
    {
        $value = trim($this->normalizeDigits($value));
        $value = preg_replace('/\\s+(صبح|بعدازظهر|عصر|شب)$/u', '', $value) ?? $value;
        $value = trim($value);
        $today = now('Asia/Tehran')->startOfDay();

        return match ($value) {
            'امروز', 'today' => $today->format('Y-m-d'),
            'فردا', 'tomorrow' => $today->copy()->addDay()->format('Y-m-d'),
            'پس فردا', 'پس‌فردا', 'day after tomorrow' => $today->copy()->addDays(2)->format('Y-m-d'),
            default => preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) ? $value : null,
        };
    }

    private function normalizeTime(string $value): ?string
    {
        $value = trim($this->normalizeDigits($value));
        $value = str_replace(['٫', '،'], ':', $value);
        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;

        $isPm = preg_match('/(?:بعدازظهر|عصر|شب|pm)$/iu', $value) === 1;
        $isAm = preg_match('/(?:صبح|am)$/iu', $value) === 1;
        $value = preg_replace('/\\s*(?:صبح|بعدازظهر|عصر|شب|am|pm)$/iu', '', $value) ?? $value;
        $value = trim($value);

        if (preg_match('/^(\\d{1,2}):(\\d{1,2})$/', $value, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        } elseif (preg_match('/^(\\d{1,2})$/', $value, $m)) {
            $hour = (int) $m[1];
            $minute = 0;
        } else {
            return null;
        }

        if ($hour > 23 || $minute > 59) return null;

        if ($isPm && !$isAm && $hour < 12) $hour += 12;
        if ($isAm && $hour === 12) $hour = 0;

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function createGoal(array $args): Goal
    {
        return $this->mutateModel(new Goal(), ['title' => trim((string) ($args['title'] ?? '')), 'description' => $args['description'] ?? null, 'status' => $args['status'] ?? 'active', 'importance' => (int) ($args['importance'] ?? 50), 'weight' => (float) ($args['weight'] ?? 1), 'target_date' => $args['target_date'] ?? $args['deadline'] ?? null, 'success_criteria' => $args['success_criteria'] ?? null]);
    }

    private function updateGoal(array $args): Goal
    {
        $goal = $this->resolver->resolveGoal($args) ?? throw new RuntimeException('Goal could not be resolved.');
        return $this->updateModel($goal, $this->editable($args, ['title','description','status','importance','weight','progress','target_date','success_criteria']));
    }

    private function createProject(array $args): Project
    {
        $goal = (!empty($args['goal_id']) || !empty($args['goal'])) ? $this->resolver->resolveGoal($args) : null;
        return $this->mutateModel(new Project(), ['goal_id' => $goal?->id, 'title' => trim((string) ($args['title'] ?? '')), 'description' => $args['description'] ?? null, 'status' => $args['status'] ?? 'active', 'importance' => (int) ($args['importance'] ?? 50), 'weight' => (float) ($args['weight'] ?? 1), 'estimated_minutes' => max(0, (int) ($args['estimated_minutes'] ?? 0)), 'target_date' => $args['target_date'] ?? $args['deadline'] ?? null]);
    }

    private function updateProject(array $args): Project
    {
        $project = $this->resolver->resolveProject($args) ?? throw new RuntimeException('Project could not be resolved.');
        return $this->updateModel($project, $this->editable($args, ['title','description','status','importance','weight','progress','target_date','estimated_minutes']));
    }

    private function createMilestone(array $args): Milestone
    {
        $project = $this->resolver->resolveProject($args) ?? throw new RuntimeException('Project is required for a milestone.');
        return $this->mutateModel(new Milestone(), ['project_id' => $project->id, 'title' => trim((string) ($args['title'] ?? '')), 'status' => $args['status'] ?? 'pending', 'weight' => (float) ($args['weight'] ?? 1), 'progress' => (float) ($args['progress'] ?? 0), 'target_date' => $args['target_date'] ?? $args['deadline'] ?? null]);
    }

    private function updateMilestone(array $args): Milestone
    {
        $milestone = $this->resolver->resolveMilestone($args) ?? throw new RuntimeException('Milestone could not be resolved.');
        return $this->updateModel($milestone, $this->editable($args, ['title','status','weight','progress','target_date']));
    }

    private function updateTask(array $args): Task
    {
        $task = $this->resolver->resolveTask($args) ?? throw new RuntimeException('Task could not be resolved.');
        return $this->updateModel($task, $this->editable($args, ['title','description','status','priority','importance','weight','progress','estimated_minutes','actual_minutes','deadline','planned_start','planned_end','energy_level','focus_level','failure_reason']));
    }

    private function createReminder(array $args): \App\Models\Reminder
    {
        $task = !empty($args['task_id']) || !empty($args['task'])
            ? $this->resolver->resolveTask($args)
            : null;
        $scheduled = $args['scheduled_at'] ?? $args['remind_at'] ?? null;
        if (!$scheduled) throw new RuntimeException('Reminder time is required.');
        $chatId = $args['chat_id'] ?? null;
        if ($chatId === null) throw new RuntimeException('Reminder chat_id is required.');
        $reminder = \App\Models\Reminder::create([
            'task_id' => $task?->id,
            'type' => $args['type'] ?? 'telegram',
            'scheduled_at' => \Carbon\Carbon::parse($scheduled),
            'status' => 'pending',
            'payload' => ['chat_id' => $chatId, 'message' => $args['message'] ?? null],
        ]);
        $this->activity->log('created', \App\Models\Reminder::class, $reminder->id, null, $reminder->toArray());
        return $reminder->refresh();
    }

    private function scheduleTask(array $args): Task
    {
        $task = $this->resolver->resolveTask($args) ?? throw new RuntimeException('Task could not be resolved.');
        $data = $this->editable($args, ['planned_start','planned_end']);
        if ($data === []) throw new RuntimeException('Schedule time is required.');
        if (isset($data['planned_start'], $data['planned_end']) && $data['planned_start'] !== null && $data['planned_end'] !== null) {
            $start = \Carbon\Carbon::parse($data['planned_start']);
            $end = \Carbon\Carbon::parse($data['planned_end']);
            if ($end->lt($start)) throw new RuntimeException('Schedule end must be after schedule start.');
        }
        return $this->updateModel($task, $data);
    }

    private function editable(array $args, array $keys): array
    {
        $data = [];
        foreach ($keys as $key) if (array_key_exists($key, $args)) $data[$key] = $args[$key];
        return $data;
    }

    private function mutateModel(Model $model, array $data): Model
    {
        if (trim((string) ($data['title'] ?? '')) === '') throw new RuntimeException('Title is required.');
        $model->fill($data);
        $model->save();
        $this->activity->log('created', $model::class, $model->id, null, $model->toArray());
        return $model->refresh();
    }

    private function updateModel(Model $model, array $data): Model
    {
        $before = $model->toArray();
        $model->fill($data);
        $model->save();
        $this->activity->log('updated', $model::class, $model->id, $before, $model->fresh()->toArray());
        return $model->refresh();
    }

    private function resolvedReference(string $intent, array $args): array
    {
        $map = [
            'UPDATE_TASK'=>'resolveTask','COMPLETE_TASK'=>'resolveTask','DEFER_TASK'=>'resolveTask',
            'CANCEL_TASK'=>'resolveTask','LOG_PROGRESS'=>'resolveTask',
            'UPDATE_GOAL'=>'resolveGoal','UPDATE_PROJECT'=>'resolveProject','UPDATE_MILESTONE'=>'resolveMilestone',
        ];
        if (!isset($map[$intent])) return [];
        $model = $this->resolver->{$map[$intent]}($args);
        return $model ? ['type' => $model::class, 'id' => $model->id, 'title' => $model->title] : [];
    }

    private function summarize(string $intent, array $args): string
    {
        return match ($intent) {
            'CREATE_TASK' => 'ایجاد کار: '.((string) ($args['title'] ?? 'بدون عنوان')),
            'COMPLETE_TASK' => 'تکمیل کار: '.((string) ($args['title'] ?? ('#'.($args['task_id'] ?? '?')))),
            'DEFER_TASK' => 'تعویق کار: '.((string) ($args['title'] ?? ('#'.($args['task_id'] ?? '?')))),
            default => 'تغییر در برنامه',
        };
    }

    private function successMessage(string $intent, array $result): string
    {
        $task = $result['task'] ?? null;
        $title = is_array($task) ? (string) ($task['title'] ?? '') : '';
        return match ($intent) {
            'CREATE_TASK' => "ایجاد شد: {$title}",
            'COMPLETE_TASK' => "تکمیل شد: {$title}",
            'DEFER_TASK' => "به تعویق افتاد: {$title}",
            'CANCEL_TASK' => "لغو شد: {$title}",
            'UPDATE_TASK', 'LOG_PROGRESS' => "به‌روزرسانی شد: {$title}",
            'SCHEDULE_TASK', 'RESCHEDULE_TASK' => "زمان‌بندی شد: {$title}",
            'LOG_BLOCKER' => "تسک مسدود شد: {$title}",
            'LOG_FAILURE' => "دلیل عدم موفقیت ثبت شد: {$title}",
            'LOG_TIME' => 'زمان اجرا ثبت شد.',
            'ADD_REMINDER' => 'یادآوری ثبت شد.',
            default => 'انجام شد.',
        };
    }
}
