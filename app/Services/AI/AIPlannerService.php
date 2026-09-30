<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\PlanLimitReached;
use App\Models\AiInteraction;
use App\Models\Task;
use App\Models\User;
use App\Services\Analytics\ProductEvents;
use App\Services\Billing\Entitlements;
use App\Services\Planner\RecommendationService;
use App\Support\PlannerUserContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class AIPlannerService
{
    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @param array $context  free-form planner context (e.g. ['focus'=>...]) sent to the model
     * @param int|null $userId planner owner; defaults to PlannerUserContext (Telegram) then Auth (web).
     *                         Only this user's tasks are sent to the AI provider when an owner is known.
     * @throws PlanLimitReached when AI is disabled for the user or the monthly allowance is used up
     */
    public function recommend(array $context = [], ?int $userId = null): array
    {
        $userId ??= PlannerUserContext::id() ?? (Auth::id() !== null ? (int) Auth::id() : null);
        $user = $userId !== null ? User::query()->find($userId) : null;

        if ($user) {
            if ($user->preference('ai_enabled') === false) {
                throw new PlanLimitReached('ai_disabled');
            }
            $this->entitlements->ensureFeature($user, 'ai_planner');
            $this->entitlements->consume($user, 'ai_requests');
        }

        try {
            $result = $this->ask($context, $userId, $user);
        } catch (\Throwable $e) {
            if ($user) {
                $this->entitlements->refund($user, 'ai_requests');
            }
            throw $e;
        }
        if ($user) {
            ProductEvents::record($user, ProductEvents::FIRST_AI_REQUEST, [], true);
        }
        return $result;
    }

    private function ask(array $context, ?int $userId, ?User $user): array
    {
        $tz = $user?->preferredTimezone() ?? (string) config('app.timezone');
        $language = $user ? self::responseLanguage($user) : 'Persian';
        $data = [
            'now' => now($tz)->toIso8601String(),
            'timezone' => $tz,
            'recommendations' => $this->recommendations->build($userId),
            'open_tasks' => Task::query()->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
                ->whereNotIn('status', ['completed', 'cancelled'])->orderByDesc('importance')->limit(50)
                ->get(['id', 'title', 'status', 'priority', 'importance', 'estimated_minutes', 'deadline', 'planned_start', 'planned_end'])->toArray(),
            'context' => $context,
        ];
        $provider = AIProviderFactory::make();
        $response = $provider->chat([
            ['role' => 'system', 'content' => 'You are Haman Planner planning assistant. Use only supplied planner data. Do not invent tasks, IDs, dates, or facts. Return JSON with summary, risks, recommendations. Recommendations are proposals only; never claim a mutation occurred. Write all text values in '.$language.'.'],
            ['role' => 'user', 'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ], ['temperature' => 0, 'response_format' => ['type' => 'json_object'], '_feature' => 'recommendations']);
        $raw = $response['choices'][0]['message']['content'] ?? '{}';
        $value = json_decode((string) $raw, true);
        $valid = is_array($value);
        AiInteraction::create([
            'user_id' => $userId,
            'provider' => AIProviderFactory::lastUsed()['provider'],
            'model' => AIProviderFactory::lastUsed()['model'],
            'intent' => 'AI_PLANNER',
            'input_hash' => hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'input_payload' => $context,
            'output_payload' => $valid ? $value : ['raw' => $raw],
            'confidence' => null,
            'status' => $valid ? 'completed' : 'invalid',
        ]);
        if (!$valid) {
            throw new RuntimeException('AI planner returned invalid JSON.');
        }
        return ['generated_at' => now()->toIso8601String(), 'grounded' => true, 'data_source' => 'stored_planner_data', 'result' => $value];
    }

    /** Language the model should answer in, from the user's AI preference or interface language. */
    public static function responseLanguage(User $user): string
    {
        $pref = (string) $user->preference('ai_response_language');
        $code = in_array($pref, ['fa', 'en'], true) ? $pref : $user->preferredLocale();
        return $code === 'en' ? 'English' : 'Persian';
    }
}
