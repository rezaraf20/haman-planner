<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ExecutionLog;
use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\HabitEntry;
use App\Models\Task;
use App\Models\User;
use App\Services\Planner\FocusService;
use App\Services\Planner\HabitService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FocusAndHabitsTest extends TestCase
{
    use RefreshDatabase;

    private User $u;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 12 Nov 2025, 10:00 in Tehran (06:30 UTC).
        $this->travelTo(CarbonImmutable::parse('2025-11-12 06:30:00', 'UTC'));
        $this->u = $this->user('u@example.com');
        $this->h = $this->auth($this->u);
    }

    private function user(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
    }

    private function auth(User $u): array
    {
        $plain = 'fh-'.$u->id.str_repeat('f', 40);
        ApiToken::create(['user_id' => $u->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function api(string $m, string $url, array $data = [], ?array $h = null)
    {
        return $this->withHeaders($h ?? $this->h)->json($m, '/api'.$url, $data);
    }

    // ================================================================ focus

    public function test_focus_session_lifecycle_logs_time_once_and_excludes_pauses(): void
    {
        $task = Task::create(['user_id' => $this->u->id, 'title' => 'Write report']);
        $uuid = '7d1c2c4e-1111-4a2b-9c3d-000000000001';
        $s = $this->api('POST', '/focus/start', ['task_id' => $task->id, 'planned_minutes' => 25, 'client_uuid' => $uuid])->assertCreated();
        $id = $s->json('id');

        // Retried start (same uuid) and a second start both return the same session.
        $this->api('POST', '/focus/start', ['task_id' => $task->id, 'planned_minutes' => 25, 'client_uuid' => $uuid])->assertOk()->assertJsonPath('id', $id);
        $this->api('POST', '/focus/start', ['planned_minutes' => 50])->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, FocusSession::count());

        $this->travel(10)->minutes();
        $this->api('POST', "/focus/$id/pause")->assertOk()->assertJsonPath('status', 'paused')->assertJsonPath('elapsed_seconds', 600);
        $this->travel(30)->minutes(); // paused time never counts
        $this->api('GET', '/focus')->assertJsonPath('current.elapsed_seconds', 600);
        $this->api('POST', "/focus/$id/resume")->assertOk()->assertJsonPath('status', 'running');
        $this->travel(10)->minutes();

        $done = $this->api('POST', "/focus/$id/finish", ['note' => 'draft done'])->assertOk();
        $this->assertSame('completed', $done->json('status'));
        $this->assertSame(1200, $done->json('actual_seconds'));
        $this->assertSame(20, (int) $task->fresh()->actual_minutes);
        $this->assertSame(1, ExecutionLog::count());
        $this->assertSame(20, ExecutionLog::first()->duration_minutes);

        // Finishing again changes nothing (no duplicate log).
        $this->api('POST', "/focus/$id/finish")->assertOk();
        $this->assertSame(1, ExecutionLog::count());
        $this->assertSame(20, (int) $task->fresh()->actual_minutes);

        $stats = $this->api('GET', '/focus/stats?days=7')->assertOk();
        $this->assertSame(20, $stats->json('today_minutes'));
        $this->assertSame(1, $stats->json('today_sessions'));
        $this->assertSame(20, $stats->json('average_minutes'));
    }

    public function test_cancelled_short_and_free_sessions_log_nothing(): void
    {
        $task = Task::create(['user_id' => $this->u->id, 'title' => 'T']);
        $id = $this->api('POST', '/focus/start', ['task_id' => $task->id, 'planned_minutes' => 25])->json('id');
        $this->travel(15)->minutes();
        $this->api('POST', "/focus/$id/cancel")->assertJsonPath('status', 'cancelled');

        $id = $this->api('POST', '/focus/start', ['task_id' => $task->id, 'planned_minutes' => 25])->json('id');
        $this->travel(30)->seconds();
        $this->api('POST', "/focus/$id/finish")->assertJsonPath('status', 'completed'); // under a minute: a mis-tap

        $id = $this->api('POST', '/focus/start', ['planned_minutes' => 25])->json('id');
        $this->travel(25)->minutes();
        $this->api('POST', "/focus/$id/finish")->assertJsonPath('actual_seconds', 1500);

        $this->assertSame(0, ExecutionLog::count());
        $this->assertSame(0, (int) $task->fresh()->actual_minutes);
        $this->assertSame(25, $this->api('GET', '/focus/stats')->json('today_minutes'), 'free focus still counts as focus time');
    }

    public function test_stale_sessions_are_closed_and_other_users_cannot_touch_a_session(): void
    {
        $id = $this->api('POST', '/focus/start', ['planned_minutes' => 25])->json('id');
        $other = $this->user('o@example.com');
        $this->api('POST', "/focus/$id/finish", [], $this->auth($other))->assertNotFound();

        $this->travel(13)->hours();
        $this->assertSame(1, app(FocusService::class)->closeStale());
        $s = FocusSession::withoutGlobalScopes()->find($id);
        $this->assertSame('interrupted', $s->status);
        $this->assertSame(1500, $s->actual_seconds, 'capped at the planned length');
        $this->assertNull($this->api('GET', '/focus')->json('current'));
    }

    public function test_focus_settings_are_validated_and_saved(): void
    {
        $this->api('POST', '/focus/settings', ['focus_minutes' => 50, 'break_minutes' => 10, 'long_break_minutes' => 20, 'long_break_every' => 3, 'sound' => false])
            ->assertOk()->assertJsonPath('focus_minutes', 50)->assertJsonPath('sound', false);
        $this->assertSame(50, $this->api('GET', '/focus')->json('settings.focus_minutes'));
        $this->api('POST', '/focus/settings', ['focus_minutes' => 1, 'break_minutes' => 10, 'long_break_minutes' => 20, 'long_break_every' => 3])->assertStatus(422);
    }

    // ================================================================ habits

    private function habit(array $a = []): Habit
    {
        return Habit::create($a + ['user_id' => $this->u->id, 'title' => 'Read', 'start_date' => '2025-11-01']);
    }

    private function mark(Habit $h, string $date, array $e = ['completed' => true]): void
    {
        HabitEntry::create($e + ['user_id' => $this->u->id, 'habit_id' => $h->id, 'entry_date' => $date]);
    }

    public function test_daily_streak_is_kind_today_pending_never_breaks_it(): void
    {
        $h = $this->habit();
        foreach (['2025-11-08', '2025-11-09', '2025-11-10', '2025-11-11'] as $d) $this->mark($h, $d);
        $this->mark($h, '2025-11-03'); $this->mark($h, '2025-11-04'); // older run of 2
        $s = app(HabitService::class)->stats($h, $this->u);
        $this->assertSame(4, $s['current_streak'], 'today (not done yet) does not break the streak');
        $this->assertSame(4, $s['longest_streak']);
        $this->assertTrue($s['due_today']);
        $this->assertFalse($s['done_today']);

        $this->api('POST', "/habits/{$h->id}/check")->assertOk()->assertJsonPath('habit.current_streak', 5)->assertJsonPath('habit.done_today', true);
        $this->api('POST', "/habits/{$h->id}/check")->assertOk()->assertJsonPath('entry', null)->assertJsonPath('habit.current_streak', 4); // toggled back
    }

    public function test_weekdays_interval_and_pauses_do_not_count_as_missed(): void
    {
        // Sat(6) and Mon(1) only. 2025-11-08 Sat, 10 Mon, 12 Wed (today, not scheduled).
        $h = $this->habit(['frequency' => 'weekdays', 'weekdays' => [6, 1]]);
        $this->mark($h, '2025-11-01'); $this->mark($h, '2025-11-03'); $this->mark($h, '2025-11-08'); $this->mark($h, '2025-11-10');
        $s = app(HabitService::class)->stats($h, $this->u);
        $this->assertSame(4, $s['current_streak']);
        $this->assertFalse($s['due_today']);

        $i = $this->habit(['title' => 'Every 3 days', 'frequency' => 'interval', 'interval_days' => 3, 'start_date' => '2025-11-03']); // 3, 6, 9, 12
        $svc = app(HabitService::class);
        $this->assertTrue($svc->isDue($i, HabitService::day('2025-11-09')));
        $this->assertFalse($svc->isDue($i, HabitService::day('2025-11-10')));

        // Paused 5–9 Nov: those days are not missed.
        $p = $this->habit(['title' => 'Walk', 'pauses' => [['from' => '2025-11-05', 'to' => '2025-11-10']]]);
        foreach (['2025-11-03', '2025-11-04', '2025-11-10', '2025-11-11'] as $d) $this->mark($p, $d);
        $this->assertSame(4, $svc->stats($p, $this->u)['current_streak']);
        $states = collect($svc->history($p, $this->u, 3)['days'])->pluck('state', 'date');
        $this->assertSame('paused', $states['2025-11-07']);
        $this->assertSame('missed', $states['2025-11-02']);
        $this->assertSame('pending', $states['2025-11-12']);
        $this->assertSame('future', $states['2025-11-13']);
    }

    public function test_weekly_and_numeric_build_and_reduce_habits(): void
    {
        $svc = app(HabitService::class);
        // 3× a week; fa weeks start on Saturday: 1–7 Nov, 8–14 Nov (current, 1 so far).
        $w = $this->habit(['title' => 'Gym', 'frequency' => 'weekly', 'times_per_week' => 3]);
        foreach (['2025-11-01', '2025-11-03', '2025-11-05', '2025-11-09'] as $d) $this->mark($w, $d);
        $s = $svc->stats($w, $this->u);
        $this->assertSame(1, $s['current_streak'], 'last week met; this week is unfinished and does not break it');
        $this->assertSame('weeks', $s['streak_unit']);
        $this->assertSame(1, $s['week_count']);

        $pages = $this->habit(['title' => 'Pages', 'measure' => 'numeric', 'target_value' => 20, 'unit' => 'pages']);
        $this->api('POST', "/habits/{$pages->id}/check", ['add' => 12])->assertJsonPath('habit.done_today', false);
        $this->api('POST', "/habits/{$pages->id}/check", ['add' => 10])->assertJsonPath('habit.done_today', true)->assertJsonPath('entry.value', 22);

        $smoke = $this->habit(['title' => 'Cigarettes', 'kind' => 'reduce', 'measure' => 'numeric', 'target_value' => 3]);
        $this->api('POST', "/habits/{$smoke->id}/check", ['value' => 2])->assertJsonPath('habit.done_today', true);
        $this->api('POST', "/habits/{$smoke->id}/check", ['value' => 5])->assertJsonPath('habit.done_today', false);

        $this->api('POST', "/habits/{$pages->id}/check", ['date' => '2025-11-13', 'completed' => true])->assertStatus(422);
        $this->api('POST', "/habits/{$pages->id}/check", ['date' => '2025-10-01', 'value' => 3])->assertStatus(422);
    }

    public function test_crud_validation_overview_pause_archive_and_plan_limit(): void
    {
        $this->seed(PlanSeeder::class);
        $this->api('POST', '/habits', ['title' => 'X', 'frequency' => 'weekdays'])->assertStatus(422)->assertJsonValidationErrors('weekdays');
        $ids = [];
        foreach (['A', 'B', 'C'] as $t) $ids[] = $this->api('POST', '/habits', ['title' => $t])->assertCreated()->json('id');
        $this->api('POST', '/habits', ['title' => 'D'])->assertStatus(402)->assertJsonPath('metric', 'active_habits');

        $this->api('POST', "/habits/{$ids[0]}/archive")->assertOk();
        $this->api('POST', '/habits', ['title' => 'D'])->assertCreated();
        $this->api('POST', "/habits/{$ids[0]}/unarchive")->assertStatus(402);
        $this->assertCount(1, $this->api('GET', '/habits?archived=1')->json('habits'));

        $this->api('POST', "/habits/{$ids[1]}/check")->assertOk();
        $o = $this->api('GET', '/habits')->assertOk();
        $this->assertSame(['due' => 3, 'done' => 1], $o->json('today'));
        $this->assertCount(11 * 7 + 5, $o->json('heatmap'), '11 full weeks + this week so far (Sat→Wed)');
        $this->assertSame(['due' => 3, 'done' => 1, 'rate' => 33], array_intersect_key(collect($o->json('heatmap'))->last(), array_flip(['due', 'done', 'rate'])));

        $this->api('POST', "/habits/{$ids[2]}/pause")->assertJsonPath('status', 'paused');
        $this->travel(1)->days();
        $this->assertSame(2, $this->api('GET', '/habits')->json('today.due'), 'a paused habit is not due');
        $this->api('POST', "/habits/{$ids[2]}/resume")->assertJsonPath('status', 'active');

        $this->api('GET', "/habits/{$ids[1]}?weeks=4")->assertOk()->assertJsonCount(28, 'history.days')->assertJsonCount(7, 'habit.recent');
        $this->api('DELETE', "/habits/{$ids[1]}")->assertNoContent();
        $this->assertSame(0, HabitEntry::where('habit_id', $ids[1])->count());
    }

    public function test_habits_are_private_per_user(): void
    {
        $h = $this->habit();
        $other = $this->auth($this->user('o@example.com'));
        $this->api('GET', "/habits/{$h->id}", [], $other)->assertNotFound();
        $this->api('POST', "/habits/{$h->id}/check", [], $other)->assertNotFound();
        $this->assertSame([], $this->api('GET', '/habits', [], $other)->json('habits'));
        $goal = \App\Models\Goal::create(['user_id' => $this->u->id, 'title' => 'Mine']);
        $this->api('POST', '/habits', ['title' => 'Steal', 'goal_id' => $goal->id], $other)->assertStatus(422);
    }

    public function test_bot_checks_in_habits(): void
    {
        \Illuminate\Support\Facades\Http::fake(['https://api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->u->update(['telegram_chat_id' => '777']);
        $read = $this->habit();
        $water = $this->habit(['title' => 'Water', 'measure' => 'numeric', 'target_value' => 2, 'unit' => 'glasses']);
        $bot = app(\App\Services\Telegram\TelegramPlannerBotService::class);
        $bot->handleCallback('777', 'u', 'c1', 5, 'hb');
        $bot->handleCallback('777', 'u', 'c2', 5, 'hbt:'.$read->id);
        $bot->handleCallback('777', 'u', 'c3', 5, 'hba:'.$water->id);
        $bot->handleCallback('777', 'u', 'c4', 5, 'hba:'.$water->id);
        $this->assertTrue(HabitEntry::withoutGlobalScopes()->where('habit_id', $read->id)->value('completed'));
        $this->assertSame(2.0, (float) HabitEntry::withoutGlobalScopes()->where('habit_id', $water->id)->value('value'));
        $last = collect(\Illuminate\Support\Facades\Http::recorded())->map(fn ($x) => $x[0])->filter(fn ($r) => str_contains($r->url(), 'Message'))->last();
        $this->assertStringContainsString('۲ از ۲', (string) $last['text']);

        $other = Habit::create(['user_id' => $this->user('x@example.com')->id, 'title' => 'Theirs', 'start_date' => '2025-11-01']);
        $bot->handleCallback('777', 'u', 'c5', 5, 'hbt:'.$other->id);
        $this->assertSame(0, HabitEntry::withoutGlobalScopes()->where('habit_id', $other->id)->count());
    }
}
