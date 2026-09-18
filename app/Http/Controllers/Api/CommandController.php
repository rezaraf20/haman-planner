<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\PlannerIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommandController extends Controller
{
    public function __construct(private readonly PlannerIntentService $service) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|max:5000']);
        return response()->json($this->service->handleText($data['text']));
    }
}
