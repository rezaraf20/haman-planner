<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TagsAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $u;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2025-11-12 06:30:00', 'UTC')); // Wed 10:00 Tehran
        $this->u = $this->user('u@example.com');
        $this->h = $this->auth($this->u);
    }

    private function user(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
    }

    private function auth(User $u): array
    {
        $plain = 'tg-'.$u->id.str_repeat('t', 40);
        ApiToken::create(['user_id' => $u->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function api(string $m, string $url, array $data = [], ?array $h = null)
    {
        return $this->withHeaders($h ?? $this->h)->json($m, '/api'.$url, $data);
    }

    private function titles($r): array
    {
        $t = array_column($r->assertOk()->json('data'), 'title');
        sort($t);
        return $t;
    }

    public function test_tags_on_tasks_create_reuse_and_isolation(): void
    {
        $t = $this->api('POST', '/tasks', ['title' => 'Report', 'new_tags' => ['work', 'Urgent']])->assertCreated();
        $this->assertSame(['Urgent', 'work'], collect($t->json('tags'))->pluck('name')->sort()->values()->all());
        $work = Tag::where('name', 'work')->value('id');

        // Same name, different case: reused, not duplicated.
        $t2 = $this->api('POST', '/tasks', ['title' => 'Email', 'new_tags' => ['WORK']])->assertCreated();
        $this->assertSame([$work], collect($t2->json('tags'))->pluck('id')->all());
        $this->assertSame(2, Tag::count());

        $this->api('PUT', '/tasks/'.$t->json('id'), ['tag_ids' => [$work]])->assertOk()->assertJsonCount(1, 'tags');
        $this->assertSame(2, collect($this->api('GET', '/tags')->json())->firstWhere('name', 'work')['open_tasks']);

        // Another user can neither see nor attach my tags.
        $other = $this->auth($this->user('o@example.com'));
        $this->assertSame([], $this->api('GET', '/tags', [], $other)->json());
        $this->api('POST', '/tasks', ['title' => 'Mine', 'tag_ids' => [$work]], $other)->assertStatus(422);
        $this->api('DELETE', '/tags/'.$work, [], $other)->assertNotFound();

        $this->api('DELETE', '/tags/'.$work)->assertNoContent();
        $this->assertSame(2, Task::count(), 'deleting a tag never deletes tasks');
    }

    public function test_smart_lists_match_the_spec(): void
    {
        $p = Project::create(['user_id' => $this->u->id, 'title' => 'P']);
        $mk = fn (array $a) => Task::create($a + ['user_id' => $this->u->id]);
        $mk(['title' => 'today', 'deadline' => '2025-11-12 15:00:00', 'priority' => 'p1', 'estimated_minutes' => 20, 'project_id' => $p->id]);
        $mk(['title' => 'tomorrow', 'deadline' => '2025-11-13 09:00:00', 'estimated_minutes' => 120, 'importance' => 90]);
        $mk(['title' => 'late', 'deadline' => '2025-11-10 09:00:00', 'priority' => 'p0']);
        $mk(['title' => 'someday']);
        $mk(['title' => 'done', 'status' => 'completed', 'deadline' => '2025-11-12 12:00:00']);
        $waiting = $mk(['title' => 'waiting', 'deadline' => '2025-11-15 09:00:00']);
        $dep = $mk(['title' => 'needed first']);
        TaskDependency::create(['user_id' => $this->u->id, 'task_id' => $waiting->id, 'depends_on_task_id' => $dep->id]);

        $this->assertSame(['today'], $this->titles($this->api('GET', '/tasks?smart=today')));
        $this->assertSame(['tomorrow'], $this->titles($this->api('GET', '/tasks?smart=tomorrow')));
        $this->assertSame(['late'], $this->titles($this->api('GET', '/tasks?smart=overdue')));
        $this->assertSame(['today', 'tomorrow', 'waiting'], $this->titles($this->api('GET', '/tasks?smart=upcoming')));
        $this->assertSame(['needed first', 'someday'], $this->titles($this->api('GET', '/tasks?smart=no_deadline')));
        $this->assertSame(['late', 'today'], $this->titles($this->api('GET', '/tasks?smart=high_priority')));
        $this->assertSame(['tomorrow'], $this->titles($this->api('GET', '/tasks?smart=high_contribution')));
        $this->assertSame(['today'], $this->titles($this->api('GET', '/tasks?smart=quick')));
        $this->assertSame(['tomorrow'], $this->titles($this->api('GET', '/tasks?smart=deep_work')));
        $this->assertSame(['waiting'], $this->titles($this->api('GET', '/tasks?smart=blocked')));
        $this->assertNotContains('today', $this->titles($this->api('GET', '/tasks?smart=no_project')));

        // "Overdue tasks that are not blocked" as a custom definition.
        $f = json_encode(['due' => 'overdue', 'blocked' => false]);
        $this->assertSame(['late'], $this->titles($this->api('GET', '/tasks?filter='.urlencode($f))));
    }

    public function test_filters_are_validated_and_saved_per_user(): void
    {
        $tag = Tag::create(['user_id' => $this->u->id, 'name' => 'work']);
        $t = Task::create(['user_id' => $this->u->id, 'title' => 'Tagged', 'priority' => 'p1', 'deadline' => '2025-11-14 10:00:00']);
        $t->tags()->attach($tag->id);
        Task::create(['user_id' => $this->u->id, 'title' => 'Plain', 'priority' => 'p1', 'deadline' => '2025-11-14 10:00:00']);

        $def = ['priority' => ['p0', 'p1'], 'due' => 'week', 'tags' => [$tag->id]];
        $saved = $this->api('POST', '/filters', ['name' => 'High-priority work this week', 'definition' => $def])->assertCreated();
        $this->assertSame(['Tagged'], $this->titles($this->api('GET', '/tasks?filter='.urlencode(json_encode($saved->json('definition'))))));
        $this->assertSame(['Tagged'], $this->titles($this->api('GET', '/tasks?tag='.$tag->id)));

        $this->api('POST', '/filters', ['name' => ' high-priority WORK this week ', 'definition' => $def])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->api('POST', '/filters', ['name' => 'Bad', 'definition' => ['sql' => 'drop table']])->assertStatus(422);
        $this->api('POST', '/filters', ['name' => 'Bad', 'definition' => ['due' => 'yesterday']])->assertStatus(422);
        $this->api('GET', '/tasks?filter=not-json')->assertStatus(422);

        $other = $this->auth($this->user('o@example.com'));
        $this->api('POST', '/filters', ['name' => 'Spy', 'definition' => ['tags' => [$tag->id]]], $other)->assertStatus(422);
        $this->assertSame([], $this->api('GET', '/filters', [], $other)->json('saved'));
        $this->api('DELETE', '/filters/'.$saved->json('id'), [], $other)->assertNotFound();
        $this->assertContains('quick', $this->api('GET', '/filters')->json('presets'));
    }

    public function test_ai_text_to_filter_is_validated_and_never_trusted(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $tag = Tag::create(['user_id' => $this->u->id, 'name' => 'work']);
        config(['services.ai.provider' => 'openai', 'services.ai.api_key' => 'k', 'services.ai.base_url' => 'https://ai.test/v1', 'services.ai.model' => 'm']);
        \Illuminate\Support\Facades\Http::fake(['https://ai.test/*' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode(['tags' => [$tag->id], 'max_minutes' => 60, 'min_importance' => 70, 'evil' => 'x'])]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])
            ->push(['choices' => [['message' => ['content' => json_encode(['project_id' => 999999])]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);

        $r = $this->api('POST', '/filters/parse', ['text' => 'important work I can finish in under an hour'])->assertOk();
        $this->assertEquals(['tags' => [$tag->id], 'max_minutes' => 60, 'min_importance' => 70], $r->json('definition'));
        $this->api('POST', '/filters/parse', ['text' => 'tasks of project nine'])->assertStatus(422); // invented id rejected
    }
}
