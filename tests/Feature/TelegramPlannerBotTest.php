<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\Area;
use App\Models\ExecutionLog;
use App\Models\Goal;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\Planner\AnalyticsService;
use App\Services\Telegram\TelegramPlannerBotService;
use App\Support\PlannerUserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramPlannerBotTest extends TestCase
{
    use RefreshDatabase;

    private User $a;
    private User $b;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'test-key',
            'services.ai.base_url' => 'https://ai.test/v1',
            'services.ai.model' => 'test-model',
            'cache.default' => 'array',
        ]);
        Http::fake([
            'https://ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'summary' => 'Focus on your top task',
                'risks' => ['Deadline is close'],
                'recommendations' => [['action' => 'Start Alpha report first']],
            ])]]]], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]], 200),
        ]);
        $this->a = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'telegram_chat_id' => '1001']);
        $this->b = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'telegram_chat_id' => '2002']);
    }

    private function bot(): TelegramPlannerBotService
    {
        return app(TelegramPlannerBotService::class);
    }

    private function cb(User $u, string $data): void
    {
        $this->bot()->handleCallback($u->telegram_chat_id, 'user', 'cb-'.uniqid(), 100, $data);
    }

    private function say(User $u, string $text): void
    {
        $this->bot()->handleText($u->telegram_chat_id, 'user', $text);
    }

    private function task(User $u, string $title, array $extra = []): Task
    {
        return Task::create(['user_id' => $u->id, 'title' => $title, 'status' => 'inbox', 'importance' => 50, 'weight' => 1] + $extra);
    }

    /** @return array<int,array{text:string,keyboard:array}> Telegram messages sent/edited so far */
    private function messages(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (!str_contains($request->url(), 'api.telegram.org') || !preg_match('/(sendMessage|editMessageText)$/', $request->url())) {
                continue;
            }
            $data = $request->data();
            $out[] = ['text' => (string) ($data['text'] ?? ''), 'keyboard' => isset($data['reply_markup']) ? json_decode($data['reply_markup'], true)['inline_keyboard'] : []];
        }
        return $out;
    }

    private function lastText(): string
    {
        $m = $this->messages();
        return $m === [] ? '' : end($m)['text'];
    }

    private function allCallbacks(): array
    {
        $out = [];
        foreach ($this->messages() as $m) {
            foreach ($m['keyboard'] as $row) {
                $this->assertIsArray($row);
                foreach ($row as $button) {
                    $this->assertIsArray($button);
                    $this->assertArrayHasKey('text', $button);
                    $out[] = $button['callback_data'] ?? '';
                }
            }
        }
        return $out;
    }

    private function state(User $u): array
    {
        return Cache::get('telegram:planner:state:'.$u->telegram_chat_id, []);
    }

    public function test_a_user_cannot_see_another_users_task(): void
    {
        $foreign = $this->task($this->b, 'B secret task');
        $this->task($this->a, 'A visible task');

        $this->cb($this->a, 'v:task:'.$foreign->id);
        $this->assertStringNotContainsString('B secret task', $this->lastText());
        $this->assertStringContainsString('پیدا نشد', $this->lastText());

        $this->cb($this->a, 'ls:task:0');
        $this->assertStringContainsString('A visible task', $this->lastText());
        $this->assertStringNotContainsString('B secret task', $this->lastText());

        foreach (['today', 'inbox', 'tomorrow', 'calendar', 'xlogs', 'dependencies', 'daily', 'reviews', 'analytics', 'rweek', 'aiinteractions', 'pending', 'activity', 'failures'] as $screen) {
            $this->cb($this->a, $screen);
            $this->assertStringNotContainsString('B secret task', $this->lastText(), $screen);
        }
    }

    public function test_a_user_cannot_edit_another_users_task(): void
    {
        $foreign = $this->task($this->b, 'B original');

        $this->cb($this->a, 'f:task:'.$foreign->id.':title');
        $this->say($this->a, 'hacked');
        $this->cb($this->a, 'done:'.$foreign->id);
        $this->cb($this->a, 'defer:'.$foreign->id);
        $this->cb($this->a, 'tcancel:'.$foreign->id);

        $foreign->refresh();
        $this->assertSame('B original', $foreign->title);
        $this->assertSame('inbox', $foreign->status);
    }

    public function test_a_user_cannot_delete_another_users_task(): void
    {
        $foreign = $this->task($this->b, 'B keep me');

        $this->cb($this->a, 'del:task:'.$foreign->id);
        $this->cb($this->a, 'delok:task:'.$foreign->id);

        $this->assertNotNull(Task::find($foreign->id));
    }

    public function test_owner_can_edit_complete_and_delete_own_task(): void
    {
        $own = $this->task($this->a, 'Old title');

        $this->cb($this->a, 'f:task:'.$own->id.':title');
        $this->say($this->a, 'New title');
        $this->assertSame('New title', $own->fresh()->title);
        $this->assertSame([], $this->state($this->a));

        $this->cb($this->a, 'done:'.$own->id);
        $this->assertSame('completed', $own->fresh()->status);

        $this->cb($this->a, 'delok:task:'.$own->id);
        $this->assertNull(Task::find($own->id));
    }

    public function test_search_only_returns_own_data(): void
    {
        $this->task($this->a, 'Alpha mine');
        $this->task($this->b, 'Alpha foreign');
        Goal::create(['user_id' => $this->a->id, 'title' => 'Alpha goal mine']);
        Goal::create(['user_id' => $this->b->id, 'title' => 'Alpha goal foreign']);
        Note::create(['user_id' => $this->b->id, 'title' => 'Alpha note foreign', 'content' => 'x']);

        $this->cb($this->a, 'search');
        $this->say($this->a, 'Alpha');

        $text = $this->lastText();
        $this->assertStringContainsString('Alpha mine', $text);
        $this->assertStringContainsString('Alpha goal mine', $text);
        $this->assertStringNotContainsString('foreign', $text);
        $this->assertSame([], $this->state($this->a));
    }

    public function test_ai_mode_accepts_text_and_only_sends_own_tasks(): void
    {
        $this->task($this->a, 'Alpha report');
        $this->task($this->b, 'Bravo confidential');

        $this->cb($this->a, 'aiplanner');
        $this->assertSame('ai', $this->state($this->a)['mode'] ?? null);
        $this->say($this->a, 'What should I focus on?');

        $aiRequests = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'ai.test'));
        $this->assertCount(1, $aiRequests);
        $body = $aiRequests->first()[0]->body();
        $this->assertStringContainsString('Alpha report', $body);
        $this->assertStringNotContainsString('Bravo confidential', $body);

        $text = $this->lastText();
        $this->assertStringContainsString('Focus on your top task', $text);
        $this->assertStringContainsString('Deadline is close', $text);
        $this->assertStringContainsString('Start Alpha report first', $text);
        $this->assertStringContainsString('هیچ تغییری', $text);
        $this->assertSame([], $this->state($this->a));
        $this->assertSame($this->a->id, (int) AiInteraction::query()->latest('id')->value('user_id'));
    }

    public function test_create_task_from_telegram_assigns_user(): void
    {
        $this->cb($this->a, 'new:task');
        $this->say($this->a, 'Buy milk');
        $this->assertContains('wz:skip', $this->allCallbacks());
        $this->cb($this->a, 'wz:fin');
        $this->assertStringContainsString('Buy milk', $this->lastText());
        $this->cb($this->a, 'wz:ok');

        $task = Task::where('title', 'Buy milk')->first();
        $this->assertNotNull($task);
        $this->assertSame($this->a->id, (int) $task->user_id);
        $this->assertSame('inbox', $task->status);
        $this->assertSame([], $this->state($this->a));
    }

    public function test_create_goal_from_telegram_assigns_user_with_skips_and_enum(): void
    {
        $this->cb($this->a, 'new:goal');
        $this->say($this->a, 'Grow revenue');   // title
        $this->cb($this->a, 'wz:skip');          // description
        $this->cb($this->a, 'wz:null');          // area_id (no value)
        $this->cb($this->a, 'wz:o:0');           // status -> first real option
        $this->cb($this->a, 'wz:q:75');          // importance
        $this->cb($this->a, 'wz:fin');
        $this->cb($this->a, 'wz:ok');

        $goal = Goal::where('title', 'Grow revenue')->first();
        $this->assertNotNull($goal);
        $this->assertSame($this->a->id, (int) $goal->user_id);
        $this->assertNull($goal->area_id);
        $this->assertSame('active', $goal->status);
        $this->assertSame(75, (int) $goal->importance);
    }

    public function test_reference_picker_cannot_select_another_users_object(): void
    {
        $foreignArea = Area::create(['user_id' => $this->b->id, 'name' => 'B area']);
        $ownArea = Area::create(['user_id' => $this->a->id, 'name' => 'A area']);

        $this->cb($this->a, 'new:note');
        $this->say($this->a, 'Note title');
        $this->say($this->a, 'Note body');
        $this->assertStringNotContainsString('B area', $this->lastText());
        $this->assertNotContains('wz:p:'.$foreignArea->id, $this->allCallbacks());

        $this->cb($this->a, 'wz:p:'.$foreignArea->id);
        $this->assertArrayNotHasKey('area_id', $this->state($this->a)['data']);

        $this->cb($this->a, 'wz:p:'.$ownArea->id);
        $this->cb($this->a, 'wz:fin');
        $this->cb($this->a, 'wz:ok');

        $note = Note::where('title', 'Note title')->first();
        $this->assertSame($this->a->id, (int) $note->user_id);
        $this->assertSame($ownArea->id, (int) $note->area_id);
    }

    public function test_skip_reference_sets_null(): void
    {
        $this->cb($this->a, 'new:note');
        $this->say($this->a, 'N');
        $this->say($this->a, 'Body');
        $this->assertContains('wz:null', $this->allCallbacks());
        $this->cb($this->a, 'wz:null');
        $this->assertArrayHasKey('area_id', $this->state($this->a)['data']);
        $this->assertNull($this->state($this->a)['data']['area_id']);
        $this->assertSame('goal_id', $this->state($this->a)['steps'][$this->state($this->a)['step']]);
    }

    public function test_execution_log_only_accepts_own_task(): void
    {
        $foreign = $this->task($this->b, 'B task');
        $own = $this->task($this->a, 'A task');

        $this->cb($this->a, 'texec:'.$foreign->id);
        $this->assertSame([], $this->state($this->a));

        $this->cb($this->a, 'newexec');
        $this->cb($this->a, 'wz:p:'.$foreign->id);
        $this->assertArrayNotHasKey('task_id', $this->state($this->a)['data']);

        $this->cb($this->a, 'wz:p:'.$own->id);
        $this->cb($this->a, 'wz:q:now');       // started_at
        $this->cb($this->a, 'wz:skip');        // ended_at
        $this->say($this->a, '30');            // duration
        $this->cb($this->a, 'wz:q:75');        // focus
        $this->cb($this->a, 'wz:fin');
        $this->cb($this->a, 'wz:ok');

        $log = ExecutionLog::first();
        $this->assertNotNull($log);
        $this->assertSame($own->id, (int) $log->task_id);
        $this->assertSame($this->a->id, (int) $log->user_id);
        $this->assertSame(30, (int) $log->duration_minutes);
        $this->assertSame(75, (int) $log->focus_level);
        $this->assertSame(30, (int) $own->fresh()->actual_minutes);
        $this->assertSame(0, ExecutionLog::where('task_id', $foreign->id)->count());
    }

    public function test_dependency_only_allows_own_tasks(): void
    {
        $one = $this->task($this->a, 'A one');
        $two = $this->task($this->a, 'A two');
        $foreign = $this->task($this->b, 'B task');

        $this->cb($this->a, 'newdep');
        $this->cb($this->a, 'wz:p:'.$one->id);
        $this->cb($this->a, 'wz:p:'.$foreign->id);
        $this->assertArrayNotHasKey('depends_on_task_id', $this->state($this->a)['data']);
        $this->cb($this->a, 'wz:p:'.$two->id);
        $this->cb($this->a, 'wz:o:0');
        $this->cb($this->a, 'wz:ok');

        $dep = TaskDependency::first();
        $this->assertNotNull($dep);
        $this->assertSame([$one->id, $two->id, 'requires', $this->a->id], [(int) $dep->task_id, (int) $dep->depends_on_task_id, $dep->type, (int) $dep->user_id]);
        $this->assertSame(0, TaskDependency::where('depends_on_task_id', $foreign->id)->count());

        // B cannot delete A's dependency.
        $this->cb($this->b, 'depdelok:'.$dep->id);
        $this->assertNotNull(TaskDependency::find($dep->id));
        $this->cb($this->a, 'depdelok:'.$dep->id);
        $this->assertNull(TaskDependency::find($dep->id));
    }

    public function test_schedule_block_only_allows_own_task(): void
    {
        $own = $this->task($this->a, 'A focus');
        $foreign = $this->task($this->b, 'B focus');

        $this->cb($this->a, 'newsched');
        $this->cb($this->a, 'wz:p:'.$foreign->id);
        $this->assertArrayNotHasKey('task_id', $this->state($this->a)['data']);
        $this->cb($this->a, 'wz:p:'.$own->id);
        $this->say($this->a, '2026-10-01 10:00');
        $this->say($this->a, '2026-10-01 11:30');
        $this->cb($this->a, 'wz:ok');

        $block = ScheduleBlock::first();
        $this->assertNotNull($block);
        $this->assertSame($own->id, (int) $block->task_id);
        $this->assertSame($this->a->id, (int) $block->user_id);
        $this->assertSame('2026-10-01 10:00', $block->starts_at->timezone('Asia/Tehran')->format('Y-m-d H:i'));
        $this->assertSame(0, ScheduleBlock::where('task_id', $foreign->id)->count());
    }

    public function test_cancel_wizard_clears_state(): void
    {
        $this->cb($this->a, 'new:task');
        $this->say($this->a, 'Should not exist');
        $this->assertSame('wizard', $this->state($this->a)['mode']);
        $this->cb($this->a, 'wz:x');
        $this->assertSame([], $this->state($this->a));
        $this->say($this->a, 'still nothing');
        $this->assertSame(0, Task::count());
    }

    public function test_reminder_creation_preserves_worker_payload(): void
    {
        $own = $this->task($this->a, 'A task');
        $this->cb($this->a, 'trem:'.$own->id);
        $this->cb($this->a, 'wz:o:0');            // type telegram
        $this->say($this->a, '2026-10-02 08:15');  // scheduled_at
        $this->say($this->a, 'Call the client');   // message
        $this->cb($this->a, 'wz:ok');

        $r = Reminder::first();
        $this->assertSame([$this->a->id, $own->id, 'telegram', 'pending'], [(int) $r->user_id, (int) $r->task_id, $r->type, $r->status]);
        $this->assertSame(['chat_id' => '1001', 'message' => 'Call the client'], $r->payload);
    }

    public function test_all_navigation_paths_exist_without_web_redirects(): void
    {
        $screens = ['home', 'today', 'tasks', 'tomorrow', 'inbox', 'structure', 'ls:area:0', 'ls:goal:0', 'ls:project:0', 'ls:milestone:0',
            'execution', 'daily', 'calendar', 'xlogs', 'dependencies', 'ls:reminder:0', 'failures',
            'analysis', 'reviews', 'analytics', 'report', 'rday', 'rweek', 'rmonth',
            'knowledge', 'ls:note:0', 'ls:decision:0', 'aiinteractions', 'pending', 'activity'];
        foreach ($screens as $s) {
            $this->cb($this->a, $s);
            $text = $this->lastText();
            $this->assertStringNotContainsString('❌ خطا', $text, $s);
            $this->assertStringNotContainsString('نسخه وب', $text, $s);
            $this->assertStringNotContainsString('پنل وب', $text, $s);
        }
        $callbacks = $this->allCallbacks();
        foreach (['today', 'tasks', 'inbox', 'search', 'structure', 'execution', 'analysis', 'knowledge', 'new:task', 'tomorrow',
            'ls:area:0', 'ls:goal:0', 'ls:project:0', 'ls:milestone:0', 'daily', 'calendar', 'xlogs', 'dependencies', 'ls:reminder:0', 'failures',
            'reviews', 'analytics', 'report', 'ls:note:0', 'ls:decision:0', 'aiplanner', 'aiinteractions', 'pending', 'activity'] as $expected) {
            $this->assertContains($expected, $callbacks);
        }
        foreach ($callbacks as $data) {
            $this->assertLessThanOrEqual(64, strlen($data));
        }
    }

    public function test_invalid_callback_data_fails_gracefully(): void
    {
        foreach (['v:task:abc', 'v:unknown:1', 'delok:task:', 'wz:p:5', 'sb:999', 'xl:-1', 'depdel:x', 'f:task:1:user_id', '::::'] as $data) {
            $this->cb($this->a, $data);
        }
        $answered = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), 'answerCallbackQuery'))->count();
        $this->assertSame(9, $answered);
        $this->assertSame(0, Task::count());
    }

    public function test_unlinked_chat_has_no_access(): void
    {
        $this->bot()->handleCallback('9999', 'stranger', 'cb-x', 1, 'ls:task:0');
        $this->assertStringContainsString('دسترسی', $this->lastText());
    }

    public function test_existing_telegram_link_and_unlink_still_work(): void
    {
        $user = User::create(['name' => 'C', 'email' => 'c@example.com', 'password' => 'secret-pass-123', 'is_active' => true]);
        Cache::put('telegram:link:ABCD1234', $user->id, now()->addMinutes(15));

        $this->bot()->start('3003', 'cuser', 'abcd1234');
        $user->refresh();
        $this->assertSame('3003', $user->telegram_chat_id);
        $this->assertSame('cuser', $user->telegram_username);

        $this->actingAs($user)->post('/settings/telegram/unlink')->assertRedirect();
        $this->assertNull($user->fresh()->telegram_chat_id);

        $this->bot()->handleCallback('3003', 'cuser', 'cb-y', 1, 'today');
        $this->assertStringContainsString('دسترسی', $this->lastText());
    }

    public function test_web_creation_assigns_authenticated_user_and_explicit_owner_wins(): void
    {
        $this->actingAs($this->a);
        $task = Task::create(['title' => 'web task']);
        $this->assertSame($this->a->id, (int) $task->user_id);

        $explicit = Task::create(['title' => 'explicit', 'user_id' => $this->b->id]);
        $this->assertSame($this->b->id, (int) $explicit->user_id);

        PlannerUserContext::set($this->b->id);
        try {
            $this->assertSame($this->b->id, (int) Goal::create(['title' => 'ctx'])->user_id);
        } finally {
            PlannerUserContext::clear();
        }
    }

    public function test_analytics_are_user_scoped(): void
    {
        $this->task($this->a, 'A1');
        $this->task($this->b, 'B1');
        $this->task($this->b, 'B2');
        $from = Carbon::now()->subDay();
        $to = Carbon::now()->addDay();
        $service = app(AnalyticsService::class);
        $this->assertSame(1, $service->summary($from, $to, $this->a->id)['tasks_created']);
        $this->assertSame(2, $service->summary($from, $to, $this->b->id)['tasks_created']);
        $this->assertSame(3, $service->summary($from, $to)['tasks_created']);
    }

    public function test_daily_plan_create_and_update_persist_values(): void
    {
        $this->cb($this->a, 'dplan:today');
        $this->say($this->a, '480');   // available_minutes
        $this->say($this->a, '300');   // planned_minutes
        $this->cb($this->a, 'wz:fin');
        $this->cb($this->a, 'wz:ok');

        $plan = \App\Models\DailyPlan::where('user_id', $this->a->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame([480, 300], [(int) $plan->available_minutes, (int) $plan->planned_minutes]);

        // Same date for another user must not collide with A's plan.
        $this->cb($this->b, 'dplan:today');
        $this->say($this->b, '120');
        $this->cb($this->b, 'wz:fin');
        $this->cb($this->b, 'wz:ok');
        $this->assertSame(2, \App\Models\DailyPlan::count());

        $this->cb($this->a, 'dplan:today');
        $this->say($this->a, '500');
        $this->cb($this->a, 'wz:fin');
        $this->cb($this->a, 'wz:ok');
        $this->assertSame([500, 300], [(int) $plan->fresh()->available_minutes, (int) $plan->fresh()->planned_minutes]);
    }

    public function test_explicit_priority_survives_unrelated_edit(): void
    {
        $own = $this->task($this->a, 'Prioritized');
        $this->cb($this->a, 'f:task:'.$own->id.':priority');
        $this->cb($this->a, 'wz:o:0');
        $this->assertSame('p0', $own->fresh()->priority);

        $this->cb($this->a, 'f:task:'.$own->id.':planned_start');
        $this->say($this->a, '2026-10-01 10:00');
        $this->assertSame('p0', $own->fresh()->priority);
    }

    public function test_user_context_is_cleared_after_update(): void
    {
        $this->cb($this->a, 'today');
        $this->assertNull(PlannerUserContext::id());
    }
}
