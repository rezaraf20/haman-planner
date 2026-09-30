<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiInteraction;
use App\Models\FailureReason;
use App\Models\PendingAction;
use App\Models\ExecutionLog;
use App\Models\DailyPlan;
use App\Models\Task;
use App\Services\Planner\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
final class SystemController extends Controller {
 private function metrics(int $days): array { $to=Carbon::now(); return app(AnalyticsService::class)->summary($to->copy()->subDays($days),$to); }
 public function dashboard(Request $r): JsonResponse { $asked=max(1,min(90,(int)$r->input('days',30))); $days=$this->historyDays($r,$asked); return response()->json(['history_days'=>$days,'history_limited'=>$days<$asked,'upgrade_url'=>$days<$asked?route('billing.index'):null,'metrics'=>$this->metrics($days),'recent_tasks'=>Task::query()->latest()->limit(8)->get(['id','title','status','priority','progress','deadline']),'pending_actions'=>PendingAction::query()->where('status','pending')->latest()->limit(10)->get(),'activity'=>ActivityLog::query()->latest()->limit(15)->get()]); }
 /** Plans without advanced analytics see a shorter history (config billing.basic_analytics_days). */
 private function historyDays(Request $r, int $asked): int { return $r->user() ? app(\App\Services\Billing\Entitlements::class)->historyDays($r->user(), $asked) : $asked; }
 public function activity(): JsonResponse { return response()->json(ActivityLog::query()->latest()->paginate(50)); }
 public function ai(): JsonResponse { return response()->json(AiInteraction::query()->latest()->paginate(50)); }
 public function pending(): JsonResponse { return response()->json(PendingAction::query()->latest()->paginate(50)); }
 public function failures(): JsonResponse { return response()->json(FailureReason::query()->latest()->paginate(50)); }
 public function execution(Request $r): JsonResponse { $q=ExecutionLog::query()->with('task')->latest(); if($r->filled('task_id'))$q->where('task_id',$r->integer('task_id')); return response()->json($q->paginate(50)); }
 public function dependencies(): JsonResponse { return response()->json(\App\Models\TaskDependency::query()->with(['task:id,title,status','dependsOn:id,title,status'])->latest('id')->paginate(100)); }
 public function dailyPlans(): JsonResponse { return response()->json(DailyPlan::query()->latest('plan_date')->paginate(31)); }
 public function report(Request $r): JsonResponse { $period=$r->input('period','week'); $asked=$period==='day'?1:($period==='month'?30:7); $days=$this->historyDays($r,$asked); return response()->json(['period'=>$period,'history_days'=>$days,'history_limited'=>$days<$asked,'upgrade_url'=>$days<$asked?route('billing.index'):null,'metrics'=>$this->metrics($days),'tasks'=>Task::query()->where('created_at','>=',now()->subDays($days))->get(['id','title','status','priority','progress','estimated_minutes','actual_minutes','deadline'])]); }
}