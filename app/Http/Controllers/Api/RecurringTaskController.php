<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Planner\RecurrenceRule;
use App\Http\Controllers\Controller;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Services\Billing\Entitlements;
use App\Services\Planner\RecurrenceService;
use App\Support\RecurrenceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Recurring tasks (series). Occurrences are regular tasks and use the task endpoints. */
final class RecurringTaskController extends Controller
{
    public function __construct(private readonly RecurrenceService $recurrence) {}

    public function index(): JsonResponse
    {
        $rows = RecurringTask::query()->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")->latest('id')->limit(200)->get();
        return response()->json(['data' => $rows->map(fn (RecurringTask $s) => $this->present($s))]);
    }

    public function show(RecurringTask $recurringTask): JsonResponse
    {
        $upcoming = Task::query()->where('recurring_task_id', $recurringTask->id)
            ->orderBy('occurrence_date')->where('occurrence_date', '>=', now($recurringTask->timezone)->subDays(14)->toDateString())
            ->limit(40)->get(['id', 'title', 'status', 'occurrence_date', 'planned_start', 'recurrence_exception', 'completed_at']);
        return response()->json($this->present($recurringTask) + ['occurrences' => $upcoming]);
    }

    public function store(Request $request, Entitlements $entitlements): JsonResponse
    {
        $user = $request->user();
        $entitlements->ensureFeature($user, 'recurring_tasks');
        $data = $this->validated($request, false);
        $data['timezone'] = $user->preferredTimezone();
        $data['starts_on'] ??= CarbonImmutable::now($data['timezone'])->toDateString();
        $data['estimated_minutes'] ??= (int) $user->preference('default_task_minutes');
        $this->assertValidRule($data);

        $series = RecurringTask::create($data + ['status' => 'active'])->refresh();
        $this->recurrence->generate($series);
        return response()->json($this->present($series->refresh()), 201);
    }

    /** Edit all future occurrences (from `from_date`, default today in the series timezone). */
    public function update(Request $request, RecurringTask $recurringTask): JsonResponse
    {
        $data = $this->validated($request, true);
        $from = $request->validate(['from_date' => ['nullable', 'date']])['from_date'] ?? null;
        $data['timezone'] = $request->user()->preferredTimezone();
        $this->assertValidRule(array_merge($recurringTask->only(RecurringTask::RULE_FIELDS), $data, [
            'starts_on' => $data['starts_on'] ?? $recurringTask->starts_on?->toDateString(),
            'ends_on' => array_key_exists('ends_on', $data) ? $data['ends_on'] : $recurringTask->ends_on?->toDateString(),
        ]));
        $series = $this->recurrence->updateSeries($recurringTask, $data, $from);
        return response()->json($this->present($series));
    }

    public function stop(RecurringTask $recurringTask): JsonResponse
    {
        return response()->json($this->present($this->recurrence->stop($recurringTask)));
    }

    /** Skip one occurrence (it stays in history as skipped and is never recreated). */
    public function skip(Task $task): JsonResponse
    {
        abort_unless($task->isOccurrence(), 422, __('recurrence.not_occurrence'));
        return response()->json($this->recurrence->skip($task));
    }

    /** Dates a rule would produce (for the form preview) — nothing is saved. */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request, false);
        $data['starts_on'] ??= CarbonImmutable::now($request->user()->preferredTimezone())->toDateString();
        $rule = $this->assertValidRule($data);
        $dates = $rule->between($data['starts_on'], CarbonImmutable::parse($data['starts_on'])->addYears(2)->toDateString());
        return response()->json(['dates' => array_slice($dates, 0, 8)]);
    }

    private function validated(Request $request, bool $partial): array
    {
        $s = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$s, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'area_id' => ['sometimes', 'nullable', 'integer'],
            'goal_id' => ['sometimes', 'nullable', 'integer'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'milestone_id' => ['sometimes', 'nullable', 'integer'],
            'priority' => ['sometimes', 'nullable', Rule::in(['p0', 'p1', 'p2', 'p3'])],
            'importance' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1440'],
            'frequency' => [$s, Rule::in(RecurrenceRule::FREQUENCIES)],
            'calendar' => ['sometimes', 'nullable', Rule::in(['gregorian', 'jalali'])],
            'interval' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'by_weekday' => ['sometimes', 'nullable', 'array', 'max:7'],
            'by_weekday.*' => ['integer', 'min:1', 'max:7'],
            'by_month_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'by_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'max_occurrences' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
            'time_of_day' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);
        // An untouched optional select arrives empty: treat it as "not chosen" (series default / unchanged).
        foreach (['priority', 'importance', 'weight', 'calendar', 'interval'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) unset($data[$k]);
        }
        if (isset($data['by_weekday'])) {
            $data['by_weekday'] = array_values(array_unique(array_map('intval', $data['by_weekday'])));
            sort($data['by_weekday']);
        }
        if (array_key_exists('starts_on', $data) && $data['starts_on'] !== null) {
            $data['starts_on'] = CarbonImmutable::parse($data['starts_on'])->toDateString();
        }
        if (array_key_exists('ends_on', $data) && $data['ends_on'] !== null) {
            $data['ends_on'] = CarbonImmutable::parse($data['ends_on'])->toDateString();
        }
        return $data;
    }

    private function assertValidRule(array $d): RecurrenceRule
    {
        if (!empty($d['ends_on']) && !empty($d['starts_on']) && $d['ends_on'] < $d['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => __('recurrence.ends_before_start')]);
        }
        try {
            return new RecurrenceRule(
                (string) $d['frequency'], (int) ($d['interval'] ?? 1), array_map('intval', (array) ($d['by_weekday'] ?? [])),
                isset($d['by_month_day']) ? (int) $d['by_month_day'] : null, isset($d['by_month']) ? (int) $d['by_month'] : null,
                (string) $d['starts_on'], $d['ends_on'] ?? null, isset($d['max_occurrences']) ? (int) $d['max_occurrences'] : null,
                (string) ($d['calendar'] ?? 'gregorian'),
            );
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['frequency' => __('recurrence.invalid_rule')]);
        }
    }

    /** @return array<string,mixed> */
    private function present(RecurringTask $s): array
    {
        $next = null;
        if ($s->isActive()) {
            $next = $s->rule()->next(CarbonImmutable::now($s->timezone)->toDateString());
        }
        return $s->toArray() + ['rule_label' => RecurrenceLabel::for($s), 'next_date' => $next];
    }
}
