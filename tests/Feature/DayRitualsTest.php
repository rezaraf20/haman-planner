<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\DayRitual;
use App\Models\ExecutionLog;
use App\Models\Task;
use App\Models\User;
use App\Services\Planner\WeeklyReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DayRitualsTest extends TestCase
{
    use RefreshDatabase;

    private User $u;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai.api_key' => null]);
        $this->travelTo(CarbonImmutable::parse('2025-11-12 06:30:00', 'UTC')); // Wednesday 10:00 Tehran (a fa working day)
        $this->u = $this->user('u@example.com');
        $this->h = $this->auth($this->u);
    }

    private function user(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
    }

    private function auth(User $u): array
    {
        $plain = 'rt-'.$u->id.str_repeat('r', 40);
        ApiToken::create(['user_id' => $u->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function api(string $m, string $url, array $data = [], ?array $h = null)
    {
        return $this->withHeaders($h ?? $this->h)->json($m, '/api'.$url, $data);
    }

    private function task(string $title, array $a = []): Task
    {
        return Task::create($a + ['user_id' => $this->u->id, 'title' => $title]);
    }

    public function test_morning_planning_moves_only_what_the_user_picks_and_never_touches_deadlines(): void
    {
        $late = $this->task('Late report', ['deadline' => '2025-11-10 12:00:00', 'estimated_minutes' => 60]);
        $slipped = $this->task('Slipped', ['planned_start' => '2025-11-11 09:00:00', 'planned_end' => '2025-11-11 10:30:00']);
        $later = $this->task('Later', ['planned_start' => '2025-11-11 14:00:00']);
        $today = $this->task('Today', ['deadline' => '2025-11-12 17:00:00', 'priority' => 'p1', 'estimated_minutes' => 30]);
        $this->task('Done before', ['status' => 'completed', 'completed_at' => '2025-11-11 11:00:00']);

        $m = $this->api('GET', '/rituals/morning')->assertOk();
        $this->assertSame('2025-11-12', $m->json('date'));
        $this->assertEqualsCanonicalizing([$late->id, $slipped->id, $later->id], array_column($m->json('leftovers'), 'id'));
        $this->assertContains($today->id, array_column($m->json('candidates'), 'id'));
        $this->assertSame(1, $m->json('yesterday.completed'));
        $this->assertTrue($m->json('capacity.working'));
        $this->assertSame(30, $m->json('capacity.committed_minutes')); // only work due or planned today
        $this->assertSame('morning', $this->api('GET', '/planning/today')->json('ritual.prompt'));

        $this->api('POST', '/rituals/morning', [
            'energy' => 4, 'intention' => '  Finish the <b>report</b> ',
            'top_task_ids' => [$today->id, $late->id],
            'moves' => [
                ['task_id' => $late->id, 'action' => 'today'],
                ['task_id' => $slipped->id, 'action' => 'tomorrow'],
                ['task_id' => $later->id, 'action' => 'keep'],
            ],
        ])->assertOk();

        $late->refresh(); $slipped->refresh(); $later->refresh();
        $this->assertSame('2025-11-10 12:00:00', $late->deadline->format('Y-m-d H:i:s'), 'deadline unchanged');
        $this->assertSame('2025-11-12 09:00', $late->planned_start->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
        $this->assertSame('2025-11-13 09:00', $slipped->planned_start->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
        $this->assertSame(90, (int) $slipped->planned_start->diffInMinutes($slipped->planned_end), 'planned length kept');
        $this->assertSame('2025-11-11 14:00', $later->planned_start->setTimezone('Asia/Tehran')->format('Y-m-d H:i'), 'keep = untouched');
        $this->assertSame(1, ActivityLog::where('action', 'rescheduled')->where('entity_id', $slipped->id)->count());

        $r = DayRitual::firstOrFail();
        $this->assertSame([$today->id, $late->id], $r->top_task_ids);
        $this->assertSame('Finish the report', $r->intention);
        $today_ = $this->api('GET', '/planning/today')->json('ritual');
        $this->assertSame([$today->id, $late->id], array_column($today_['top'], 'id'));
        $this->assertNull($today_['prompt']);

        // Saving again updates the same record.
        $this->api('POST', '/rituals/morning', ['energy' => 2, 'top_task_ids' => [$today->id]])->assertOk();
        $this->assertSame(1, DayRitual::count());
        $this->assertSame(2, DayRitual::first()->energy);
    }

    public function test_morning_input_is_validated_and_owned(): void
    {
        $mine = $this->task('Mine');
        $other = $this->user('o@example.com');
        $theirs = Task::create(['user_id' => $other->id, 'title' => 'Theirs']);
        $done = $this->task('Done', ['status' => 'completed', 'completed_at' => now()]);

        $this->api('POST', '/rituals/morning', ['top_task_ids' => [$theirs->id]])->assertStatus(422);
        $this->api('POST', '/rituals/morning', ['top_task_ids' => [$done->id]])->assertStatus(422);
        $this->api('POST', '/rituals/morning', ['top_task_ids' => [1, 2, 3, 4]])->assertStatus(422);
        $this->api('POST', '/rituals/morning', ['moves' => [['task_id' => $theirs->id, 'action' => 'today']]])->assertStatus(422);
        $this->api('POST', '/rituals/morning', ['moves' => [['task_id' => $mine->id, 'action' => 'date', 'date' => '2025-11-01']]])->assertStatus(422);
        $this->api('POST', '/rituals/morning', ['energy' => 9])->assertStatus(422);
        $this->assertSame(0, DayRitual::count(), 'nothing saved on invalid input');
        $this->assertNull($theirs->fresh()->planned_start);
        $this->assertSame([], $this->api('GET', '/rituals', [], $this->auth($other))->json('data'));
    }

    public function test_evening_shutdown_records_reasons_and_feeds_tomorrow_morning(): void
    {
        $a = $this->task('A', ['deadline' => '2025-11-12 15:00:00']);
        $b = $this->task('B', ['planned_start' => '2025-11-12 11:00:00']);
        $c = $this->task('C');
        $this->api('POST', '/rituals/morning', ['top_task_ids' => [$a->id, $c->id]])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2025-11-12 13:00:00', 'UTC')); // 16:30 Tehran, last hour of the workday
        $a->update(['status' => 'completed', 'completed_at' => now()]);
        ExecutionLog::create(['user_id' => $this->u->id, 'task_id' => $a->id, 'started_at' => '2025-11-12 10:00:00', 'ended_at' => '2025-11-12 10:50:00', 'duration_minutes' => 50]);
        $this->assertSame('evening', $this->api('GET', '/planning/today')->json('ritual.prompt'));

        $e = $this->api('GET', '/rituals/evening')->assertOk();
        $this->assertSame(['A'], array_column($e->json('completed'), 'title'));
        $this->assertEqualsCanonicalizing([$b->id, $c->id], array_column($e->json('unfinished'), 'id'));
        $this->assertSame(['completed' => 1, 'logged_minutes' => 50, 'top_total' => 2, 'top_done' => 1],
            array_intersect_key($e->json('summary'), array_flip(['completed', 'logged_minutes', 'top_total', 'top_done'])));

        $this->api('POST', '/rituals/evening', [
            'mood' => 4, 'wins' => 'Shipped A', 'tomorrow_task_ids' => [$b->id],
            'summary' => ['completed' => 99], // ignored: the summary is always computed by the server
            'moves' => [['task_id' => $b->id, 'action' => 'tomorrow', 'reason' => 'no_time'], ['task_id' => $c->id, 'action' => 'keep', 'reason' => 'too_difficult']],
        ])->assertOk();

        $ev = DayRitual::where('kind', 'evening')->firstOrFail();
        $this->assertSame(1, $ev->summary['completed']);
        $this->assertSame('no_time', $b->fresh()->failure_reason);
        $this->assertSame('too_difficult', $c->fresh()->failure_reason);
        $this->assertNull($c->fresh()->planned_start);
        $this->assertSame(2, ActivityLog::where('action', 'failure_logged')->count());
        $this->assertNull($this->api('GET', '/planning/today')->json('ritual.prompt'));

        // Next morning: last night's pick comes first.
        $this->travelTo(CarbonImmutable::parse('2025-11-13 05:00:00', 'UTC'));
        $m = $this->api('GET', '/rituals/morning')->assertOk();
        $this->assertSame([$b->id], $m->json('from_yesterday'));
        $this->assertSame($b->id, $m->json('suggested_top.0'));
    }

    public function test_skip_and_settings_silence_prompts_without_overwriting(): void
    {
        $this->api('POST', '/rituals/morning', ['energy' => 3])->assertOk();
        $this->api('POST', '/rituals/morning/skip')->assertOk()->assertJsonPath('status', 'completed');
        $this->api('POST', '/rituals/evening/skip')->assertOk()->assertJsonPath('status', 'skipped');
        $this->api('POST', '/rituals/lunch/skip')->assertNotFound();

        $this->travelTo(CarbonImmutable::parse('2025-11-13 05:30:00', 'UTC')); // Thursday 09:00 Tehran: not a fa working day
        $this->assertNull($this->api('GET', '/planning/today')->json('ritual.prompt'));

        $this->travelTo(CarbonImmutable::parse('2025-11-15 05:30:00', 'UTC')); // Saturday 09:00 Tehran: working day
        $this->assertSame('morning', $this->api('GET', '/planning/today')->json('ritual.prompt'));
        $this->u->update(['preferences' => ['rituals_enabled' => false]]);
        $this->assertNull($this->api('GET', '/planning/today')->json('ritual.prompt'));
        $this->assertFalse($this->api('GET', '/planning/today')->json('ritual.enabled'));
    }

    public function test_weekly_review_counts_rituals_and_keeps_reflections_away_from_ai(): void
    {
        // Week of Sat 2025-11-08 (fa). Three mornings with 2 picks each; only 1 of 6 finished the same day.
        foreach (['2025-11-08', '2025-11-09', '2025-11-10'] as $i => $date) {
            $t1 = $this->task('T'.$i.'a');
            $t2 = $this->task('T'.$i.'b');
            if ($i === 0) $t1->update(['status' => 'completed', 'completed_at' => $date.' 12:00:00']);
            if ($i === 1) $t2->update(['status' => 'completed', 'completed_at' => '2025-11-11 12:00:00']); // next day: does not count
            DayRitual::create(['user_id' => $this->u->id, 'ritual_date' => $date, 'kind' => 'morning', 'energy' => 3 + $i % 2, 'top_task_ids' => [$t1->id, $t2->id], 'completed_at' => now()]);
        }
        DayRitual::create(['user_id' => $this->u->id, 'ritual_date' => '2025-11-09', 'kind' => 'evening', 'mood' => 5, 'wins' => 'Private win text', 'completed_at' => now()]);
        DayRitual::create(['user_id' => $this->u->id, 'ritual_date' => '2025-11-10', 'kind' => 'evening', 'status' => 'skipped']);
        DayRitual::create(['user_id' => $this->user('o@example.com')->id, 'ritual_date' => '2025-11-09', 'kind' => 'morning', 'completed_at' => now()]);

        $svc = app(WeeklyReviewService::class);
        $review = $svc->generate($this->u, CarbonImmutable::parse('2025-11-08', 'Asia/Tehran'), false);
        $r = $review->metrics_json['rituals'];
        $this->assertSame(3, $r['mornings']);
        $this->assertSame(1, $r['evenings']);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(6, $r['top3_picked']);
        $this->assertSame(1, $r['top3_done']);
        $this->assertSame(17, $r['top3_rate']);
        $this->assertSame(5.0, (float) $r['avg_mood']);
        $this->assertSame([['date' => '2025-11-09', 'text' => 'Private win text']], $r['wins']);
        $this->assertContains('smaller_top3', array_column($review->actions_json, 'key'));

        $forAi = (new \ReflectionMethod($svc, 'forAi'))->invoke($svc, $review->metrics_json);
        $this->assertStringNotContainsString('Private win text', json_encode($forAi));
        $this->assertSame(1, $forAi['rituals']['wins_count']);
    }
}
