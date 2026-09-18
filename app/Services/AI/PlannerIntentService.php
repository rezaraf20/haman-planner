<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Models\PendingAction;
use App\Models\Task;
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

        $intent = $this->factory::make()->parseIntent($text);
        $name = strtoupper((string) ($intent['intent'] ?? 'UNKNOWN'));
        $args = is_array($intent['arguments'] ?? null) ? $intent['arguments'] : [];

        return match ($name) {
            'CREATE_TASK', 'COMPLETE_TASK', 'DEFER_TASK' => $this->requestConfirmation($name, $args, $chatId),
            'QUERY_PLAN' => $this->queryPlan($args, $chatId),
            'QUERY_PROGRESS' => $this->queryProgress($args, $chatId),
            'QUERY_REPORT' => $this->queryReport($args, $chatId),
            'QUERY_GOAL' => $this->queryProgress($args, $chatId),
            'DAILY_REVIEW', 'WEEKLY_REVIEW' => $this->queryReview($name, $chatId),
            default => ['intent' => 'UNKNOWN', 'message' => 'I could not safely map this request to a planner action.'],
        };
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

        if ($intent !== 'CREATE_TASK' && ! $this->resolveTask($args)) {
            return ['intent' => $intent, 'confirmation_required' => false, 'message' => 'Task could not be resolved.'];
        }

        $payload = ['arguments' => $args];
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

        return match ($action->intent) {
            'CREATE_TASK' => ['task' => $this->createTask($args)->toArray()],
            'COMPLETE_TASK' => ['task' => $this->planner->complete($this->resolveTaskOrFail($args))->toArray()],
            'DEFER_TASK' => ['task' => $this->deferTask($args)->toArray()],
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
            'status' => 'inbox',
        ]);
    }

    private function deferTask(array $args): Task
    {
        return $this->planner->defer($this->resolveTaskOrFail($args));
    }

    private function resolveTaskOrFail(array $args): Task
    {
        $task = $this->resolveTask($args);
        if (! $task) throw new RuntimeException('Task could not be resolved.');
        return $task;
    }

    private function resolveTask(array $args): ?Task
    {
        if (! empty($args['task_id'])) return Task::find((int) $args['task_id']);
        $title = trim((string) ($args['title'] ?? ''));
        return $title !== '' ? Task::where('title', $title)->latest('id')->first() : null;
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
            default => 'انجام شد.',
        };
    }
}
