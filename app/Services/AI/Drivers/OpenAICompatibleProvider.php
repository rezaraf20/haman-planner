<?php
declare(strict_types=1);
namespace App\Services\AI\Drivers;
use App\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;
final class OpenAICompatibleProvider implements AIProviderInterface {
 public function __construct(private readonly string $baseUrl, private readonly string $apiKey, private readonly string $model) {}
 public function chat(array $messages,array $options=[]): array {
  $r=Http::withToken($this->apiKey)->acceptJson()->post(rtrim($this->baseUrl,'/').'/chat/completions',array_merge(['model'=>$this->model,'messages'=>$messages],$options));
  if($r->failed()) throw new RuntimeException('AI provider request failed: '.$r->status());
  return $r->json();
 }
 public function parseIntent(string $input,array $context=[]): array {
  $system='Return JSON only. Allowed intents: CREATE_TASK, COMPLETE_TASK, DEFER_TASK, UPDATE_TASK, QUERY_PLAN, QUERY_PROGRESS, DAILY_REVIEW, WEEKLY_REVIEW, UNKNOWN.';
  $r=$this->chat([['role'=>'system','content'=>$system],['role'=>'user','content'=>json_encode(['input'=>$input,'context'=>$context],JSON_UNESCAPED_UNICODE)]],['temperature'=>0,'response_format'=>['type'=>'json_object']]);
  $v=json_decode($r['choices'][0]['message']['content']??'{}',true);
  return is_array($v)?$v:['intent'=>'UNKNOWN','arguments'=>[]];
 }
}