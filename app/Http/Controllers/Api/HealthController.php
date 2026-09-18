<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
final class HealthController extends Controller {
 public function __invoke(): JsonResponse { return response()->json(['status'=>'ok','service'=>'haman-planner','time'=>now()->toIso8601String()]); }
}