<?php

declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Task;
use Illuminate\Support\Collection;

final class RecommendationService
{
    public function build(): array
    {
        $tasks=Task::query()->whereNotIn('status',['completed','cancelled'])->orderByDesc('importance')->get();
        $overdue=$tasks->filter(fn(Task $t)=>$t->deadline && $t->deadline->isPast());
        $blocked=$tasks->where('status','blocked');
        $unplanned=$tasks->whereNull('planned_start');
        $recommendations=[];
        if($overdue->isNotEmpty())$recommendations[]=['type'=>'overdue','action'=>'review_overdue_tasks','count'=>$overdue->count(),'task_ids'=>$overdue->pluck('id')->values()->all()];
        if($blocked->isNotEmpty())$recommendations[]=['type'=>'blocked','action'=>'resolve_blockers','count'=>$blocked->count(),'task_ids'=>$blocked->pluck('id')->values()->all()];
        if($unplanned->isNotEmpty())$recommendations[]=['type'=>'unplanned','action'=>'schedule_ready_work','count'=>$unplanned->count(),'task_ids'=>$unplanned->take(10)->pluck('id')->values()->all()];
        if(!$recommendations)$recommendations[]=['type'=>'maintenance','action'=>'continue_current_plan','count'=>0,'task_ids'=>[]];
        return ['generated_at'=>now()->toIso8601String(),'source'=>'stored_planner_data','recommendations'=>$recommendations];
    }
}
