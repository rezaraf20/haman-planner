<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExecutionLog;
use App\Models\Goal;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use App\Services\Telegram\TelegramPlannerBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramBotLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private const SCREENS = ['home', 'today', 'tasks', 'tomorrow', 'inbox', 'structure', 'ls:area:0', 'ls:goal:0', 'ls:project:0', 'ls:milestone:0',
        'ls:task:0', 'execution', 'daily', 'calendar', 'xlogs', 'dependencies', 'ls:reminder:0', 'failures',
        'analysis', 'reviews', 'analytics', 'report', 'rday', 'rweek', 'rmonth',
        'knowledge', 'ls:note:0', 'ls:decision:0', 'aiinteractions', 'pending', 'activity', 'new:task', 'search'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test-token', 'cache.default' => 'array']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);
    }

    private function bot(): TelegramPlannerBotService
    {
        return app(TelegramPlannerBotService::class);
    }

    /** @return list<string> every text and button label sent to Telegram */
    private function outputs(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (!preg_match('/(sendMessage|editMessageText)$/', $request->url())) continue;
            $data = $request->data();
            $out[] = (string) ($data['text'] ?? '');
            foreach (isset($data['reply_markup']) ? (json_decode($data['reply_markup'], true)['inline_keyboard'] ?? []) : [] as $row) {
                foreach ($row as $button) $out[] = (string) $button['text'];
            }
        }
        return $out;
    }

    private function seedPlanner(User $u): Task
    {
        $goal = Goal::create(['user_id' => $u->id, 'title' => 'Ship v2', 'status' => 'active']);
        $task = Task::create(['user_id' => $u->id, 'title' => 'Write docs', 'status' => 'planned', 'goal_id' => $goal->id, 'due_at' => now()->addHours(3), 'estimated_minutes' => 30]);
        ExecutionLog::create(['user_id' => $u->id, 'task_id' => $task->id, 'started_at' => now()->subHour(), 'duration_minutes' => 25]);
        Review::create(['user_id' => $u->id, 'type' => 'weekly', 'period_start' => now()->subWeek(), 'period_end' => now(), 'metrics_json' => ['tasks_created' => 2, 'completion_rate' => 50]]);
        return $task;
    }

    public function test_english_user_gets_a_fully_english_bot(): void
    {
        $u = User::create(['name' => 'E', 'email' => 'e@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'telegram_chat_id' => '4004', 'locale' => 'en', 'timezone' => 'Europe/London']);
        $task = $this->seedPlanner($u);

        foreach (array_merge(self::SCREENS, ['v:task:'.$task->id, 'v:goal:'.$task->goal_id]) as $screen) {
            $this->bot()->handleCallback('4004', 'e', 'cb-'.$screen, 10, $screen);
        }
        $this->bot()->handleText('4004', 'e', '/start');

        $outputs = $this->outputs();
        $this->assertNotEmpty($outputs);
        foreach ($outputs as $text) {
            $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $text, 'Persian text leaked: '.$text);
            $this->assertDoesNotMatchRegularExpression('/\b(bot|planner|common)\.[a-z_]+/', $text, 'Raw translation key: '.$text);
        }
        $joined = implode("\n", $outputs);
        $this->assertStringContainsString('Write docs', $joined);
        $this->assertStringContainsString(__('planner.task_status.planned', [], 'en'), $joined);
        $this->assertStringNotContainsString('· planned', $joined);
        $this->assertStringNotContainsString('Error', $joined);
        // The locale is restored after handling the update.
        $this->assertSame(config('app.locale'), app()->getLocale());
    }

    public function test_persian_user_keeps_the_persian_bot(): void
    {
        $u = User::create(['name' => 'F', 'email' => 'f@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'telegram_chat_id' => '5005', 'locale' => 'fa']);
        $this->seedPlanner($u);
        $this->bot()->handleCallback('5005', 'f', 'cb-1', 10, 'home');
        $this->bot()->handleCallback('5005', 'f', 'cb-2', 10, 'today');
        foreach ($this->outputs() as $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(bot|planner)\.[a-z_]+/', $text);
        }
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', implode('', $this->outputs()));
    }

    public function test_unlinked_chat_follows_telegram_language_code(): void
    {
        $this->bot()->handleCallback('9998', 'x', 'cb-a', 1, 'today', 'en-GB');
        $english = last($this->outputs());
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $english);

        $this->bot()->handleCallback('9997', 'y', 'cb-b', 1, 'today', null);
        $this->assertStringContainsString('دسترسی', last($this->outputs()));
    }

    public function test_webhook_passes_language_code_to_the_bot(): void
    {
        config(['services.telegram.webhook_secret' => 'sec']);
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->postJson('/api/telegram/webhook', [
            'update_id' => 91,
            'message' => ['message_id' => 1, 'chat' => ['id' => 9996], 'from' => ['id' => 9996, 'username' => 'z', 'language_code' => 'en'], 'text' => '/start'],
        ])->assertOk();
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', last($this->outputs()));
    }
}
