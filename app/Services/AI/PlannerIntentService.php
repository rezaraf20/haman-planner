<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Services\Planner\PlannerService;
use App\Services\Telegram\TelegramService;
use App\Models\Task;
use RuntimeException;

final class PlannerIntentService
{
    public function __construct(
        private readonly AIProviderFactory $factory,
        private readonly PlannerService $planner,
        private readonly TelegramService $telegram,
    ) {}

    public function handleText(string $text, string|int|null $chatId = null): array
    {
        $intent = $this->factory::make()->parseIntent($text);
        $name = strtoupper((string) ($intent['intent'] ?? 'UNKNOWN'));
        $args = is_array($intent['arguments'] ?? null) ? $intent['arguments'] : [];

        return match ($name) {
            'CREATE_TASK' => $this->createTask($args, $chatId),
            'COMPLETE_TASK' => $this->completeTask($args, $chatId),
            'DEFER_TASK' => $this->deferTask($args, $chatId),
            'QUERY_PLAN' => ['intent' => $name, 'requires_query' => true],
            'QUERY_PROGRESS' => ['intent' => $name, 'requires_query' => true],
            'DAILY_REVIEW', 'WEEKLY_REVIEW' => ['intent' => $name, 'requires_review' => true],
            default => ['intent' => 'UNKNOWN', 'message' => 'I could not safely map this request to a planner action.'],
        };
    }

    private function createTask(array $args, string|int|null $chatId): array
    {
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Task title is required.');

        $task = $this->planner->createTask([
            'title' => $title,
            'description' => $args['description'] ?? null,
            'importance' => (int) ($args['importance'] ?? 50),
            'estimated_minutes' => max(0, (int) ($args['estimated_minutes'] ?? 30)),
            'deadline' => $args['deadline'] ?? null,
            'status' => 'inbox',
        ]);

        $result = ['intent' => 'CREATE_TASK', 'task' => $task->toArray(), 'confirmation_required' => false];
        if ($chatId !== null) $this->telegram->sendMessage($chatId, "Task created: {$task->title}");
        return $result;
    }

    private function completeTask(array $args, string|int|null $chatId): array
    {
        $task = $this->resolveTask($args);
        if (!$task) return ['intent' => 'COMPLETE_TASK', 'confirmation_required' => true, 'message' => 'Task could not be resolved.'];

        $task = $this->planner->complete($task);
        if ($chatId !== null) $this->telegram->sendMessage($chatId, "Completed: {$task->title}");
        return ['intent' => 'COMPLETE_TASK', 'task' => $task->toArray()];
    }

    private function deferTask(array $args, string|int|null $chatId): array
    {
        $task = $this->resolveTask($args);
        if (!$task) return ['intent' => 'DEFER_TASK', 'confirmation_required' => true, 'message' => 'Task could not be resolved.'];

        $task->update(['status' => 'deferred']);
        if ($chatId !== null) $this->telegram->sendMessage($chatId, "Deferred: {$task->title}");
        return ['intent' => 'DEFER_TASK', 'task' => $task->refresh()->toArray()];
    }

    private function resolveTask(array $args): ?Task
    {
        if (!empty($args['task_id'])) return Task::find((int) $args['task_id']);
        $title = trim((string) ($args['title'] ?? ''));
        return $title !== '' ? Task::where('title', $title)->latest('id')->first() : null;
    }
}
