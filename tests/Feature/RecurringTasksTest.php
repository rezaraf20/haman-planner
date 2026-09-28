<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planner\RecurrenceRule;
use App\Models\ApiToken;
use App\Models\Goal;
use App\Models\Plan;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class RecurringTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00', 'Asia/Tehran')); // a Monday
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'timezone' => 'Asia/Tehran']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(User $u): array
    {
        $plain = 'rt-'.$u->id.str_repeat('r', 40);
        ApiToken::firstOrCreate(['token_hash' => hash('sha256', $plain)], ['user_id' => $u->id, 'name' => 't', 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function occurrences(int $seriesId): array
    {
        return Task::withoutGlobalScopes()->where('recurring_task_id', $seriesId)->orderBy('occurrence_date')->pluck('occurrence_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
    }

    // ---------------------------------------------------------------- rule engine

    public function test_rule_patterns(): void
    {
        $r = fn (...$a) => new RecurrenceRule(...$a);
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], $r('daily', 1, [], null, null, '2026-10-05')->between('2026-10-01', '2026-10-07'));
        $this->assertSame(['2026-10-05', '2026-10-07', '2026-10-09', '2026-10-12'], $r('weekly', 1, [1, 3, 5], null, null, '2026-10-05')->between('2026-10-05', '2026-10-12'));
        $this->assertSame(['2026-10-05', '2026-10-19', '2026-11-02'], $r('weekly', 2, [1], null, null, '2026-10-05')->between('2026-10-01', '2026-11-08'), 'every 2 weeks');
        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], $r('monthly', 1, [], 1, null, '2026-01-01')->between('2026-01-01', '2026-03-15'));
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $r('monthly', 1, [], 31, null, '2026-01-31')->between('2026-01-01', '2026-04-30'), 'clamped to month end');
        $this->assertSame(['2027-01-01', '2028-01-01'], $r('yearly', 1, [], 1, 1, '2026-06-01')->between('2026-06-01', '2028-06-01'));
        $this->assertSame(['2026-10-05', '2026-10-06'], $r('daily', 1, [], null, null, '2026-10-05', '2026-10-06')->between('2026-10-01', '2026-12-31'), 'end date');
        $this->assertSame(['2026-10-11'], $r('daily', 3, [], null, null, '2026-10-05', null, 3)->between('2026-10-09', '2026-12-31'), 'max occurrences counts from the start');
        // Persian calendar: the 1st of every Jalali month, and 1 Farvardin every year.
        $this->assertSame(['2026-10-23', '2026-11-22', '2026-12-22'], $r('monthly', 1, [], 1, null, '2026-10-05', null, null, 'jalali')->between('2026-10-01', '2026-12-31'));
        $this->assertSame(['2027-03-21', '2028-03-20'], $r('yearly', 1, [], 1, 1, '2026-10-05', null, null, 'jalali')->between('2026-10-01', '2028-12-31'));
    }

    // ---------------------------------------------------------------- series lifecycle

    public function test_series_generates_only_up_to_the_horizon_and_copies_the_template(): void
    {
        config(['planner.recurrence_horizon_days' => 7]);
        $goal = Goal::create(['user_id' => $this->user->id, 'title' => 'Health']);
        $res = $this->withHeaders($this->h($this->user))->postJson('/api/recurring-tasks', [
            'title' => 'Workout', 'frequency' => 'weekly', 'by_weekday' => [1, 3, 5], 'goal_id' => $goal->id,
            'priority' => 'p1', 'weight' => 2, 'estimated_minutes' => 45, 'time_of_day' => '07:30',
        ])->assertCreated();
        $this->assertStringContainsString('Monday', __('recurrence.weekdays', [], 'en')[0]);
        $this->assertNotEmpty($res->json('rule_label'));

        $id = $res->json('id');
        $this->assertSame(['2026-10-05', '2026-10-07', '2026-10-09', '2026-10-12'], $this->occurrences($id), 'no occurrences beyond 7 days');
        $occ = Task::withoutGlobalScopes()->where('recurring_task_id', $id)->orderBy('occurrence_date')->first();
        $this->assertSame([$this->user->id, $goal->id, 'p1', '2.00', 45, 'Workout'], [$occ->user_id, $occ->goal_id, $occ->priority, (string) $occ->weight, (int) $occ->estimated_minutes, $occ->title]);
        $this->assertSame('2026-10-05 07:30', $occ->planned_start->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-05 08:15', $occ->planned_end->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));

        // A week later the scheduler extends the series without duplicating anything.
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00', 'Asia/Tehran'));
        $this->artisan('planner:recurring')->assertSuccessful();
        $this->artisan('planner:recurring')->assertSuccessful();
        $this->assertSame(['2026-10-05', '2026-10-07', '2026-10-09', '2026-10-12', '2026-10-14', '2026-10-16', '2026-10-19'], $this->occurrences($id));
    }

    public function test_times_follow_the_series_timezone_across_dst(): void
    {
        $this->user->update(['timezone' => 'America/New_York']);
        Carbon::setTestNow(Carbon::parse('2026-10-29 12:00', 'UTC'));
        $id = $this->withHeaders($this->h($this->user))->postJson('/api/recurring-tasks', [
            'title' => 'Standup', 'frequency' => 'daily', 'time_of_day' => '09:00', 'starts_on' => '2026-10-30', 'ends_on' => '2026-11-03',
        ])->assertCreated()->json('id');
        $utc = Task::withoutGlobalScopes()->where('recurring_task_id', $id)->orderBy('occurrence_date')->get()
            ->mapWithKeys(fn ($t) => [Carbon::parse($t->occurrence_date)->toDateString() => $t->planned_start->utc()->format('H:i')])->all();
        // US clocks go back on 1 Nov 2026: 09:00 local is 13:00Z before and 14:00Z after.
        $this->assertSame(['2026-10-30' => '13:00', '2026-10-31' => '13:00', '2026-11-01' => '14:00', '2026-11-02' => '14:00', '2026-11-03' => '14:00'], $utc);
    }

    public function test_skipped_and_deleted_occurrences_are_never_recreated(): void
    {
        config(['planner.recurrence_horizon_days' => 3]);
        $h = $this->h($this->user);
        $id = $this->withHeaders($h)->postJson('/api/recurring-tasks', ['title' => 'Read', 'frequency' => 'daily'])->json('id');
        [$first, $second] = Task::withoutGlobalScopes()->where('recurring_task_id', $id)->orderBy('occurrence_date')->take(2)->get();

        $this->withHeaders($h)->postJson('/api/tasks/'.$first->id.'/skip')->assertOk()->assertJsonPath('recurrence_exception', 'skipped');
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->withHeaders($h)->deleteJson('/api/tasks/'.$second->id)->assertNoContent();

        Carbon::setTestNow(now()->addDay());
        $this->artisan('planner:recurring');
        $dates = $this->occurrences($id);
        $this->assertContains('2026-10-05', $dates, 'skipped stays as history');
        $this->assertNotContains('2026-10-06', $dates, 'deleted one is not recreated');
        $this->assertContains('2026-10-09', $dates);

        $plain = Task::create(['user_id' => $this->user->id, 'title' => 'One-off']);
        $this->withHeaders($h)->postJson('/api/tasks/'.$plain->id.'/skip')->assertStatus(422);
    }

    public function test_complete_reschedule_and_edit_single_occurrence(): void
    {
        $h = $this->h($this->user);
        $id = $this->withHeaders($h)->postJson('/api/recurring-tasks', ['title' => 'Review inbox', 'frequency' => 'daily', 'time_of_day' => '10:00'])->json('id');
        [$a, $b, $c] = Task::withoutGlobalScopes()->where('recurring_task_id', $id)->orderBy('occurrence_date')->take(3)->get();

        $this->withHeaders($h)->putJson('/api/tasks/'.$a->id, ['status' => 'completed'])->assertOk();
        $this->assertNotNull($a->fresh()->completed_at);
        $this->withHeaders($h)->putJson('/api/tasks/'.$b->id, ['planned_start' => '2026-10-06T15:00:00+03:30', 'planned_end' => '2026-10-06T15:30:00+03:30'])->assertOk();
        $this->assertSame('moved', $b->fresh()->recurrence_exception);
        $this->withHeaders($h)->putJson('/api/tasks/'.$c->id, ['title' => 'Review inbox (short)'])->assertOk();
        $this->assertSame('edited', $c->fresh()->recurrence_exception);
        $this->assertTrue(\App\Models\ActivityLog::withoutGlobalScopes()->where('action', 'rescheduled')->where('entity_id', (string) $b->id)->exists());

        // "Edit all future": untouched occurrences change, the individually edited one keeps its title.
        $this->withHeaders($h)->putJson('/api/recurring-tasks/'.$id, ['title' => 'Inbox zero', 'priority' => 'p1'])->assertOk();
        $titles = Task::withoutGlobalScopes()->where('recurring_task_id', $id)->orderBy('occurrence_date')->pluck('title')->all();
        $this->assertSame('Review inbox', $titles[0], 'completed occurrence untouched');
        $this->assertSame('Review inbox (short)', $titles[2]);
        $this->assertSame('Inbox zero', $titles[3]);
        $this->assertSame('Inbox zero', RecurringTask::withoutGlobalScopes()->find($id)->title);
    }

    public function test_rule_change_replaces_untouched_future_occurrences_and_stop_ends_the_series(): void
    {
        config(['planner.recurrence_horizon_days' => 13]);
        $h = $this->h($this->user);
        $id = $this->withHeaders($h)->postJson('/api/recurring-tasks', ['title' => 'Plan', 'frequency' => 'daily'])->json('id');
        $today = Task::withoutGlobalScopes()->where('recurring_task_id', $id)->where('occurrence_date', '2026-10-05')->first();
        $this->withHeaders($h)->putJson('/api/tasks/'.$today->id, ['status' => 'completed']);

        $this->withHeaders($h)->putJson('/api/recurring-tasks/'.$id, ['frequency' => 'weekly', 'by_weekday' => [1], 'from_date' => '2026-10-06'])->assertOk();
        $this->assertSame(['2026-10-05', '2026-10-12'], $this->occurrences($id), 'daily 6–18 Oct replaced by Mondays within the 13-day horizon');

        $this->withHeaders($h)->postJson('/api/recurring-tasks/'.$id.'/stop')->assertOk()->assertJsonPath('status', 'stopped');
        $this->assertSame(['2026-10-05'], $this->occurrences($id));
        Carbon::setTestNow(now()->addDays(10));
        $this->artisan('planner:recurring');
        $this->assertSame(['2026-10-05'], $this->occurrences($id), 'a stopped series generates nothing');
    }

    public function test_validation_ownership_and_entitlement(): void
    {
        $h = $this->h($this->user);
        $this->withHeaders($h)->postJson('/api/recurring-tasks', ['title' => 'x', 'frequency' => 'hourly'])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/recurring-tasks', ['title' => 'x', 'frequency' => 'daily', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-01'])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/recurring-tasks', ['title' => 'x', 'frequency' => 'weekly', 'by_weekday' => [8]])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/recurring-tasks/preview', ['title' => 'x', 'frequency' => 'monthly', 'by_month_day' => 31, 'starts_on' => '2026-01-31'])
            ->assertOk()->assertJsonPath('dates.1', '2026-02-28');

        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'secret123', 'is_active' => true]);
        $foreignGoal = Goal::create(['user_id' => $other->id, 'title' => 'Theirs']);
        $this->withHeaders($h)->postJson('/api/recurring-tasks', ['title' => 'x', 'frequency' => 'daily', 'goal_id' => $foreignGoal->id])->assertStatus(422);
        $theirs = $this->withHeaders($this->h($other))->postJson('/api/recurring-tasks', ['title' => 'Secret', 'frequency' => 'daily'])->json('id');
        $this->withHeaders($h)->getJson('/api/recurring-tasks/'.$theirs)->assertNotFound();
        $this->withHeaders($h)->putJson('/api/recurring-tasks/'.$theirs, ['title' => 'hacked'])->assertNotFound();
        $this->withHeaders($h)->postJson('/api/recurring-tasks/'.$theirs.'/stop')->assertNotFound();
        $this->assertStringNotContainsString('Secret', $this->withHeaders($h)->getJson('/api/recurring-tasks')->getContent());

        $this->seed(PlanSeeder::class);
        $free = Plan::where('code', 'free')->first();
        $free->update(['features' => array_merge($free->features, ['recurring_tasks' => false])]);
        $this->withHeaders($h)->postJson('/api/recurring-tasks', ['title' => 'x', 'frequency' => 'daily'])->assertStatus(402)->assertJsonPath('metric', 'feature_recurring_tasks');
    }
}
