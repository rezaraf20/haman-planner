<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planner\LifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/{goals|projects|milestones|areas}/{id}/{complete|reopen|cancel|archive|unarchive} */
final class LifecycleController extends Controller
{
    public function __construct(private readonly LifecycleService $lifecycle) {}

    public function __invoke(Request $request, string $kind, int $id, string $action): JsonResponse
    {
        $class = LifecycleService::KINDS[$kind];
        $item = $class::query()->findOrFail($id); // owner scope applies
        if ($kind === 'areas' && !in_array($action, ['archive', 'unarchive'], true)) {
            abort(404);
        }
        $data = $request->validate(['cancel_open_tasks' => 'sometimes|boolean']);
        $item = $this->lifecycle->apply($item, $action, (bool) ($data['cancel_open_tasks'] ?? false));

        return response()->json($item->setAttribute('open_tasks_count', $this->lifecycle->openTasks($item)->count()));
    }

    /** GET …/{id}/open-tasks — how many open tasks would be affected (for the confirmation dialog). */
    public function openTasks(string $kind, int $id): JsonResponse
    {
        $class = LifecycleService::KINDS[$kind];
        $item = $class::query()->findOrFail($id);
        return response()->json(['count' => $this->lifecycle->openTasks($item)->count()]);
    }
}
