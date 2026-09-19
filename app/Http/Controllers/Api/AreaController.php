<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AreaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Area::query()->orderBy('sort_order')->orderBy('name')->get());
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
            'type' => ['sometimes','string','max:50'],
            'description' => ['nullable','string'],
            'status' => ['sometimes','string','max:50'],
            'sort_order' => ['sometimes','integer','min:0'],
        ]));

        return response()->json($area->refresh());
    }

    public function destroy(Area $area): JsonResponse
    {
        $area->delete();
        return response()->json(null, 204);
    }
}
