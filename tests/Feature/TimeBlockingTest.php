<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planner\CapacityPlanner;
use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\Planner\SchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class TimeBlockingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 07:00', 'Europe/London')); // Monday
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(),
            'locale' => 'en', 'timezone' => 'Europe/London',
            'preferences' => ['work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '15:00', 'planning_buffer_percent' => 20]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(User $u): array
    {
        $plain = 'tb-'.$u->id.str_repeat('b', 40);
        ApiToken::firstOrCreate(['token_hash' => hash('sha256', $plain)], ['user_id' => $u->id, 'name' => 't', 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function at(string $time, string $day = '2026-10-05'): string
    {
        return CarbonImmutable::parse($day.' '.$time, 'Europe/London')->toIso8601String();
    }

    public function test_overload_warning_uses_working_hours_busy_time_and_buffer(): void
    {
        foreach ([['09:00', '12:00'], ['12:00', '14:30'], ['14:30', '16:30']] as $i => [$s, $e]) {
            $t = Task::create(['user_id' => $this->user->id, 'title' => 'T'.$i]);
            ScheduleBlock::create(['user_id' => $this->user->id, 'task_id' => $t->id, 'starts_at' => $this->at($s), 'ends_at' => $this->at($e)]);
        }
        $day = app(SchedulingService::class)->dayCapacity($this->user, CarbonImmutable::parse('2026-10-05', 'Europe/London'));
        $this->assertSame([360, 288, 450, 162], [$day['available_minutes'], $day['usable_minutes'], $day['scheduled_minutes'], $day['overload_minutes']]);
        $this->assertSame('You have 7h 30m of work scheduled in a 6h available window.', $day['warning']['text']);

        // A busy calendar event reduces available time only while the setting is on.
        $conn = CalendarConnection::create(['user_id' => $this->user->id, 'provider' => 'google']);
        CalendarEvent::create(['user_id' => $this->user->id, 'calendar_connection_id' => $conn->id, 'provider_event_id' => 'e1', 'title' => 'Dentist', 'starts_at' => $this->at('13:00'), 'ends_at' => $this->at('14:00')]);
        $this->assertSame(300, app(SchedulingService::class)->dayCapacity($this->user, CarbonImmutable::parse('2026-10-05', 'Europe/London'))['available_minutes']);
        $this->user->update(['preferences' => array_merge($this->user->preferences, ['calendar_blocks_planning' => false])]);
        $this->assertSame(360, app(SchedulingService::class)->dayCapacity($this->user->fresh(), CarbonImmutable::parse('2026-10-05', 'Europe/London'))['available_minutes']);

        // Weekend: not a working day.
        $sat = app(SchedulingService::class)->dayCapacity($this->user, CarbonImmutable::parse('2026-10-10', 'Europe/London'));
        $this->assertFalse($sat['working']);
        $this->assertSame(0, $sat['available_minutes']);
    }

    public function test_buffer_is_configurable_per_user(): void
    {
        $this->assertSame(0.2, CapacityPlanner::forUser($this->user)->bufferRatio());
        $this->actingAs($this->user)->post('/settings/planning', [
            'work_days' => [6, 7, 1, 2, 3], 'work_start' => '08:30', 'work_end' => '16:00', 'default_task_minutes' => 45, 'break_minutes' => 15, 'planning_buffer_percent' => 30,
        ])->assertRedirect();
        $u = $this->user->fresh();
        $this->assertSame([1, 2, 3, 6, 7], $u->preference('work_days'));
        $this->assertSame(0.3, CapacityPlanner::forUser($u)->bufferRatio());
        $this->assertSame(0.3, app(CapacityPlanner::class)->bufferRatio(), 'container binding follows the signed-in user');
        $this->assertFalse($u->preference('calendar_blocks_planning'));

        $this->post('/settings/planning', ['work_days' => [], 'work_start' => '18:00', 'work_end' => '09:00', 'default_task_minutes' => 1, 'break_minutes' => 0, 'planning_buffer_percent' => 90])
            ->assertSessionHasErrors(['work_days', 'work_end', 'default_task_minutes', 'planning_buffer_percent']);
    }

    public function test_place_detects_conflicts_and_requires_confirmation(): void
    {
        $h = $this->h($this->user);
        $a = Task::create(['user_id' => $this->user->id, 'title' => 'Write spec', 'estimated_minutes' => 90, 'status' => 'inbox']);
        $b = Task::create(['user_id' => $this->user->id, 'title' => 'Email', 'estimated_minutes' => 30]);

        $this->withHeaders($h)->postJson('/api/schedule/place', ['task_id' => $a->id, 'starts_at' => $this->at('09:00')])->assertCreated();
        $a->refresh();
        $this->assertSame('planned', $a->status);
        $this->assertSame('10:30', $a->planned_end->setTimezone('Europe/London')->format('H:i'), 'duration from the estimate');

        $res = $this->withHeaders($h)->postJson('/api/schedule/place', ['task_id' => $b->id, 'starts_at' => $this->at('10:00')])->assertStatus(409);
        $this->assertTrue($res->json('requires_confirmation'));
        $this->assertSame('Write spec', $res->json('conflicts.0.title'));
        $this->assertNull($b->fresh()->planned_start, 'nothing saved without confirmation');

        $this->withHeaders($h)->postJson('/api/schedule/place', ['task_id' => $b->id, 'starts_at' => $this->at('10:00'), 'force' => true])->assertCreated();
        $range = $this->withHeaders($h)->getJson('/api/schedule?from=2026-10-05&to=2026-10-11')->assertOk();
        $this->assertCount(2, $range->json('items'));
        $this->assertCount(1, $range->json('conflicts'));
        $this->assertSame(7, count($range->json('days')));

        // Breaks never block.
        $this->withHeaders($h)->postJson('/api/schedule/place', ['kind' => 'break', 'starts_at' => $this->at('10:15')])->assertCreated()->assertJsonPath('kind', 'break');
    }

    public function test_move_and_resize_blocks_and_tasks_logs_rescheduling(): void
    {
        $h = $this->h($this->user);
        $t = Task::create(['user_id' => $this->user->id, 'title' => 'Deep work']);
        $block = $this->withHeaders($h)->postJson('/api/schedule/place', ['task_id' => $t->id, 'starts_at' => $this->at('09:00'), 'ends_at' => $this->at('10:00')])->json();

        $this->withHeaders($h)->patchJson('/api/schedule/block/'.$block['id'], ['starts_at' => $this->at('11:00'), 'ends_at' => $this->at('12:30')])->assertOk();
        $this->assertSame('11:00', $t->fresh()->planned_start->setTimezone('Europe/London')->format('H:i'));
        $this->assertTrue(ActivityLog::withoutGlobalScopes()->where('action', 'rescheduled')->where('entity_id', (string) $t->id)->exists());

        $free = Task::create(['user_id' => $this->user->id, 'title' => 'Calls', 'planned_start' => $this->at('13:00'), 'planned_end' => $this->at('13:30')]);
        $this->withHeaders($h)->patchJson('/api/schedule/task/'.$free->id, ['starts_at' => $this->at('11:30'), 'ends_at' => $this->at('12:00')])->assertStatus(409);
        $this->withHeaders($h)->patchJson('/api/schedule/task/'.$free->id, ['starts_at' => $this->at('14:00'), 'ends_at' => $this->at('14:45')])->assertOk();

        $this->withHeaders($h)->postJson('/api/tasks/'.$free->id.'/unschedule')->assertOk();
        $this->assertNull($free->fresh()->planned_start);
    }

    public function test_schedule_is_owner_scoped(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'secret123', 'is_active' => true]);
        $theirs = Task::create(['user_id' => $other->id, 'title' => 'Private meeting', 'planned_start' => $this->at('10:00'), 'planned_end' => $this->at('11:00')]);
        $theirBlock = ScheduleBlock::create(['user_id' => $other->id, 'task_id' => $theirs->id, 'starts_at' => $this->at('10:00'), 'ends_at' => $this->at('11:00')]);
        $h = $this->h($this->user);

        $this->assertStringNotContainsString('Private meeting', $this->withHeaders($h)->getJson('/api/schedule?from=2026-10-05&to=2026-10-06')->getContent());
        $this->withHeaders($h)->postJson('/api/schedule/place', ['task_id' => $theirs->id, 'starts_at' => $this->at('09:00')])->assertNotFound();
        $this->withHeaders($h)->patchJson('/api/schedule/block/'.$theirBlock->id, ['starts_at' => $this->at('12:00'), 'ends_at' => $this->at('13:00')])->assertNotFound();
        $this->withHeaders($h)->patchJson('/api/schedule/task/'.$theirs->id, ['starts_at' => $this->at('12:00'), 'ends_at' => $this->at('13:00')])->assertNotFound();
        $this->withHeaders($h)->postJson('/api/tasks/'.$theirs->id.'/unschedule')->assertNotFound();
        // Another user's time is never a conflict for me.
        $mine = Task::create(['user_id' => $this->user->id, 'title' => 'Mine']);
        $this->withHeaders($h)->postJson('/api/schedule/place', ['task_id' => $mine->id, 'starts_at' => $this->at('10:00')])->assertCreated();
        $this->assertSame('10:00', $theirBlock->fresh()->starts_at->setTimezone('Europe/London')->format('H:i'));
    }
}
