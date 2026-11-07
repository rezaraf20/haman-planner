<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\AiInteraction;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\AIPlannerService;
use App\Services\AI\AIProviderFactory;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Split a task into subtasks. The AI only proposes steps (validated, never saved); subtasks are
 * created by create(), from the list the user confirmed and edited. Without AI the user can
 * still add steps by hand through the same create() path.
 */
final class TaskBreakdownService
{
    public const MAX_STEPS = 8;
    public const MAX_CREATE = 12;

    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly EstimateService $estimates,
        private readonly PlannerService $planner,
    ) {}

    public function aiAvailable(User $user): bool
    {
        return $user->preference('ai_enabled') !== false && AIProviderFactory::available();
    }

    /** @return array{steps:list<array{title:string,estimated_minutes:?int,suggested_minutes:?int}>,calibrated:bool,factor:?float} */
    public function propose(User $user, Task $task): array
    {
        $this->entitlements->ensureFeature($user, 'ai_planner');
        $this->entitlements->consume($user, 'ai_requests');
        $requestId = (string) Str::uuid();
        $project = $task->project_id ? Project::query()->ownedBy($user->id)->whereKey($task->project_id)->value('title') : null;
        try {
            $r = AIProviderFactory::make()->chat([
                ['role' => 'system', 'content' => 'You are Haman AI. Break the given task into 2 to '.self::MAX_STEPS.' concrete, sequential steps that one person can do. '
                    .'Each step: "title" (an action, at most 120 characters) and "estimated_minutes" (integer 5–240). Use only what the task says; do not invent names, dates, '
                    .'people or facts. Do not repeat the task itself as a step. Return only JSON {"steps":[{"title":string,"estimated_minutes":integer}]}. Write the titles in '
                    .AIPlannerService::responseLanguage($user).'.'],
                ['role' => 'user', 'content' => json_encode(['task' => ['title' => $task->title, 'description' => mb_substr((string) $task->description, 0, 2000),
                    'estimated_minutes' => (int) $task->estimated_minutes ?: null, 'project' => $project]], JSON_UNESCAPED_UNICODE)],
            ], ['temperature' => 0.2, 'response_format' => ['type' => 'json_object'], '_feature' => 'task_breakdown']);
        } catch (\Throwable $e) {
            $this->entitlements->refund($user, 'ai_requests');
            Log::warning('Task breakdown failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            throw $e;
        }
        $out = json_decode((string) ($r['choices'][0]['message']['content'] ?? ''), true);
        $steps = $this->validSteps(is_array($out) ? ($out['steps'] ?? null) : null, $task);
        $used = AIProviderFactory::lastUsed();
        AiInteraction::create(['user_id' => $user->id, 'provider' => $used['provider'], 'model' => $used['model'], 'intent' => 'TASK_BREAKDOWN',
            'input_hash' => hash('sha256', $requestId), 'input_payload' => ['task_id' => $task->id], 'output_payload' => is_array($out) ? $out : null,
            'confidence' => null, 'status' => $steps ? 'completed' : 'invalid', 'request_id' => $requestId]);
        if (!$steps) {
            throw ValidationException::withMessages(['steps' => __('breakdown.ai_unclear')]);
        }

        $factor = app(PlanInsightsService::class)->planVsActual($user, 90)['estimation']['ratio'];
        return [
            'steps' => array_map(fn ($s) => $s + ['suggested_minutes' => $this->estimates->calibrate($user, $s['estimated_minutes'])], $steps),
            'calibrated' => $factor !== null,
            'factor' => $factor,
        ];
    }

    /** Create the subtasks the user confirmed. All or nothing; they inherit the parent's context. */
    public function create(User $user, Task $parent, array $in): array
    {
        $d = validator($in, [
            'steps' => 'required|array|min:1|max:'.self::MAX_CREATE,
            'steps.*.title' => 'nullable|string|max:255', // blank rows are skipped
            'steps.*.estimated_minutes' => 'nullable|integer|min:0|max:1440',
            'update_parent_estimate' => 'nullable|boolean',
        ])->validate();
        if (in_array($parent->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['steps' => __('breakdown.parent_closed')]);
        }

        return DB::transaction(function () use ($user, $parent, $d) {
            $created = [];
            foreach ($d['steps'] as $s) {
                $title = trim(strip_tags((string) ($s['title'] ?? '')));
                if ($title === '') continue;
                $created[] = $this->planner->createTask([
                    'user_id' => $user->id, 'parent_task_id' => $parent->id, 'title' => mb_substr($title, 0, 255),
                    'area_id' => $parent->area_id, 'goal_id' => $parent->goal_id, 'project_id' => $parent->project_id, 'milestone_id' => $parent->milestone_id,
                    'importance' => $parent->importance, 'estimated_minutes' => (int) ($s['estimated_minutes'] ?? 0),
                ]);
            }
            if (!$created) throw ValidationException::withMessages(['steps' => __('breakdown.empty')]);
            if (!empty($d['update_parent_estimate'])) {
                $sum = array_sum(array_map(fn ($t) => (int) $t->estimated_minutes, $created));
                if ($sum > 0) $parent->update(['estimated_minutes' => $sum]);
            }
            \App\Services\Analytics\ProductEvents::record($user, \App\Services\Analytics\ProductEvents::FIRST_BREAKDOWN, [], true);
            return $created;
        });
    }

    /** @return list<array{title:string,estimated_minutes:?int}> */
    private function validSteps(mixed $steps, Task $task): array
    {
        if (!is_array($steps)) return [];
        $out = [];
        $seen = [mb_strtolower(trim($task->title)) => true];
        foreach (array_slice($steps, 0, self::MAX_STEPS) as $s) {
            if (!is_array($s) || !is_string($s['title'] ?? null)) continue;
            $title = trim(preg_replace('/\s+/u', ' ', strip_tags($s['title'])));
            $key = mb_strtolower($title);
            if ($title === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $m = $s['estimated_minutes'] ?? null;
            $m = is_numeric($m) && (int) $m >= 5 && (int) $m <= 480 ? (int) $m : null;
            $out[] = ['title' => mb_substr($title, 0, 160), 'estimated_minutes' => $m];
        }
        return $out;
    }
}
