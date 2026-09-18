<?php

declare(strict_types=1);
namespace App\Services\AI;
use App\Services\Planner\RecommendationService;
use App\Models\Task;
use RuntimeException;
final class AIPlannerService
{
 public function __construct(private readonly RecommendationService $recommendations) {}
 public function recommend(array $context=[]): array
 {
  $data=['recommendations'=>$this->recommendations->build(),'open_tasks'=>Task::query()->whereNotIn('status',['completed','cancelled'])->orderByDesc('importance')->limit(50)->get(['id','title','status','priority','importance','estimated_minutes','deadline','planned_start','planned_end'])->toArray(),'context'=>$context];
  $provider=AIProviderFactory::make();
  $response=$provider->chat([
   ['role'=>'system','content'=>'You are Haman Planner planning assistant. Use only supplied planner data. Do not invent tasks, IDs, dates, or facts. Return JSON with summary, risks, recommendations. Recommendations are proposals only; never claim a mutation occurred.'],
   ['role'=>'user','content'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
  ],['temperature'=>0,'response_format'=>['type'=>'json_object']]);
  $raw=$response['choices'][0]['message']['content']??'{}'; $value=json_decode((string)$raw,true);
  if(!is_array($value)) throw new RuntimeException('AI planner returned invalid JSON.');
  return ['generated_at'=>now()->toIso8601String(),'grounded'=>true,'data_source'=>'stored_planner_data','result'=>$value];
 }
}
