<?php
declare(strict_types=1);
namespace App\Services\AI;
use App\Contracts\AIProviderInterface;
use App\Services\AI\Drivers\OpenAICompatibleProvider;
use InvalidArgumentException;
final class AIProviderFactory {
 public static function make(): AIProviderInterface {
  $key=env('AI_API_KEY',''); if(!$key) throw new InvalidArgumentException('AI_API_KEY is not configured.');
  return new OpenAICompatibleProvider(env('AI_BASE_URL','https://api.openai.com/v1'),$key,env('AI_MODEL','gpt-4o-mini'));
 }
}