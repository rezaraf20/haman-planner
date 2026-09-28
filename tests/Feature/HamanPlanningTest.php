<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AiInteraction;
use App\Models\ApiToken;
use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\PlanProposal;
use App\Models\Project;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\PlanningIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HamanPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00', 'UTC')); // Monday
        config(['services.ai.api_key' => null]);
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'en', 'timezone' => 'UTC',
            'preferences' => ['work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '17:00', 'planning_buffer_percent' => 20, 'break_minutes' => 0]]);
        $plain = str_repeat('p', 48);
        ApiToken::create(['user_id' => $this->user->id, 'token_hash' => hash('sha256', $plain), 'name' => 't', 'token_prefix' => 'pppp']);
        $this->h = ['Authorization' => 'Bearer '.$plain];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(string $title, array $extra = []): Task
    {
        return Task::create(array_merge(['user_id' => $this->user->id, 'title' => $title, 'status' => 'ready', 'priority' => 'p2', 'estimated_minutes' => 60], $extra));
    }

    private function action(array $proposal, int $taskId): ?array
    {
        return collect($proposal['actions'])->firstWhere('task_id', $taskId);
    }

    public function test_plan_my_day_is_grounded_respects_dependencies_busy_time_and_changes_nothing(): void
    {
        $urgent = $this->task('Ship release', ['priority' => 'p0', 'deadline' => '2026-10-05 16:00:00', 'estimated_minutes' => 120]);
        $normal = $this->task('Tidy docs');
        $prereq = $this->task('Get API key', ['estimated_minutes' => 30]);
        $blocked = $this->task('Integrate API');
        TaskDependency::create(['user_id' => $this->user->id, 'task_id' => $blocked->id, 'depends_on_task_id' => $prereq->id, 'type' => 'requires']);
        $conn = CalendarConnection::create(['user_id' => $this->user->id, 'provider' => 'google']);
        CalendarEvent::create(['user_id' => $this->user->id, 'calendar_connection_id' => $conn->id, 'provider_event_id' => 'e', 'title' => 'Standup', 'starts_at' => '2026-10-05 09:00:00+00', 'ends_at' => '2026-10-05 09:30:00+00']);

        $p = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['message' => 'plan my day'])->assertCreated()->json();
        $this->assertSame('day', $p['kind']);
        $this->assertFalse($p['ai_used']);
        $first = $this->action($p, $urgent->id);
        $this->assertSame('schedule', $first['type']);
        $this->assertSame('2026-10-05T09:30:00+00:00', Carbon::parse($first['to']['starts_at'])->utc()->toIso8601String(), 'after the busy standup');
        $this->assertContains('priority', array_column($first['reasons'], 'code'));
        $this->assertContains('due_today', array_column($first['reasons'], 'code'));
        $this->assertStringContainsString('Ship release', $first['text']);
        $this->assertNotEmpty($first['reasons_text']);
        $this->assertNotNull($this->action($p, $normal->id));
        $this->assertNotNull($this->action($p, $prereq->id));
        $this->assertNull($this->action($p, $blocked->id), 'blocked task is never scheduled');

        // Proposals change nothing until applied.
        $this->assertNull($urgent->fresh()->planned_start);
        $this->assertSame(0, ScheduleBlock::count());
    }

    public function test_capacity_buffer_and_overload_are_respected(): void
    {
        // 480 min window, 20% buffer → 384 usable. 7 × 60 min only 6 fit.
        foreach (range(1, 7) as $i) $this->task('Task '.$i);
        $p = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['kind' => 'day'])->json();
        $this->assertCount(6, array_filter($p['actions'], fn ($a) => $a['type'] === 'schedule'));

        // An already overloaded week: headline + recovery buffer + "fix" moves flexible work off it.
        foreach (range(0, 4) as $d) {
            $t = $this->task('Big '.$d, ['estimated_minutes' => 480]);
            ScheduleBlock::create(['user_id' => $this->user->id, 'task_id' => $t->id, 'starts_at' => Carbon::parse('2026-10-05 09:00', 'UTC')->addDays($d), 'ends_at' => Carbon::parse('2026-10-05 17:00', 'UTC')->addDays($d)]);
        }
        $fix = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['message' => 'I fell behind, fix my schedule'])->json();
        $this->assertSame('fix', $fix['kind']);
        $this->assertStringContainsString('overloaded by approximately', $fix['headline']);
        $this->assertGreaterThan(0, $fix['metrics']['overload_minutes_before']);
    }

    public function test_apply_only_selected_actions_logs_ai_activity_and_cannot_be_reapplied(): void
    {
        $a = $this->task('Alpha', ['priority' => 'p0']);
        $b = $this->task('Beta');
        $p = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['kind' => 'day'])->json();
        $keyA = $this->action($p, $a->id)['key'];

        $res = $this->withHeaders($this->h)->postJson('/api/planning/proposals/'.$p['id'].'/apply', ['actions' => [$keyA, 'a999']])->assertOk()->json();
        $this->assertSame('applied', $res['results'][$keyA]['status']);
        $this->assertSame('unknown_action', $res['results']['a999']['reason']);
        $this->assertNotNull($a->fresh()->planned_start);
        $this->assertNull($b->fresh()->planned_start, 'unselected action not applied');
        $this->assertSame(1, ScheduleBlock::where('task_id', $a->id)->count());
        $log = ActivityLog::withoutGlobalScopes()->where('entity_id', (string) $a->id)->where('action', 'scheduled')->first();
        $this->assertSame('ai', $log->actor_type);

        $this->withHeaders($this->h)->postJson('/api/planning/proposals/'.$p['id'].'/apply', ['actions' => [$keyA]])->assertStatus(409);
    }

    public function test_stale_actions_are_skipped_and_proposals_are_private(): void
    {
        $a = $this->task('Alpha');
        $p = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['kind' => 'day'])->json();
        $a->update(['status' => 'completed']);
        $res = $this->withHeaders($this->h)->postJson('/api/planning/proposals/'.$p['id'].'/apply', ['actions' => [$this->action($p, $a->id)['key']]])->json();
        $this->assertSame('task_changed', array_values($res['results'])[0]['reason']);

        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'secret123', 'is_active' => true]);
        $theirs = PlanProposal::create(['user_id' => $other->id, 'kind' => 'day', 'period_start' => '2026-10-05', 'period_end' => '2026-10-05', 'actions' => [], 'expires_at' => now()->addDay()]);
        $this->withHeaders($this->h)->getJson('/api/planning/proposals/'.$theirs->id)->assertNotFound();
        $this->withHeaders($this->h)->postJson('/api/planning/proposals/'.$theirs->id.'/apply', ['actions' => ['a1']])->assertNotFound();
        $this->assertNotContains('Secret', array_column($p['actions'], 'title'));
    }

    public function test_history_adjusts_estimates_only_with_enough_data(): void
    {
        $insight = $this->withHeaders($this->h)->getJson('/api/planning/insights')->assertOk()->json();
        $this->assertNotNull($insight['not_enough_history']);
        $this->assertSame([], $insight['plan_vs_actual']['insights']);

        $project = Project::create(['user_id' => $this->user->id, 'title' => 'Website']);
        foreach (range(1, 6) as $i) {
            $this->task('Done '.$i, ['status' => 'completed', 'completed_at' => now()->subDays($i), 'estimated_minutes' => 60, 'actual_minutes' => 90 + $i, 'project_id' => $project->id]);
        }
        $insight = $this->withHeaders($this->h)->getJson('/api/planning/insights')->json();
        $this->assertNull($insight['not_enough_history']);
        $keys = array_column($insight['plan_vs_actual']['insights'], 'key');
        $this->assertContains('estimate_under', $keys);
        $this->assertContains('project_overruns', $keys);
        $this->assertStringContainsString('Website', implode(' ', $insight['plan_vs_actual']['insights_text']));
        $this->assertStringContainsString('based on 6 items', implode(' ', $insight['plan_vs_actual']['insights_text']));

        $t = $this->task('New work', ['estimated_minutes' => 60]);
        $p = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['kind' => 'day'])->json();
        $a = $this->action($p, $t->id);
        $this->assertSame(95, $a['minutes']);
        $this->assertContains('history_adjusted', array_column($a['reasons'], 'code'));
    }

    public function test_failure_patterns_come_only_from_recorded_reasons(): void
    {
        $r = $this->withHeaders($this->h)->getJson('/api/planning/insights')->json('failure_patterns');
        $this->assertFalse($r['enough_data']);
        $this->assertSame([], $r['top_reasons']);

        foreach (['no_time', 'no_time', 'unclear_task', 'no_time'] as $reason) {
            ActivityLog::create(['user_id' => $this->user->id, 'actor_type' => 'user', 'action' => 'failure_logged', 'entity_type' => Task::class, 'entity_id' => '1', 'after_json' => ['failure_reason' => $reason], 'created_at' => now()->subDays(2)]);
        }
        $this->task('Missed', ['deadline' => now()->subDay(), 'failure_reason' => 'no_time']);
        $this->task('Missed 2', ['deadline' => now()->subDays(2), 'failure_reason' => 'no_time']);
        $this->task('Missed 3', ['deadline' => now()->subDays(3), 'failure_reason' => 'no_time']);
        $r = $this->withHeaders($this->h)->getJson('/api/planning/insights')->json('failure_patterns');
        $this->assertTrue($r['enough_data']);
        $this->assertSame(['no_time', 'unclear_task'], array_column($r['top_reasons'], 'code'));
        $this->assertSame(75, $r['top_reasons'][0]['share']);
        $this->assertSame('no_time', $r['missed']['most_common']['code']);
    }

    public function test_ai_can_only_annotate_existing_actions(): void
    {
        config(['services.ai.provider' => 'openai', 'services.ai.api_key' => 'k', 'services.ai.base_url' => 'https://ai.test/v1', 'services.ai.model' => 'm']);
        $a = $this->task('Alpha', ['priority' => 'p0']);
        $b = $this->task('Beta');
        Http::fake(['https://ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'summary' => 'Start with #'.$a->id.' then #999999 which does not exist.',
            'notes' => ['a1' => 'Do this first.', 'a77' => 'Hallucinated action'],
            'not_recommended' => ['a2', 'zzz'],
            'warnings' => ['Deadline is tight'],
            'actions' => [['type' => 'schedule', 'task_id' => 999999]],
        ])]]]])]);

        $p = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['kind' => 'day'])->assertCreated()->json();
        $this->assertTrue($p['ai_used']);
        $this->assertSame(['a1', 'a2'], array_column($p['actions'], 'key'), 'AI cannot add actions');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($p['actions'], 'task_id'));
        $this->assertSame('Do this first.', $p['actions'][0]['ai_note']);
        $this->assertFalse($p['actions'][1]['selected'], 'AI may advise against an action');
        $this->assertStringContainsString('#'.$a->id, $p['summary']);
        $this->assertStringNotContainsString('999999', $p['summary']);
        $this->assertSame(['Deadline is tight'], $p['ai_warnings']);
        $this->assertSame(1, app(Entitlements::class)->used($this->user, 'ai_requests'));
        $this->assertTrue(AiInteraction::withoutGlobalScopes()->where('intent', 'PLAN_DAY')->whereNotNull('request_id')->exists());
        $this->assertNull($a->fresh()->planned_start, 'nothing applied');
    }

    public function test_ai_failure_falls_back_to_rules_and_refunds(): void
    {
        config(['services.ai.provider' => 'openai', 'services.ai.api_key' => 'k', 'services.ai.base_url' => 'https://ai.test/v1', 'services.ai.model' => 'm']);
        Http::fake(['https://ai.test/*' => Http::response('down', 500)]);
        $this->task('Alpha');
        $p = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['kind' => 'day'])->assertCreated()->json();
        $this->assertFalse($p['ai_used']);
        $this->assertNotNull($p['ai_note']);
        $this->assertCount(1, $p['actions']);
        $this->assertSame(0, app(Entitlements::class)->used($this->user, 'ai_requests'));
    }

    public function test_what_now_ranks_with_explanations(): void
    {
        $low = $this->task('Low', ['priority' => 'p3']);
        $over = $this->task('Overdue report', ['priority' => 'p1', 'deadline' => now()->subDay()]);
        $pre = $this->task('Prerequisite');
        $blocked = $this->task('Blocked');
        TaskDependency::create(['user_id' => $this->user->id, 'task_id' => $blocked->id, 'depends_on_task_id' => $pre->id, 'type' => 'requires']);
        $r = $this->withHeaders($this->h)->getJson('/api/planning/what-now')->assertOk()->json();
        $this->assertSame($over->id, $r['ranked'][0]['id']);
        $this->assertStringContainsString('overdue', $r['ranked'][0]['reasons_text'][0]);
        $this->assertContains('unblocks', array_column(collect($r['ranked'])->firstWhere('id', $pre->id)['reasons'], 'code'));
        $this->assertSame([$blocked->id], array_column($r['blocked'], 'id'));
        $this->assertSame($low->id, end($r['ranked'])['id']);
        // The same through the free-text entry point.
        $this->assertSame('now', $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['message' => 'الان روی چی کار کنم؟'])->json('kind'));
    }

    public function test_intent_detection(): void
    {
        $this->assertSame('day', PlanningIntent::detect('برنامه امروزمو بر اساس کارهایی که دارم بچین.'));
        $this->assertSame('next_week', PlanningIntent::detect('هفته آینده رو برام برنامه‌ریزی کن.'));
        $this->assertSame('fix', PlanningIntent::detect('این هفته خیلی عقب افتادم، برنامه‌ام رو درست کن.'));
        $this->assertSame('now', PlanningIntent::detect('الان روی چی کار کنم؟'));
        $this->assertSame('week', PlanningIntent::detect('Plan my week please'));
        $this->assertNull(PlanningIntent::detect('Tell me a joke'));
    }

    public function test_weekly_review_keeps_raw_metrics(): void
    {
        $this->task('Done', ['status' => 'completed', 'completed_at' => '2026-09-30 10:00:00']);
        $this->task('Missed', ['deadline' => '2026-10-01 10:00:00', 'failure_reason' => 'no_time']);
        $r = $this->withHeaders($this->h)->postJson('/api/reviews/weekly', ['week_start' => '2026-09-28'])->assertCreated()->json();
        $this->assertSame('2026-09-28', $r['period_start']);
        $this->assertSame(1, $r['metrics']['completed']);
        $this->assertSame(1, $r['metrics']['missed']);
        $this->assertArrayHasKey('planned_minutes', $r['metrics']);
        $this->assertNull($r['ai_summary']);
        $this->assertNotEmpty($r['recommendations_text']);
        $this->withHeaders($this->h)->getJson('/api/reviews/'.$r['id'].'/details')->assertOk();

        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'secret123', 'is_active' => true]);
        $plain = str_repeat('q', 48);
        ApiToken::create(['user_id' => $other->id, 'token_hash' => hash('sha256', $plain), 'name' => 't', 'token_prefix' => 'qqqq']);
        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/api/reviews/'.$r['id'].'/details')->assertNotFound();
    }

    public function test_fix_only_moves_work_off_overloaded_days_and_keeps_block_length(): void
    {
        // Tuesday fits; Wednesday is over capacity (two 4h blocks in a 6.4h usable day).
        $calm = $this->task('Calm day work', ['estimated_minutes' => 30]);
        ScheduleBlock::create(['user_id' => $this->user->id, 'task_id' => $calm->id, 'starts_at' => '2026-10-06 10:00:00+00', 'ends_at' => '2026-10-06 12:00:00+00']);
        $big1 = $this->task('Big one', ['estimated_minutes' => 30, 'priority' => 'p3']);
        $big2 = $this->task('Big two', ['estimated_minutes' => 30, 'priority' => 'p0']);
        ScheduleBlock::create(['user_id' => $this->user->id, 'task_id' => $big1->id, 'starts_at' => '2026-10-07 09:00:00+00', 'ends_at' => '2026-10-07 13:00:00+00']);
        ScheduleBlock::create(['user_id' => $this->user->id, 'task_id' => $big2->id, 'starts_at' => '2026-10-07 13:00:00+00', 'ends_at' => '2026-10-07 17:00:00+00']);

        $p = $this->withHeaders($this->h)->postJson('/api/planning/proposals', ['kind' => 'fix'])->json();
        $this->assertNull($this->action($p, $calm->id), 'work on a day that fits is not moved');
        $moved = collect($p['actions'])->whereIn('task_id', [$big1->id, $big2->id])->where('type', 'move')->first();
        $this->assertNotNull($moved);
        $this->assertSame(240, $moved['minutes'], 'a moved block keeps its real length');
        $this->assertSame(0, Carbon::parse($moved['to']['starts_at'])->minute % 5);
    }
}
