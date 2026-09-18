<?php

declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\AI\AIPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
final class AIPlannerController extends Controller
{
 public function __invoke(Request $request, AIPlannerService $planner): JsonResponse
 {
  try{return response()->json($planner->recommend($request->validate(['focus'=>'nullable|string|max:500'])));}catch(\Throwable $e){return response()->json(['message'=>'AI planner unavailable','grounded'=>false],503);}
 }
}
