<?php
declare(strict_types=1);

namespace App\Services\AI\Drivers;

use App\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAICompatibleProvider implements AIProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model
    ) {}

    public function chat(array $messages, array $options = []): array
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(60)
            ->retry(3, 1000, throw: false)
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', array_merge([
                'model' => $this->model,
                'messages' => $messages,
            ], $options));

        if ($response->failed()) {
            throw new RuntimeException('AI provider request failed: '.$response->status());
        }

        return $response->json();
    }

    public function parseIntent(string $input, array $context = []): array
    {
        $system = <<<'PROMPT'
Return exactly one JSON object.
Schema:
{"intent":"INTENT","confidence":0.0,"arguments":{},"requires_confirmation":true}
Allowed intents:
CREATE_GOAL, UPDATE_GOAL, CREATE_PROJECT, UPDATE_PROJECT, CREATE_MILESTONE, UPDATE_MILESTONE,
CREATE_TASK, UPDATE_TASK, COMPLETE_TASK, DEFER_TASK, CANCEL_TASK, SCHEDULE_TASK, RESCHEDULE_TASK,
ADD_REMINDER, LOG_TIME, LOG_PROGRESS, LOG_BLOCKER, LOG_FAILURE,
DAILY_REVIEW, WEEKLY_REVIEW, QUERY_PLAN, QUERY_PROGRESS, QUERY_REPORT, QUERY_GOAL, UNKNOWN.
Never invent database IDs. Use titles/names when the user provides them.
Mutation intents require confirmation. Queries/reviews do not.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode([
                'input' => $input,
                'context' => $context,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ];

        $options = ['temperature' => 0];

        try {
            $response = $this->chat($messages, $options + (filter_var((string) env('AI_JSON_RESPONSE_FORMAT', 'true'), FILTER_VALIDATE_BOOLEAN) ? ['response_format' => ['type' => 'json_object']] : []));
        } catch (\Throwable) {
            $response = $this->chat($messages, $options);
        }

        $raw = trim((string) ($response['choices'][0]['message']['content'] ?? '{}'));
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        }
        $value = json_decode(trim($raw), true);

        if (!is_array($value)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $value = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }

        if (!is_array($value)) {
            return ['intent' => 'UNKNOWN', 'arguments' => [], 'confidence' => 0, 'requires_confirmation' => false];
        }

        $value['intent'] = strtoupper((string) ($value['intent'] ?? 'UNKNOWN'));
        $allowed = ['CREATE_GOAL','UPDATE_GOAL','CREATE_PROJECT','UPDATE_PROJECT','CREATE_MILESTONE','UPDATE_MILESTONE','CREATE_TASK','UPDATE_TASK','COMPLETE_TASK','DEFER_TASK','CANCEL_TASK','SCHEDULE_TASK','RESCHEDULE_TASK','ADD_REMINDER','LOG_TIME','LOG_PROGRESS','LOG_BLOCKER','LOG_FAILURE','DAILY_REVIEW','WEEKLY_REVIEW','QUERY_PLAN','QUERY_PROGRESS','QUERY_REPORT','QUERY_GOAL'];
        if (!in_array($value['intent'], $allowed, true)) $value['intent'] = 'UNKNOWN';
        $value['arguments'] = is_array($value['arguments'] ?? null)
            ? $value['arguments']
            : (is_array($value['entities'] ?? null) ? $value['entities'] : []);
        $value['confidence'] = max(0, min(1, (float) ($value['confidence'] ?? 0)));
        $value['requires_confirmation'] = in_array($value['intent'], [
            'CREATE_GOAL','UPDATE_GOAL','CREATE_PROJECT','UPDATE_PROJECT','CREATE_MILESTONE','UPDATE_MILESTONE',
            'CREATE_TASK','UPDATE_TASK','COMPLETE_TASK','DEFER_TASK','CANCEL_TASK','SCHEDULE_TASK','RESCHEDULE_TASK',
            'ADD_REMINDER','LOG_TIME','LOG_PROGRESS','LOG_BLOCKER','LOG_FAILURE',
        ], true);

        return $value;
    }
}
