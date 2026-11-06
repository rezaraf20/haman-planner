<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $state = $request->validate(['state' => 'nullable|in:open,archived,all'])['state'] ?? null;
        return response()->json(Area::query()->inState($state === 'open' ? null : $state)->orderBy('sort_order')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $area = Area::create($request->validate([
            'name' => ['required','string','max:255'],
            'type' => ['nullable','string','max:50'],
            'description' => ['nullable','string'],
            'status' => ['nullable','string','max:50'],
            'sort_order' => ['nullable','integer','min:0'],
        ]));

        return response()->json($area, 201);
    }

    public function update(Request $request, Area $area): JsonResponse
    {
        $area->update($request->validate([
            'name' => ['sometimes','string','max:255'],
            'type' => ['sometimes','nullable','string','max:50'],
            'description' => ['nullable','string'],
            'status' => ['sometimes','nullable','string','max:50'],
            'sort_order' => ['sometimes','nullable','integer','min:0'],
        ]));

        return response()->json($area->refresh());
    }

    public function destroy(Area $area): JsonResponse
    {
        $area->delete();
        return response()->json(null, 204);
    }
}
