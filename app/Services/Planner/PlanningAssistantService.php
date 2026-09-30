<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Exceptions\PlanLimitReached;
use App\Models\AiInteraction;
use App\Models\PlanProposal;
use App\Models\User;
use App\Services\AI\AIPlannerService;
use App\Services\AI\AIProviderFactory;
use App\Services\Analytics\ProductEvents;
use App\Services\Billing\Entitlements;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Haman AI planning: "plan my day / week", "fix my schedule".
 *
 *   1. SmartReschedulingService builds the proposal from real data (deterministic, always works).
 *   2. If AI is available and allowed, the model receives the grounded context + the proposal and
 *      may only: write a summary, add notes to existing actions, recommend NOT applying some,
 *      and list warnings. It cannot add actions, tasks, ids or times; anything referring to
 *      unknown actions is dropped. If AI is off/unavailable, the proposal is returned as is.
 *   3. The proposal is stored as pending; nothing changes until the user applies it.
 */
final class PlanningAssistantService
{
    public function __construct(
        private readonly SmartReschedulingService $rescheduler,
        private readonly PlanInsightsService $insights,
        private readonly Entitlements $entitlements,
    ) {}

    public function aiAvailable(User $user): bool
    {
        return $user->preference('ai_enabled') !== false
            && $this->entitlements->canUse($user, 'ai_planner')
            && $this->entitlements->canUse($user, 'advanced_ai_planning')
            && \App\Services\AI\AIProviderFactory::available();
    }

    /** AI is set up and the user wants it, but their plan does not include advanced AI planning. */
    public function needsUpgradeForAi(User $user): bool
    {
        return $user->preference('ai_enabled') !== false
            && $this->entitlements->canUse($user, 'ai_planner')
            && !$this->entitlements->canUse($user, 'advanced_ai_planning')
            && \App\Services\AI\AIProviderFactory::available();
    }

    public function generate(User $user, string $kind, ?string $message = null): PlanProposal
    {
        $requestId = (string) Str::uuid();
        $draft = $this->rescheduler->propose($user, $kind);
        $summary = null;
        $aiUsed = false;
        $aiNote = null;

        if ($draft['actions'] !== [] && $this->aiAvailable($user)) {
            try {
                $this->entitlements->consume($user, 'ai_requests');
                try {
                    [$draft, $summary] = $this->withAi($user, $draft, $message, $requestId);
                    $aiUsed = true;
                } catch (\Throwable $e) {
                    $this->entitlements->refund($user, 'ai_requests');
                    Log::warning('Haman AI planning failed; using the rule-based proposal', ['request_id' => $requestId, 'error' => $e->getMessage()]);
                    $aiNote = 'ai_unavailable';
                }
            } catch (PlanLimitReached) {
                $aiNote = 'ai_limit_reached';
            }
        } elseif ($draft['actions'] !== [] && $this->needsUpgradeForAi($user)) {
            $aiNote = 'ai_needs_upgrade';
        }

        $proposal = PlanProposal::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'status' => 'pending',
            'period_start' => $draft['period_start'],
            'period_end' => $draft['period_end'],
            'metrics' => $draft['metrics'] + ['headline' => $draft['headline'], 'ai_note' => $aiNote],
            'actions' => $draft['actions'],
            'summary' => $summary,
            'ai_used' => $aiUsed,
            'request_id' => $requestId,
            'expires_at' => now()->addHours(max(1, (int) config('planner.proposal_ttl_hours', 24))),
        ]);
        ProductEvents::record($user, ProductEvents::FIRST_PLAN_GENERATED, ['kind' => $kind, 'ai' => $aiUsed], true);
        return $proposal;
    }

    /** @return array{0:array,1:?string} */
    private function withAi(User $user, array $draft, ?string $message, string $requestId): array
    {
        $tz = $user->preferredTimezone();
        $language = AIPlannerService::responseLanguage($user);
        $pva = $this->insights->planVsActual($user, 90);
        $patterns = $this->insights->failurePatterns($user, CarbonImmutable::now()->subDays(30), CarbonImmutable::now());
        $context = [
            'now' => CarbonImmutable::now($tz)->toIso8601String(),
            'timezone' => $tz,
            'request' => $message !== null ? mb_substr($message, 0, 500) : null,
            'period' => ['kind' => $draft['kind'], 'start' => $draft['period_start'], 'end' => $draft['period_end']],
            'capacity' => $draft['metrics'],
            'history' => $pva['enough_history']
                ? ['estimate_ratio' => $pva['estimation']['ratio'], 'sample' => $pva['estimation']['sample'], 'deadline_reliability_percent' => $pva['metrics']['deadline_reliability_percent'], 'insights' => array_map(fn ($i) => $i['key'], $pva['insights'])]
                : 'unavailable: not enough history',
            'failure_patterns' => $patterns['enough_data'] ? $patterns['top_reasons'] : 'unavailable: not enough recorded blockers',
            // Only what the proposal is about — the AI never receives the whole database.
            'proposed_actions' => array_map(fn ($a) => [
                'key' => $a['key'], 'type' => $a['type'], 'task_id' => $a['task_id'], 'title' => $a['title'], 'minutes' => $a['minutes'],
                'to' => $a['to'], 'from' => $a['from'], 'reasons' => array_column($a['reasons'], 'code'),
            ], $draft['actions']),
        ];
        $response = AIProviderFactory::make()->chat([
            ['role' => 'system', 'content' => 'You are Haman AI, the planning assistant of Haman Planner. The proposed_actions were computed by the planner from the user\'s real data. '
                .'Use ONLY the supplied data; never invent tasks, ids, times, durations or statistics. If something is marked unavailable, say it is unavailable. '
                .'You may: (1) write "summary": a short plain-language explanation of the plan (max 120 words); (2) "notes": an object mapping existing action keys to one short sentence each; '
                .'(3) "not_recommended": a list of existing action keys you advise against, with the reason in notes; (4) "warnings": up to 3 short strings. '
                .'You cannot add or change actions. Nothing is applied without the user\'s confirmation — never claim that a change was made. '
                .'Return a JSON object with exactly these keys. Write all text in '.$language.'.'],
            ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ], ['temperature' => 0.2, 'response_format' => ['type' => 'json_object'], '_feature' => 'plan_proposal']);

        $raw = (string) ($response['choices'][0]['message']['content'] ?? '');
        $out = json_decode($raw, true);
        AiInteraction::create([
            'user_id' => $user->id, 'provider' => AIProviderFactory::lastUsed()['provider'], 'model' => AIProviderFactory::lastUsed()['model'],
            'intent' => 'PLAN_'.strtoupper($draft['kind']), 'input_hash' => hash('sha256', $requestId),
            'input_payload' => ['kind' => $draft['kind'], 'actions' => count($draft['actions'])], 'output_payload' => is_array($out) ? $out : ['raw' => mb_substr($raw, 0, 2000)],
            'confidence' => null, 'status' => is_array($out) ? 'completed' : 'invalid', 'request_id' => $requestId,
        ]);
        if (!is_array($out)) {
            throw new \RuntimeException('AI returned invalid JSON');
        }

        // Validate: only known action keys; unknown task ids or keys are dropped.
        $known = array_column($draft['actions'], 'key');
        $knownTaskIds = array_filter(array_column($draft['actions'], 'task_id'));
        $clean = fn ($s) => self::stripUnknownIds(mb_substr(trim(strip_tags((string) $s)), 0, 400), $knownTaskIds);
        $notes = [];
        foreach ((array) ($out['notes'] ?? []) as $k => $v) {
            if (in_array((string) $k, $known, true) && is_string($v) && trim($v) !== '') $notes[(string) $k] = $clean($v);
        }
        $avoid = array_values(array_intersect($known, array_map('strval', (array) ($out['not_recommended'] ?? []))));
        foreach ($draft['actions'] as &$a) {
            if (isset($notes[$a['key']])) $a['ai_note'] = $notes[$a['key']];
            if (in_array($a['key'], $avoid, true)) { $a['selected'] = false; $a['ai_advises_against'] = true; }
        }
        unset($a);
        $warnings = array_slice(array_values(array_filter(array_map(fn ($w) => is_string($w) ? $clean($w) : null, (array) ($out['warnings'] ?? [])))), 0, 3);
        $draft['metrics']['ai_warnings'] = $warnings;
        $summary = isset($out['summary']) && is_string($out['summary']) ? self::stripUnknownIds(mb_substr(trim(strip_tags($out['summary'])), 0, 1500), $knownTaskIds) : null;
        return [$draft, $summary];
    }

    /** Remove "#123"-style references to tasks that are not part of the proposal. */
    public static function stripUnknownIds(string $text, array $knownIds): string
    {
        return (string) preg_replace_callback('/#(\d+)/', fn ($m) => in_array((int) $m[1], array_map('intval', $knownIds), true) ? $m[0] : '', $text);
    }

    // ----------------------------------------------------------------- presentation

    public function present(PlanProposal $p, User $user): array
    {
        $loc = $user->preferredLocale();
        $tz = $user->preferredTimezone();
        $m = (array) $p->metrics;
        $headline = $m['headline'] ?? ['key' => 'fits', 'params' => []];
        $hp = $headline['params'] ?? [];
        if (isset($hp['duration'])) $hp['duration'] = SchedulingService::duration((int) $hp['duration'], $loc);
        if (isset($hp['count'])) $hp['count'] = LocalDate::number($hp['count'], $loc);

        return [
            'id' => $p->id, 'kind' => $p->kind, 'status' => $p->status, 'open' => $p->isOpen(), 'ai_used' => $p->ai_used,
            'period_start' => $p->period_start?->toDateString(), 'period_end' => $p->period_end?->toDateString(),
            'headline' => __('planning.headline.'.($headline['key'] === 'overloaded' ? 'overloaded_'.($p->kind === 'day' ? 'day' : 'week') : $headline['key']), $hp, $loc),
            'summary' => $p->summary,
            'ai_note' => isset($m['ai_note']) && $m['ai_note'] ? __('planning.ai_note.'.$m['ai_note'], [], $loc) : null,
            'ai_upgrade_url' => ($m['ai_note'] ?? null) === 'ai_needs_upgrade' ? route('billing.index') : null,
            'ai_warnings' => $m['ai_warnings'] ?? [],
            'metrics' => array_intersect_key($m, array_flip(['working_days', 'usable_minutes', 'scheduled_minutes_before', 'overload_minutes_before', 'proposed_minutes', 'candidates', 'history_used', 'estimate_factor'])),
            'metrics_text' => [
                'usable' => SchedulingService::duration((int) ($m['usable_minutes'] ?? 0), $loc),
                'scheduled' => SchedulingService::duration((int) ($m['scheduled_minutes_before'] ?? 0), $loc),
                'overload' => SchedulingService::duration((int) ($m['overload_minutes_before'] ?? 0), $loc),
                'proposed' => SchedulingService::duration((int) ($m['proposed_minutes'] ?? 0), $loc),
            ],
            'actions' => array_map(fn ($a) => $a + [
                'text' => $this->actionText($a, $loc, $tz),
                'reasons_text' => array_map(fn ($r) => $this->reasonText($r, $loc, $tz), $a['reasons']),
            ], (array) $p->actions),
            'applied_actions' => $p->applied_actions,
            'expires_at' => $p->expires_at?->toIso8601String(),
        ];
    }

    private function when(?array $slot, string $loc, string $tz): array
    {
        if (!$slot) return ['day' => '', 'start' => '', 'end' => ''];
        $s = CarbonImmutable::parse($slot['starts_at'])->setTimezone($tz);
        return ['day' => LocalDate::weekday($s, $loc).' '.LocalDate::short($s, $loc, $tz, false), 'start' => LocalDate::time($s, $loc, $tz), 'end' => LocalDate::time($slot['ends_at'], $loc, $tz)];
    }

    public function actionText(array $a, string $loc, string $tz): string
    {
        $to = $this->when($a['to'] ?? null, $loc, $tz);
        $from = $this->when($a['from'] ?? null, $loc, $tz);
        return (string) __('planning.action.'.$a['type'], [
            'title' => (string) ($a['title'] ?? ''), 'day' => $to['day'], 'start' => $to['start'], 'end' => $to['end'],
            'from_day' => $from['day'], 'from' => $from['start'], 'duration' => SchedulingService::duration((int) $a['minutes'], $loc),
            'parts' => LocalDate::number((int) ($a['parts'] ?? 2), $loc),
        ], $loc);
    }

    public function reasonText(array $r, string $loc, string $tz): string
    {
        $p = $r['params'] ?? [];
        if (isset($p['date'])) $p['date'] = LocalDate::short($p['date'], $loc, $tz, true);
        foreach (['days', 'count', 'value', 'from', 'to'] as $k) {
            if (isset($p[$k]) && is_int($p[$k])) $p[$k] = LocalDate::number($p[$k], $loc);
        }
        if (isset($r['params']['from'], $r['params']['to']) && $r['code'] === 'history_adjusted') {
            $p['from'] = SchedulingService::duration((int) $r['params']['from'], $loc);
            $p['to'] = SchedulingService::duration((int) $r['params']['to'], $loc);
        }
        return (string) __('planning.reason.'.$r['code'], $p, $loc);
    }
}
