<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedFilter;
use App\Models\Tag;
use App\Services\Planner\TaskFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tags and saved task filters. Everything is scoped to the signed-in user. */
final class TagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Tag::query()->withCount(['tasks as open_tasks' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled'])])->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $existing = Tag::query()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->first();
        return $existing ? response()->json($existing) : response()->json(Tag::create($data), 201);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $tag->update($this->validated($request, $tag));
        return response()->json($tag->refresh());
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete(); // only the label goes; tasks stay
        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Tag $tag = null): array
    {
        $data = $request->validate([
            'name' => [$tag ? 'sometimes' : 'required', 'string', 'max:40', 'regex:/^[^,#<>]+$/u'],
            'color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'icon' => 'nullable|string|max:16',
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim(preg_replace('/\s+/u', ' ', $data['name']));
            $clash = Tag::query()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->when($tag, fn ($q) => $q->whereKeyNot($tag->id))->exists();
            if ($clash && $tag) throw \Illuminate\Validation\ValidationException::withMessages(['name' => __('filters.tag_exists')]);
        }
        return $data;
    }

    // ---------------------------------------------------------------- saved filters

    public function filters(): JsonResponse
    {
        return response()->json(['presets' => array_keys(TaskFilter::PRESETS), 'saved' => SavedFilter::query()->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function storeFilter(Request $request, TaskFilter $filter): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'icon' => 'nullable|string|max:16', 'definition' => 'required|array']);
        $data['definition'] = $filter->validate($data['definition'], $request->user());
        abort_if(SavedFilter::query()->count() >= 50, 422, __('filters.too_many'));
        return response()->json(SavedFilter::create($data), 201);
    }

    public function updateFilter(Request $request, SavedFilter $savedFilter, TaskFilter $filter): JsonResponse
    {
        $data = $request->validate(['name' => 'sometimes|string|max:60', 'icon' => 'nullable|string|max:16', 'definition' => 'sometimes|array', 'sort_order' => 'nullable|integer']);
        if (isset($data['definition'])) $data['definition'] = $filter->validate($data['definition'], $request->user());
        $savedFilter->update($data);
        return response()->json($savedFilter->refresh());
    }

    public function destroyFilter(SavedFilter $savedFilter): JsonResponse
    {
        $savedFilter->delete();
        return response()->json(null, 204);
    }

    /** AI: natural language → a validated filter definition (a proposal; nothing runs server-side). */
    public function parse(Request $request, TaskFilter $filter): JsonResponse
    {
        $text = $request->validate(['text' => 'required|string|min:3|max:300'])['text'];
        if (!\App\Services\AI\AIProviderFactory::available()) {
            return response()->json(['message' => __('filters.ai_unavailable')], 503);
        }
        return response()->json(['definition' => $filter->fromText($text, $request->user())]);
    }
}
