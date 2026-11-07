<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DayRitual;
use App\Models\FocusSession;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Telegram\TelegramPlannerBotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Bot screens for focus, rituals, smart lists, breakdown and estimates. */
final class TelegramGrowthBotTest extends TestCase
{
    use RefreshDatabase;

    private User $a;
    private User $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2025-11-12 06:30:00', 'UTC')); // Wed 10:00 Tehran
        config(['services.telegram.bot_token' => 'test-token', 'cache.default' => 'array', 'services.ai.api_key' => null]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]], 200)]);
        $this->a = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'telegram_chat_id' => '1001', 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
        $this->b = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'telegram_chat_id' => '2002', 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
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
        return Task::create(['user_id' => $u->id, 'title' => $title] + $extra);
    }

    /** @return list<array{chat:string,text:string,callbacks:list<string>}> */
    private function messages(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (!preg_match('/(sendMessage|editMessageText)$/', $request->url())) continue;
            $d = $request->data();
            $cbs = [];
            foreach (isset($d['reply_markup']) ? (json_decode($d['reply_markup'], true)['inline_keyboard'] ?? []) : [] as $row) {
                foreach ($row as $b) $cbs[] = (string) ($b['callback_data'] ?? '');
            }
            $out[] = ['chat' => (string) ($d['chat_id'] ?? ''), 'text' => (string) ($d['text'] ?? ''), 'callbacks' => $cbs];
        }
        return $out;
    }

    private function last(): array
    {
        $m = $this->messages();
        return end($m) ?: ['chat' => '', 'text' => '', 'callbacks' => []];
    }

    public function test_menus_and_task_card_offer_the_new_features(): void
    {
        $t = $this->task($this->a, 'Write report');
        $tag = Tag::create(['user_id' => $this->a->id, 'name' => 'work']);
        $t->tags()->attach($tag->id);
        $this->task($this->a, 'Step one', ['parent_task_id' => $t->id]);

        $this->cb($this->a, 'home');
        $this->assertContains('fc', $this->last()['callbacks']);
        $this->assertContains('rit', $this->last()['callbacks']);
        $this->cb($this->a, 'tasks');
        $this->assertContains('sl', $this->last()['callbacks']);

        $this->cb($this->a, 'v:task:'.$t->id);
        $card = $this->last();
        $this->assertStringContainsString('#work', $card['text']);
        $this->assertStringContainsString('زیرکار', $card['text']);
        foreach (['fcs:'.$t->id.':0', 'bd:'.$t->id, 'est:'.$t->id] as $cb) $this->assertContains($cb, $card['callbacks']);
    }

    public function test_morning_and_evening_rituals_from_the_bot(): void
    {
        $late = $this->task($this->a, 'Late one', ['deadline' => '2025-11-10 12:00:00']);
        $today = $this->task($this->a, 'Today one', ['deadline' => '2025-11-12 16:00:00']);

        $this->cb($this->a, 'rit');
        $this->cb($this->a, 'rm');
        $this->assertStringContainsString('Late one', $this->last()['text']);
        $this->cb($this->a, 'rme:4');
        $this->cb($this->a, 'rmm:'.$late->id.':n');
        $this->cb($this->a, 'rmi');
        $this->say($this->a, 'Ship the report');
        $picks = array_values(array_filter($this->last()['callbacks'], fn ($c) => str_starts_with($c, 'rmp:')));
        $this->assertContains('rmp:'.$today->id, $picks);
        $this->assertSame(0, DayRitual::count(), 'nothing is saved before the final button');

        $this->cb($this->a, 'rms');
        $m = DayRitual::where('kind', 'morning')->firstOrFail();
        $this->assertSame(4, $m->energy);
        $this->assertSame('Ship the report', $m->intention);
        $this->assertNotEmpty($m->top_task_ids);
        $this->assertSame('2025-11-13', $late->fresh()->planned_start->setTimezone('Asia/Tehran')->toDateString());
        $this->assertSame('2025-11-10 12:00:00', $late->fresh()->deadline->format('Y-m-d H:i:s'), 'deadline unchanged');

        $this->cb($this->a, 're');
        $this->cb($this->a, 'rem:5');
        $this->cb($this->a, 'rew');
        $this->say($this->a, 'Finished the draft');
        $this->cb($this->a, 'rmv:'.$today->id.':n');
        $this->cb($this->a, 'res');
        $e = DayRitual::where('kind', 'evening')->firstOrFail();
        $this->assertSame(5, $e->mood);
        $this->assertSame('Finished the draft', $e->wins);
        $this->assertSame('2025-11-13', $today->fresh()->planned_start->setTimezone('Asia/Tehran')->toDateString());

        // Another user's task id in a callback is rejected by the service.
        $foreign = $this->task($this->b, 'Not yours');
        $this->cb($this->a, 'rm');
        $this->cb($this->a, 'rmp:'.$foreign->id);
        $this->cb($this->a, 'rms');
        $this->assertStringContainsString('⚠️', $this->last()['text']);
        $this->assertNull($foreign->fresh()->planned_start);
    }

    public function test_focus_from_the_bot_and_time_up_message(): void
    {
        $t = $this->task($this->a, 'Deep work');
        $this->cb($this->a, 'fcs:'.$t->id.':25');
        $s = FocusSession::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('running', $s->status);
        $this->assertStringStartsWith(TelegramPlannerBotService::FOCUS_UUID_PREFIX, (string) $s->client_uuid);

        $this->cb($this->a, 'fcp');
        $this->assertSame('paused', $s->fresh()->status);
        $this->cb($this->a, 'fcr');
        $this->assertSame('running', $s->fresh()->status);

        $this->travel(26)->minutes();
        $this->artisan('planner:bot-nudges')->assertSuccessful();
        $this->artisan('planner:bot-nudges')->assertSuccessful();
        $up = array_filter($this->messages(), fn ($m) => $m['chat'] === '1001' && str_starts_with($m['text'], '⏰') && str_contains($m['text'], 'Deep work'));
        $this->assertCount(1, $up, 'time-up message once');

        $this->cb($this->a, 'fcf');
        $this->assertSame('completed', $s->fresh()->status);
        $this->assertNotNull($s->fresh()->execution_log_id);
        $this->assertGreaterThan(0, (int) $t->fresh()->actual_minutes);

        $foreign = $this->task($this->b, 'Not yours');
        $this->cb($this->a, 'fcs:'.$foreign->id.':25');
        $this->assertSame(1, FocusSession::withoutGlobalScopes()->count());
    }

    public function test_smart_lists_and_tags_are_user_scoped(): void
    {
        $this->task($this->a, 'Mine late', ['deadline' => '2025-11-10 12:00:00']);
        $this->task($this->b, 'Theirs late', ['deadline' => '2025-11-10 12:00:00']);
        $tag = Tag::create(['user_id' => $this->b->id, 'name' => 'secret']);

        $this->cb($this->a, 'slv:overdue:0');
        $this->assertContains('Mine late', $this->buttonTexts());
        $this->assertNotContains('Theirs late', $this->buttonTexts());
        $this->cb($this->a, 'slt:'.$tag->id.':0');
        $this->assertStringContainsString('پیدا نشد', $this->last()['text']);
        $this->cb($this->a, 'sl');
        $this->assertNotContains('slt:'.$tag->id.':0', $this->last()['callbacks']);
    }

    private function buttonTexts(): array
    {
        $out = [];
        foreach (Http::recorded() as [$r]) {
            $d = $r->data();
            foreach (isset($d['reply_markup']) ? (json_decode($d['reply_markup'], true)['inline_keyboard'] ?? []) : [] as $row) {
                foreach ($row as $b) $out[] = trim(explode(' · ', (string) $b['text'])[0]);
            }
        }
        return $out;
    }

    public function test_breakdown_and_estimate_from_the_bot(): void
    {
        $t = $this->task($this->a, 'Launch page', ['estimated_minutes' => 30]);
        $this->cb($this->a, 'bd:'.$t->id);
        $this->assertNotContains('bdai:'.$t->id, $this->last()['callbacks'], 'no AI configured: no AI button');
        $this->cb($this->a, 'bdadd:'.$t->id);
        $this->say($this->a, "Write copy - ۳۰\nDesign hero | 90\n\nReview");
        $kids = Task::where('parent_task_id', $t->id)->orderBy('id')->get();
        $this->assertSame(['Write copy', 'Design hero', 'Review'], $kids->pluck('title')->all());
        $this->assertSame([30, 90, 0], $kids->pluck('estimated_minutes')->map(fn ($m) => (int) $m)->all());

        // AI proposal → only created after the confirm button.
        $this->seed(\Database\Seeders\PlanSeeder::class);
        config(['services.ai.provider' => 'openai', 'services.ai.api_key' => 'k', 'services.ai.base_url' => 'https://ai.test/v1', 'services.ai.model' => 'm']);
        Http::fake(['https://ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['steps' => [['title' => 'Collect assets', 'estimated_minutes' => 20]]])]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);
        $t2 = $this->task($this->a, 'Plan event');
        $this->cb($this->a, 'bdai:'.$t2->id);
        $this->assertSame(0, Task::where('parent_task_id', $t2->id)->count());
        $this->assertContains('bdok:'.$t2->id, $this->last()['callbacks']);
        $this->cb($this->a, 'bdok:'.$t2->id);
        $this->assertSame(['Collect assets'], Task::where('parent_task_id', $t2->id)->pluck('title')->all());
        $this->cb($this->a, 'bdok:'.$t2->id); // replay: state is gone, nothing created twice
        $this->assertSame(1, Task::where('parent_task_id', $t2->id)->count());

        // Other users' tasks.
        $this->cb($this->b, 'bd:'.$t->id);
        $this->assertStringContainsString('پیدا نشد', $this->last()['text']);
        $this->cb($this->b, 'estok:'.$t->id.':60');
        $this->assertSame(30, (int) $t->fresh()->estimated_minutes);

        // Estimate: suggestion only applied by the button, and only valid values.
        foreach ([50, 60, 70] as $i => $act) $this->task($this->a, 'Launch page v'.$i, ['status' => 'completed', 'completed_at' => now()->subDay(), 'actual_minutes' => $act, 'estimated_minutes' => 30]);
        $this->cb($this->a, 'est:'.$t->id);
        $this->assertContains('estok:'.$t->id.':60', $this->last()['callbacks']);
        $this->assertSame(30, (int) $t->fresh()->estimated_minutes);
        $this->cb($this->a, 'estok:'.$t->id.':99999');
        $this->assertSame(30, (int) $t->fresh()->estimated_minutes);
        $this->cb($this->a, 'estok:'.$t->id.':60');
        $this->assertSame(60, (int) $t->fresh()->estimated_minutes);
    }

    public function test_ritual_reminders_are_opt_in_and_sent_once(): void
    {
        $this->artisan('planner:bot-nudges')->assertSuccessful();
        $this->assertSame([], $this->messages(), 'off by default');

        $this->a->update(['preferences' => ['ritual_bot_nudges' => true]]);
        $this->artisan('planner:bot-nudges')->assertSuccessful();
        $this->artisan('planner:bot-nudges')->assertSuccessful();
        $sent = $this->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('1001', $sent[0]['chat']);
        $this->assertContains('rm', $sent[0]['callbacks']);

        // Done for today → no evening reminder until the end of the workday, and none on a day off.
        $this->travelTo(CarbonImmutable::parse('2025-11-13 06:30:00', 'UTC')); // Thursday: not a fa working day
        $this->artisan('planner:bot-nudges')->assertSuccessful();
        $this->assertCount(1, $this->messages());
    }
}
