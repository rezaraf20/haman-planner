<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlanProposal;
use App\Models\Task;
use App\Models\User;
use App\Services\Telegram\TelegramPlannerBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00', 'UTC'));
        config(['services.telegram.bot_token' => 'test-token', 'cache.default' => 'array', 'services.ai.api_key' => null]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function texts(): array
    {
        return collect(Http::recorded())->filter(fn ($p) => preg_match('/(sendMessage|editMessageText)$/', $p[0]->url()))
            ->map(fn ($p) => (string) ($p[0]->data()['text'] ?? '').' '.($p[0]->data()['reply_markup'] ?? ''))->values()->all();
    }

    public function test_plan_my_day_in_telegram_needs_an_explicit_apply(): void
    {
        $u = User::create(['name' => 'E', 'email' => 'e@example.com', 'password' => 'secret123', 'is_active' => true, 'telegram_chat_id' => '777', 'locale' => 'en', 'timezone' => 'UTC',
            'preferences' => ['work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '17:00']]);
        $t = Task::create(['user_id' => $u->id, 'title' => 'Write proposal', 'status' => 'ready', 'priority' => 'p1', 'estimated_minutes' => 60]);
        $bot = app(TelegramPlannerBotService::class);

        $bot->handleCallback('777', 'e', 'cb1', 5, 'hplan:day');
        $out = implode("\n", $this->texts());
        $this->assertStringContainsString('Write proposal', $out);
        $this->assertStringContainsString('Because:', $out);
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $out);
        $this->assertNull($t->fresh()->planned_start, 'nothing changes before Apply');

        $p = PlanProposal::withoutGlobalScopes()->first();
        $this->assertStringContainsString('hpapply:'.$p->id, $out);
        $bot->handleCallback('777', 'e', 'cb2', 5, 'hpapply:'.$p->id);
        $this->assertNotNull($t->fresh()->planned_start);
        $this->assertSame('applied', $p->fresh()->status);

        // Free text in AI mode is routed to the planner.
        $bot->handleCallback('777', 'e', 'cb3', 5, 'aiplanner');
        $bot->handleText('777', 'e', 'what should I work on now?');
        $this->assertStringContainsString('What should I work on now?', implode("\n", $this->texts()));

        // Another user can't apply this proposal.
        User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'is_active' => true, 'telegram_chat_id' => '888']);
        $other = PlanProposal::create(['user_id' => $u->id, 'kind' => 'day', 'period_start' => '2026-10-05', 'period_end' => '2026-10-05', 'actions' => [], 'expires_at' => now()->addDay()]);
        $bot->handleCallback('888', 'x', 'cb4', 5, 'hpapply:'.$other->id);
        $this->assertSame('pending', $other->fresh()->status);
    }
}
