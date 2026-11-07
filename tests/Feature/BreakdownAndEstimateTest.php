<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\ApiToken;
use App\Models\Project;
use App\Models\Task;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BreakdownAndEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $u;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai.api_key' => null]);
        $this->travelTo(CarbonImmutable::parse('2025-11-12 06:30:00', 'UTC'));
        $this->u = $this->user('u@example.com');
        $this->h = $this->auth($this->u);
    }

    private function user(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'en', 'timezone' => 'Asia/Tehran']);
    }

    private function auth(User $u): array
    {
        $plain = 'bd-'.$u->id.str_repeat('b', 40);
        ApiToken::create(['user_id' => $u->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function api(string $m, string $url, array $data = [], ?array $h = null)
    {
        return $this->withHeaders($h ?? $this->h)->json($m, '/api'.$url, $data);
    }

    private function ai(array ...$contents): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        config(['services.ai.provider' => 'openai', 'services.ai.api_key' => 'k', 'services.ai.base_url' => 'https://ai.test/v1', 'services.ai.model' => 'm']);
        $seq = Http::sequence();
        foreach ($contents as $c) $seq->push(['choices' => [['message' => ['content' => json_encode($c)]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]);
        Http::fake(['https://ai.test/*' => $seq]);
    }

    public function test_manual_breakdown_creates_subtasks_that_inherit_context(): void
    {
        $p = Project::create(['user_id' => $this->u->id, 'title' => 'Website']);
        $parent = Task::create(['user_id' => $this->u->id, 'title' => 'Launch landing page', 'project_id' => $p->id, 'importance' => 80]);

        $show = $this->api('GET', '/tasks/'.$parent->id.'/breakdown')->assertOk();
        $this->assertFalse($show->json('ai_available'));
        $this->api('POST', '/tasks/'.$parent->id.'/breakdown')->assertStatus(503); // no AI configured: clear message, no crash

        $r = $this->api('POST', '/tasks/'.$parent->id.'/subtasks', ['steps' => [
            ['title' => 'Write copy', 'estimated_minutes' => 60], ['title' => '  <b>Design hero</b> ', 'estimated_minutes' => 90], ['title' => ''],
        ], 'update_parent_estimate' => true])->assertCreated();
        $this->assertSame(2, count($r->json('created')));
        $this->assertSame(150, $parent->fresh()->estimated_minutes, 'only because the user ticked the box');

        $kids = Task::where('parent_task_id', $parent->id)->orderBy('id')->get();
        $this->assertSame(['Write copy', 'Design hero'], $kids->pluck('title')->all());
        $this->assertTrue($kids->every(fn ($k) => $k->project_id === $p->id && (int) $k->importance === 80 && $k->user_id === $this->u->id));

        $list = collect($this->api('GET', '/tasks')->json('data'));
        $this->assertSame(2, $list->firstWhere('id', $parent->id)['subtasks_total']);
        $this->assertSame('Launch landing page', $list->firstWhere('title', 'Write copy')['parent']['title']);
        $this->assertCount(2, $this->api('GET', '/tasks/'.$parent->id.'/breakdown')->json('children'));
    }

    public function test_breakdown_is_owner_only_and_validated(): void
    {
        $parent = Task::create(['user_id' => $this->u->id, 'title' => 'Mine']);
        $other = $this->auth($this->user('o@example.com'));
        $this->api('GET', '/tasks/'.$parent->id.'/breakdown', [], $other)->assertNotFound();
        $this->api('POST', '/tasks/'.$parent->id.'/subtasks', ['steps' => [['title' => 'x']]], $other)->assertNotFound();
        $this->api('POST', '/tasks/'.$parent->id.'/subtasks', ['steps' => []])->assertStatus(422);
        $this->api('POST', '/tasks/'.$parent->id.'/subtasks', ['steps' => array_fill(0, 13, ['title' => 'x'])])->assertStatus(422);
        $this->api('POST', '/tasks/'.$parent->id.'/subtasks', ['steps' => [['title' => '   ']]])->assertStatus(422);
        $parent->update(['status' => 'completed', 'completed_at' => now()]);
        $this->api('POST', '/tasks/'.$parent->id.'/subtasks', ['steps' => [['title' => 'x']]])->assertStatus(422);
        $this->assertSame(1, Task::withoutGlobalScopes()->count());
    }

    public function test_ai_breakdown_is_only_a_validated_proposal(): void
    {
        $parent = Task::create(['user_id' => $this->u->id, 'title' => 'Prepare quarterly report', 'estimated_minutes' => 240]);
        $this->ai(
            ['steps' => [
                ['title' => 'Collect numbers from finance', 'estimated_minutes' => 45],
                ['title' => 'Prepare quarterly report', 'estimated_minutes' => 60], // repeats the task: dropped
                ['title' => '<script>x</script>Draft summary', 'estimated_minutes' => 9999], // tags stripped, bad minutes → null
                ['title' => 'Collect numbers from finance'], // duplicate: dropped
                ['nope' => true],
            ]],
            ['steps' => 'not a list'],
        );
        $before = Task::count();
        $r = $this->api('POST', '/tasks/'.$parent->id.'/breakdown')->assertOk();
        $this->assertSame(['Collect numbers from finance', 'xDraft summary'], array_column($r->json('steps'), 'title'));
        $this->assertSame([45, null], array_column($r->json('steps'), 'estimated_minutes'));
        $this->assertFalse($r->json('calibrated'), 'no history: AI minutes are shown as they are');
        $this->assertSame($before, Task::count(), 'proposal creates nothing');
        $this->assertSame(240, $parent->fresh()->estimated_minutes);
        $this->assertSame(1, AiInteraction::where('intent', 'TASK_BREAKDOWN')->count());

        $this->api('POST', '/tasks/'.$parent->id.'/breakdown')->assertStatus(422); // unusable answer → readable error
    }

    public function test_estimate_uses_similar_history_then_factor_and_never_writes(): void
    {
        $mk = fn (string $title, int $est, int $act) => Task::create(['user_id' => $this->u->id, 'title' => $title, 'status' => 'completed',
            'completed_at' => now()->subDays(3), 'estimated_minutes' => $est, 'actual_minutes' => $act]);
        $mk('Weekly client report', 30, 50);
        $mk('Monthly client report', 30, 70);
        $mk('Client report for Acme', 30, 60);
        $mk('Fix login bug', 60, 40);

        $r = $this->api('POST', '/estimate', ['title' => 'Client report for Globex', 'estimated_minutes' => 30])->assertOk();
        $this->assertSame('similar', $r->json('basis'));
        $this->assertSame(60, $r->json('suggested_minutes'));
        $this->assertSame([50, 70], $r->json('range'));

        // Nothing similar: falls back to the user's measured estimate factor (only with enough samples).
        $none = $this->api('POST', '/estimate', ['title' => 'Plan offsite agenda', 'estimated_minutes' => 60])->assertOk();
        $this->assertNull($none->json('suggested_minutes'));
        $this->assertSame('not_enough_history', $none->json('reason'));

        for ($i = 0; $i < 8; $i++) $mk('Misc chore '.$i, 20, 30);
        $f = $this->api('POST', '/estimate', ['title' => 'Plan offsite agenda', 'estimated_minutes' => 60])->assertOk();
        $this->assertSame('factor', $f->json('basis'));
        $this->assertGreaterThan(60, $f->json('suggested_minutes'));

        $mine = Task::create(['user_id' => $this->u->id, 'title' => 'Client report draft', 'estimated_minutes' => 30]);
        $this->api('POST', '/estimate', ['title' => $mine->title, 'task_id' => $mine->id, 'estimated_minutes' => 30])->assertOk();
        $this->assertSame(30, $mine->fresh()->estimated_minutes, 'suggestions never change the task');

        $other = $this->auth($this->user('o@example.com'));
        $this->api('POST', '/estimate', ['title' => 'Client report', 'task_id' => $mine->id], $other)->assertNotFound();
        $this->assertSame('not_enough_history', $this->api('POST', '/estimate', ['title' => 'Client report for Globex'], $other)->json('reason'), 'history is per user');
    }

    public function test_calendar_range_can_include_deadlines(): void
    {
        Task::create(['user_id' => $this->u->id, 'title' => 'Due Friday', 'deadline' => '2025-11-14 10:00:00']);
        Task::create(['user_id' => $this->u->id, 'title' => 'Done', 'deadline' => '2025-11-14 10:00:00', 'status' => 'completed', 'completed_at' => now()]);
        Task::create(['user_id' => $this->user('o@example.com')->id, 'title' => 'Not mine', 'deadline' => '2025-11-14 10:00:00']);

        $this->assertSame([], $this->api('GET', '/schedule?from=2025-11-08&to=2025-11-21')->assertOk()->json('deadlines'));
        $r = $this->api('GET', '/schedule?from=2025-11-01&to=2025-12-12&deadlines=1')->assertOk(); // 6-week month grid
        $this->assertSame(['Due Friday'], array_column($r->json('deadlines'), 'title'));
        $this->assertCount(42, $r->json('days'));
        $this->api('GET', '/schedule?from=2025-11-01&to=2025-12-31')->assertStatus(422);
    }
}
