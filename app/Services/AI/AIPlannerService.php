<?php

declare(strict_types=1);
namespace App\Services\AI;
use App\Services\Planner\RecommendationService;
use App\Models\Task;
use RuntimeException;
use App\Models\AiInteraction;
use Illuminate\Support\Str;
use App\Support\PlannerUserContext;
use Illuminate\Support\Facades\Auth;
final class AIPlannerService
{
 public function __construct(private readonly RecommendationService $recommendations) {}
 /**
  * @param array $context  free-form planner context (e.g. ['focus'=>...]) sent to the model
  * @param int|null $userId planner owner; defaults to PlannerUserContext (Telegram) then Auth (web).
  *                         Only this user's tasks are sent to the AI provider when an owner is known.
  */
 public function recommend(array $context=[], ?int $userId=null): array
 {
  $userId ??= PlannerUserContext::id() ?? (Auth::id()!==null?(int)Auth::id():null);
  $data=['recommendations'=>$this->recommendations->build($userId),'open_tasks'=>Task::query()->when($userId!==null,fn($q)=>$q->where('user_id',$userId))->whereNotIn('status',['completed','cancelled'])->orderByDesc('importance')->limit(50)->get(['id','title','status','priority','importance','estimated_minutes','deadline','planned_start','planned_end'])->toArray(),'context'=>$context];
  $provider=AIProviderFactory::make();
  $response=$provider->chat([
   ['role'=>'system','content'=>'You are Haman Planner planning assistant. Use only supplied planner data. Do not invent tasks, IDs, dates, or facts. Return JSON with summary, risks, recommendations. Recommendations are proposals only; never claim a mutation occurred.'],
   ['role'=>'user','content'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
  ],['temperature'=>0,'response_format'=>['type'=>'json_object']]);
  $raw=$response['choices'][0]['message']['content']??'{}'; $value=json_decode((string)$raw,true);
  $valid=is_array($value);
  AiInteraction::create(['user_id'=>$userId,'provider'=>(string)config('services.ai.provider','configured'),'model'=>(string)config('services.ai.model','configured'),'intent'=>'AI_PLANNER','input_hash'=>hash('sha256',json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'input_payload'=>$context,'output_payload'=>$valid?$value:['raw'=>$raw],'confidence'=>null,'status'=>$valid?'completed':'invalid']);
  if(!$valid) throw new RuntimeException('AI planner returned invalid JSON.');
  return ['generated_at'=>now()->toIso8601String(),'grounded'=>true,'data_source'=>'stored_planner_data','result'=>$value];
 }
}
