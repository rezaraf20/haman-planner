<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\Area;
use App\Models\DailyPlan;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Reminder;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Planner\ReminderService;
use App\Services\Telegram\TelegramPlannerBotService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Completing/archiving goals & projects, and the flow fixes found in the planner audit. */
final class PlannerLifecycleAndFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $u;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $this->u = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'fa', 'timezone' => 'Asia/Tehran', 'telegram_chat_id' => '5005']);
        $plain = 'lf-'.str_repeat('l', 40);
        ApiToken::create(['user_id' => $this->u->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        $this->h = ['Authorization' => 'Bearer '.$plain];
    }

    private function api(string $m, string $url, array $data = [])
    {
        return $this->withHeaders($this->h)->json($m, '/api'.$url, $data);
    }

    // ------------------------------------------------------------ lifecycle

    public function test_project_can_be_completed_reopened_archived_and_restored(): void
    {
        $p = $this->api('POST', '/projects', ['title' => 'Site'])->assertCreated()->json('id');
        foreach (['A', 'B'] as $t) $this->api('POST', '/tasks', ['title' => $t, 'project_id' => $p])->assertCreated();

        $this->api('GET', "/projects/$p/open-tasks")->assertOk()->assertJsonPath('count', 2);

        // Complete without touching tasks: tasks stay open, project shows 100 % and a completion time.
        $r = $this->api('POST', "/projects/$p/complete")->assertOk();
        $this->assertSame('completed', $r->json('status'));
        $this->assertNotNull($r->json('completed_at'));
        $this->assertSame(100.0, (float) $r->json('progress'));
        $this->assertSame(2, Task::where('project_id', $p)->where('status', '!=', 'cancelled')->where('status', '!=', 'completed')->count());

        // Lists: gone from "open", present in "closed".
        $this->assertSame([], $this->api('GET', '/projects?state=open')->json('data'));
        $this->assertSame($p, $this->api('GET', '/projects?state=closed')->json('data.0.id'));
        $this->assertSame(2, $this->api('GET', '/projects?state=closed')->json('data.0.open_tasks_count'));

        // Reopen clears the completion time.
        $r = $this->api('POST', "/projects/$p/reopen")->assertOk();
        $this->assertSame('active', $r->json('status'));
        $this->assertNull($r->json('completed_at'));

        // Archive (and cancel the open tasks on request — never delete them).
        $this->api('POST', "/projects/$p/archive", ['cancel_open_tasks' => true])->assertOk()->assertJsonPath('open_tasks_count', 0);
        $this->assertSame(2, Task::where('project_id', $p)->where('status', 'cancelled')->count());
        $this->assertSame([], $this->api('GET', '/projects')->json('data'), 'archived items leave the default list');
        $this->assertSame($p, $this->api('GET', '/projects?state=archived')->json('data.0.id'));

        $this->api('POST', "/projects/$p/unarchive")->assertOk();
        $this->assertSame($p, $this->api('GET', '/projects')->json('data.0.id'));
        $this->assertTrue(ActivityLog::where('action', 'lifecycle_archive')->exists());

        // Statuses are a fixed list now (free text like "done" is rejected).
        $this->api('PUT', "/projects/$p", ['status' => 'done'])->assertStatus(422);
        $this->api('PUT', "/projects/$p", ['status' => 'on_hold'])->assertOk();
    }

    public function test_archived_items_do_not_count_against_plan_limits_and_areas_archive(): void
    {
        $this->seed(PlanSeeder::class);
        $e = app(Entitlements::class);
        $ids = [];
        for ($i = 1; $i <= 3; $i++) $ids[] = $this->api('POST', '/projects', ['title' => 'P'.$i])->assertCreated()->json('id');
        $this->api('POST', '/projects', ['title' => 'P4'])->assertStatus(402);
        $this->api('POST', "/projects/{$ids[0]}/archive")->assertOk();
        $e->forget($this->u);
        $this->assertSame(2, $e->used($this->u, 'active_projects'));
        $this->api('POST', '/projects', ['title' => 'P4'])->assertCreated();

        $a = $this->api('POST', '/areas', ['name' => 'Old'])->json('id');
        $this->api('POST', "/areas/$a/complete")->assertNotFound();
        $this->api('POST', "/areas/$a/archive")->assertOk();
        $this->assertSame([], $this->api('GET', '/areas')->json());
        $this->assertCount(1, $this->api('GET', '/areas?state=archived')->json());
    }

    public function test_progress_counts_direct_tasks_and_ignores_cancelled(): void
    {
        $p = Project::create(['user_id' => $this->u->id, 'title' => 'P']);
        $m = Milestone::create(['user_id' => $this->u->id, 'project_id' => $p->id, 'title' => 'M']);
        $done = $this->api('POST', '/tasks', ['title' => 'in milestone', 'project_id' => $p->id, 'milestone_id' => $m->id])->json('id');
        $direct = $this->api('POST', '/tasks', ['title' => 'direct', 'project_id' => $p->id])->json('id');
        $this->api('PUT', "/tasks/$done", ['status' => 'completed'])->assertOk();
        $this->assertSame(50.0, (float) $p->fresh()->progress, 'milestone (100%) + direct task (0%)');

        $this->api('PUT', "/tasks/$direct", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(100.0, (float) $p->fresh()->progress, 'a cancelled task no longer holds the project back');

        // Reopening a completed task resets its progress, so parents drop again.
        $this->api('PUT', "/tasks/$done", ['status' => 'planned'])->assertOk();
        $t = Task::find($done);
        $this->assertSame(0.0, (float) $t->progress);
        $this->assertNull($t->completed_at);
        $this->assertSame(0.0, (float) $p->fresh()->progress);
    }

    // ------------------------------------------------------------ fixes

    public function test_goal_edit_keeps_all_fields_and_milestone_updates_project(): void
    {
        $area = Area::create(['user_id' => $this->u->id, 'name' => 'A']);
        $g = $this->api('POST', '/goals', ['title' => 'G'])->json('id');
        $this->api('PUT', "/goals/$g", ['area_id' => $area->id, 'start_date' => '2026-11-01', 'weight' => 2])->assertOk();
        $goal = Goal::find($g);
        $this->assertSame([$area->id, '2026-11-01', 2.0], [(int) $goal->area_id, $goal->start_date->toDateString(), (float) $goal->weight]);

        $p = Project::create(['user_id' => $this->u->id, 'title' => 'P']);
        $m = $this->api('POST', '/milestones', ['title' => 'M', 'project_id' => $p->id])->json('id');
        $this->api('POST', "/milestones/$m/complete")->assertOk();
        $this->assertSame(100.0, (float) $p->fresh()->progress);
        $this->assertNotNull(Milestone::find($m)->completed_at);
    }

    public function test_task_lists_filter_by_state_and_hide_far_recurring_occurrences(): void
    {
        $this->api('POST', '/tasks', ['title' => 'open'])->assertCreated();
        $c = $this->api('POST', '/tasks', ['title' => 'closed'])->json('id');
        $this->api('PUT', "/tasks/$c", ['status' => 'completed']);
        $this->api('POST', '/recurring-tasks', ['title' => 'Gym', 'frequency' => 'daily'])->assertCreated();

        $open = $this->api('GET', '/tasks?state=open&hide_future=1')->assertOk();
        $titles = array_column($open->json('data'), 'title');
        $this->assertContains('open', $titles);
        $this->assertNotContains('closed', $titles);
        $this->assertLessThanOrEqual(2, count(array_keys($titles, 'Gym')), 'only today/tomorrow');
        $this->assertGreaterThan(5, $open->json('hidden_future'));
        $this->assertSame(['closed'], array_column($this->api('GET', '/tasks?state=closed')->json('data'), 'title'));
        $this->api('PUT', "/tasks/$c", ['status' => 'done'])->assertStatus(422);
    }

    public function test_reminder_edit_keeps_message_and_rescheduling_resends(): void
    {
        $r = $this->api('POST', '/reminders', ['scheduled_at' => now()->addHour()->toIso8601String(), 'message' => 'Call mom'])->assertCreated();
        $this->assertSame('Call mom', $r->json('message'));
        $this->assertSame('Call mom', $r->json('title'));
        $id = $r->json('id');
        Reminder::whereKey($id)->update(['status' => 'sent']);

        $this->api('GET', "/reminders/$id")->assertJsonPath('message', 'Call mom');
        $this->api('PUT', "/reminders/$id", ['scheduled_at' => now()->addDay()->toIso8601String(), 'message' => 'Call mom'])->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_reminders_skip_closed_tasks_and_follow_the_current_telegram_link(): void
    {
        $t = Task::create(['user_id' => $this->u->id, 'title' => 'Done', 'status' => 'completed']);
        $a = Reminder::create(['user_id' => $this->u->id, 'task_id' => $t->id, 'scheduled_at' => now()->subMinute(), 'status' => 'pending', 'payload' => ['chat_id' => '5005']]);
        $b = Reminder::create(['user_id' => $this->u->id, 'scheduled_at' => now()->subMinute(), 'status' => 'pending', 'payload' => ['chat_id' => '5005', 'message' => 'hi']]);
        $this->u->update(['telegram_chat_id' => '6006']); // relinked to another chat
        $this->assertSame(1, app(ReminderService::class)->dispatchDue());
        $this->assertSame('cancelled', $a->fresh()->status);
        $this->assertSame('sent', $b->fresh()->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendMessage') && (string) $req['chat_id'] === '6006');

        $this->u->update(['telegram_chat_id' => null]);
        $c = Reminder::create(['user_id' => $this->u->id, 'scheduled_at' => now()->subMinute(), 'status' => 'pending', 'payload' => ['chat_id' => '6006', 'message' => 'x']]);
        app(ReminderService::class)->dispatchDue();
        $this->assertSame('cancelled', $c->fresh()->status);
    }

    public function test_deleting_a_task_removes_its_blocks_and_pending_reminders(): void
    {
        $t = Task::create(['user_id' => $this->u->id, 'title' => 'T']);
        ScheduleBlock::create(['user_id' => $this->u->id, 'task_id' => $t->id, 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $r = Reminder::create(['user_id' => $this->u->id, 'task_id' => $t->id, 'scheduled_at' => now()->addHour(), 'status' => 'pending', 'payload' => []]);
        $this->api('DELETE', "/tasks/{$t->id}")->assertSuccessful();
        $this->assertSame(0, ScheduleBlock::count());
        $this->assertSame('cancelled', $r->fresh()->status);
    }

    public function test_work_logs_keep_actual_minutes_in_step(): void
    {
        $t = Task::create(['user_id' => $this->u->id, 'title' => 'T']);
        $log = $this->api('POST', "/tasks/{$t->id}/execution-logs", ['started_at' => '2026-11-06T08:00:00Z', 'ended_at' => '2026-11-06T09:30:00Z'])->assertCreated();
        $this->assertSame(90, (int) $log->json('duration_minutes'));
        $this->assertSame(90, (int) $t->fresh()->actual_minutes);
        $this->api('PUT', "/tasks/{$t->id}/execution-logs/".$log->json('id'), ['ended_at' => '2026-11-06T09:00:00Z'])->assertOk();
        $this->assertSame(60, (int) $t->fresh()->actual_minutes);
        $this->api('DELETE', "/tasks/{$t->id}/execution-logs/".$log->json('id'))->assertNoContent();
        $this->assertSame(0, (int) $t->fresh()->actual_minutes);
    }

    public function test_logging_a_failure_keeps_the_description_and_lists_the_task(): void
    {
        $t = Task::create(['user_id' => $this->u->id, 'title' => 'T', 'description' => 'original notes']);
        $this->api('POST', "/tasks/{$t->id}/failure", ['reason' => 'no_time', 'note' => 'meetings ran long'])->assertOk();
        $t->refresh();
        $this->assertSame('original notes', $t->description);
        $this->assertSame('no_time', $t->failure_reason);
        $this->assertSame('meetings ran long', ActivityLog::where('action', 'failure_logged')->latest('id')->first()->after_json['failure_note']);
        $this->assertSame([$t->id], array_column($this->api('GET', '/tasks?failed=1&state=all')->json('data'), 'id'));
        $this->api('POST', "/tasks/{$t->id}/failure", ['reason' => 'nonsense'])->assertStatus(422);
    }

    public function test_stopped_recurring_task_can_be_resumed_or_deleted(): void
    {
        $id = $this->api('POST', '/recurring-tasks', ['title' => 'Read', 'frequency' => 'daily'])->assertCreated()->json('id');
        $this->api('POST', "/recurring-tasks/$id/stop")->assertOk();
        $before = Task::where('recurring_task_id', $id)->count();
        $this->api('POST', "/recurring-tasks/$id/resume")->assertOk()->assertJsonPath('status', 'active');
        $this->assertGreaterThan($before, Task::where('recurring_task_id', $id)->count());
        $this->assertSame(Task::where('recurring_task_id', $id)->count(), Task::where('recurring_task_id', $id)->distinct()->count('occurrence_date'), 'no duplicate dates');

        $this->api('DELETE', "/recurring-tasks/$id")->assertNoContent();
        $this->assertNull(RecurringTask::find($id));
    }

    public function test_daily_plan_edit_and_search_across_entities(): void
    {
        $plan = $this->api('GET', '/daily-plans/2026-11-06')->assertOk();
        $this->api('PUT', '/daily-plans/'.$plan->json('id'), ['available_minutes' => 240])->assertOk();
        $this->assertSame(240, (int) DailyPlan::find($plan->json('id'))->available_minutes);

        Goal::create(['user_id' => $this->u->id, 'title' => 'Learn Rust']);
        \App\Models\Note::create(['user_id' => $this->u->id, 'title' => 'n', 'content' => 'rust book ch.4']);
        $r = $this->api('GET', '/search?q=rust')->assertOk();
        $this->assertCount(1, $r->json('goals'));
        $this->assertCount(1, $r->json('notes'));
    }

    public function test_bot_explains_when_the_chat_is_linked_to_another_account(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'secret123', 'is_active' => true]);
        Cache::put('telegram:link:CODE1234', $other->id, now()->addMinutes(15));
        app(TelegramPlannerBotService::class)->start('5005', 'u', 'code1234');
        $this->assertNull($other->fresh()->telegram_chat_id);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendMessage') && str_contains((string) $req['text'], 'حساب دیگری'));
        $this->assertTrue(Cache::has('telegram:link:CODE1234'), 'the code is not burned');
    }

    public function test_bot_can_complete_archive_and_restore_a_project(): void
    {
        $p = Project::create(['user_id' => $this->u->id, 'title' => 'Website']);
        Task::create(['user_id' => $this->u->id, 'title' => 'Open one', 'project_id' => $p->id]);
        $bot = app(TelegramPlannerBotService::class);
        $cb = fn (string $d) => $bot->handleCallback('5005', 'u', 'cb-'.uniqid(), 10, $d);
        $last = function () {
            $out = '';
            foreach (Http::recorded() as [$req]) {
                if (preg_match('/(sendMessage|editMessageText)$/', $req->url())) $out = (string) ($req->data()['text'] ?? '');
            }
            return $out;
        };
        $keyboard = function () {
            $kb = [];
            foreach (Http::recorded() as [$req]) {
                if (preg_match('/(sendMessage|editMessageText)$/', $req->url())) $kb = json_decode($req->data()['reply_markup'] ?? '{}', true)['inline_keyboard'] ?? [];
            }
            return array_merge(...array_map(fn ($row) => array_column($row, 'callback_data'), $kb));
        };

        $cb("v:project:{$p->id}");
        $this->assertContains("lc:project:{$p->id}:complete", $keyboard());
        $this->assertContains("lc:project:{$p->id}:archive", $keyboard());

        $cb("lc:project:{$p->id}:complete");               // asks about the open task first
        $this->assertStringContainsString('کار باز', $last());
        $this->assertSame('active', $p->fresh()->status);
        $cb("lc:project:{$p->id}:complete:1");             // complete and cancel the open task
        $this->assertSame('completed', $p->fresh()->status);
        $this->assertSame('cancelled', Task::where('project_id', $p->id)->value('status'));
        $this->assertContains("lc:project:{$p->id}:reopen", $keyboard());

        $cb("lc:project:{$p->id}:archive");                // nothing open any more: no question
        $this->assertNotNull($p->fresh()->archived_at);
        $cb('ls:project:0');
        $this->assertStringNotContainsString('Website', $last());
        $cb('lsa:project:0');
        $this->assertStringContainsString('Website', $last());
        $cb("lc:project:{$p->id}:unarchive");
        $this->assertNull($p->fresh()->archived_at);

        // Someone else's project cannot be touched.
        $other = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'is_active' => true]);
        $theirs = Project::create(['user_id' => $other->id, 'title' => 'Theirs']);
        $cb("lc:project:{$theirs->id}:archive");
        $this->assertNull($theirs->fresh()->archived_at);
    }
}
