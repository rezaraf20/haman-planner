<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Habit;
use App\Services\Planner\HabitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class HabitController extends Controller
{
    public function __construct(private readonly HabitService $habits) {}

    /** Today's habits, week consistency and the 12-week heatmap. ?archived=1 lists the archive. */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('archived')) {
            return response()->json(['habits' => Habit::query()->whereNotNull('archived_at')->latest('archived_at')->get()]);
        }
        return response()->json($this->habits->overview($request->user()));
    }

    public function show(Request $request, Habit $habit): JsonResponse
    {
        $weeks = (int) ($request->validate(['weeks' => 'nullable|integer|min:1|max:53'])['weeks'] ?? 12);
        return response()->json(['habit' => $this->habits->present($habit, $request->user()), 'history' => $this->habits->history($habit, $request->user(), $weeks)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['start_date'] ??= $this->habits->today($request->user())->toDateString();
        $habit = Habit::create($data);
        \App\Services\Analytics\ProductEvents::record($request->user(), \App\Services\Analytics\ProductEvents::FIRST_HABIT, [], true);
        return response()->json($this->habits->present($habit, $request->user()), 201);
    }

    public function update(Request $request, Habit $habit): JsonResponse
    {
        $habit->update($this->validated($request, false));
        return response()->json($this->habits->present($habit->refresh(), $request->user()));
    }

    public function destroy(Habit $habit): JsonResponse
    {
        $habit->delete(); // entries go with it (cascade)
        return response()->json(null, 204);
    }

    public function check(Request $request, Habit $habit): JsonResponse
    {
        $data = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'completed' => 'nullable|boolean',
            'value' => 'nullable|numeric|min:0|max:99999999',
            'add' => 'nullable|numeric|min:-100000|max:100000',
            'note' => 'nullable|string|max:1000',
        ]);
        $entry = $this->habits->check($habit, $request->user(), $data);
        if ($entry) \App\Services\Analytics\ProductEvents::record($request->user(), \App\Services\Analytics\ProductEvents::FIRST_HABIT_CHECKIN, [], true);
        return response()->json(['entry' => $entry, 'habit' => $this->habits->present($habit->refresh(), $request->user())]);
    }

    public function action(Request $request, Habit $habit, string $action): JsonResponse
    {
        $user = $request->user();
        match ($action) {
            'pause' => $this->habits->pause($habit, $user),
            'resume' => $this->habits->resume($habit, $user),
            'archive' => $habit->update(['archived_at' => now()]),
            'unarchive' => (function () use ($habit, $user) {
                app(\App\Services\Billing\Entitlements::class)->ensureCanCreate($user, 'active_habits');
                $habit->update(['archived_at' => null]);
            })(),
        };
        return response()->json($this->habits->present($habit->refresh(), $user));
    }

    private function validated(Request $request, bool $creating): array
    {
        $s = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => "$s|string|max:120",
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:16',
            'color' => ['nullable', 'string', 'max:20', 'regex:/^(#[0-9a-fA-F]{3,8}|[a-z]+)$/'],
            'kind' => ['nullable', Rule::in(Habit::KINDS)],
            'measure' => ['nullable', Rule::in(Habit::MEASURES)],
            'target_value' => 'nullable|numeric|min:0|max:99999999',
            'unit' => 'nullable|string|max:30',
            'frequency' => ['nullable', Rule::in(Habit::FREQUENCIES)],
            'weekdays' => 'nullable|array|max:7',
            'weekdays.*' => 'integer|between:1,7',
            'times_per_week' => 'nullable|integer|min:1|max:7',
            'interval_days' => 'nullable|integer|min:2|max:365',
            'start_date' => 'nullable|date_format:Y-m-d',
            'goal_id' => 'nullable|integer',
            'area_id' => 'nullable|integer',
            'sort_order' => 'nullable|integer',
        ]);
        foreach (['kind' => 'build', 'measure' => 'binary', 'frequency' => 'daily'] as $k => $default) {
            if (array_key_exists($k, $data) && $data[$k] === null) $data[$k] = $default;
        }
        $freq = $data['frequency'] ?? ($creating ? 'daily' : $request->route('habit')?->frequency);
        $errors = [];
        if ($freq === 'weekdays' && empty($data['weekdays']) && ($creating || array_key_exists('frequency', $data))) $errors['weekdays'] = __('habits.need_weekdays');
        if ($freq === 'weekly' && empty($data['times_per_week']) && ($creating || array_key_exists('frequency', $data))) $errors['times_per_week'] = __('habits.need_times');
        if ($freq === 'interval' && empty($data['interval_days']) && ($creating || array_key_exists('frequency', $data))) $errors['interval_days'] = __('habits.need_interval');
        if ($errors) throw \Illuminate\Validation\ValidationException::withMessages($errors);
        if (isset($data['weekdays'])) $data['weekdays'] = array_values(array_unique(array_map('intval', $data['weekdays'])));
        return $data;
    }
}
