<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Services\AI\Drivers\OpenAICompatibleProvider;
use App\Support\PlannerUserContext;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * Tries the configured AI connections in priority order: a connection that fails or has used its
 * monthly token budget is skipped and the next one answers. Every call is recorded in ai_usage
 * (tokens, latency, outcome — never the prompt or the answer).
 */
final class ManagedAIProvider implements AIProviderInterface
{
    /** @var array{provider:string,model:string,id:?int}|null */
    private static ?array $lastUsed = null;

    /** @param list<array{id:?int,label:string,base:string,key:string,model:string,provider:?AiProvider}> $candidates */
    public function __construct(private readonly array $candidates) {}

    public static function lastUsed(): ?array
    {
        return self::$lastUsed;
    }

    public function chat(array $messages, array $options = []): array
    {
        $feature = (string) ($options['_feature'] ?? 'chat');
        unset($options['_feature']);
        return $this->attempt(fn (OpenAICompatibleProvider $d) => $d->chat($messages, $options), $feature);
    }

    public function parseIntent(string $input, array $context = []): array
    {
        return $this->attempt(fn (OpenAICompatibleProvider $d) => $d->parseIntent($input, $context), 'intent');
    }

    private function attempt(callable $call, string $feature): mixed
    {
        $last = null;
        $tried = 0;
        foreach ($this->candidates as $c) {
            if ($c['provider'] && $c['provider']->overBudget()) {
                continue;
            }
            $tried++;
            $driver = new OpenAICompatibleProvider($c['base'], $c['key'], $c['model'], fn (bool $ok, array $json, int $ms, ?string $error) => $this->record($c, $feature, $ok, $json, $ms, $error));
            try {
                $result = $call($driver);
                self::$lastUsed = ['provider' => $c['label'], 'model' => $c['model'], 'id' => $c['id']];
                $c['provider']?->forceFill(['last_success_at' => now()])->saveQuietly();
                return $result;
            } catch (\Throwable $e) {
                $last = $e;
                $c['provider']?->forceFill(['last_error_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 300)])->saveQuietly();
            }
        }
        throw $last ?? new RuntimeException($tried === 0 ? 'Every AI connection has used its monthly token budget.' : 'No AI connection available.');
    }

    private function record(array $c, string $feature, bool $ok, array $json, int $ms, ?string $error): void
    {
        $u = (array) ($json['usage'] ?? []);
        $prompt = (int) ($u['prompt_tokens'] ?? $u['input_tokens'] ?? 0);
        $completion = (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? 0);
        AiUsage::create([
            'ai_provider_id' => $c['id'], 'provider' => mb_substr($c['label'], 0, 80), 'model' => mb_substr($c['model'], 0, 120),
            'user_id' => PlannerUserContext::id() ?? auth()->id(), 'feature' => mb_substr($feature, 0, 40),
            'prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => (int) ($u['total_tokens'] ?? ($prompt + $completion)),
            'success' => $ok, 'latency_ms' => $ms, 'error' => $error ? mb_substr($error, 0, 200) : null,
            'request_id' => Context::get('request_id'), 'created_at' => now(),
        ]);
    }
}
