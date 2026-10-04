<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Task;
use App\Models\User;
use App\Support\ApiErrors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Forms that leave optional fields empty must save, and failures must read as plain language. */
final class EmptyFieldsAndErrorsTest extends TestCase
{
    use RefreshDatabase;

    /** Every optional field a planner form may send, all left empty. */
    private const EMPTY = ['status', 'priority', 'area_id', 'goal_id', 'task_id', 'project_id', 'milestone_id', 'importance', 'weight', 'health',
        'estimated_minutes', 'deadline', 'start_date', 'target_date', 'type', 'kind', 'source', 'description', 'progress', 'calendar', 'interval',
        'planned_start', 'planned_end', 'failure_reason', 'energy_level', 'focus_level', 'success_criteria', 'rationale', 'decided_at', 'time_of_day'];

    private User $user;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'fa']);
        $plain = 'ef-'.str_repeat('e', 40);
        ApiToken::create(['user_id' => $this->user->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        $this->h = ['Authorization' => 'Bearer '.$plain];
    }

    public static function resources(): array
    {
        return [
            'area' => ['areas', ['name' => 'A']], 'task' => ['tasks', ['title' => 'T']], 'goal' => ['goals', ['title' => 'G']],
            'project' => ['projects', ['title' => 'P']], 'milestone' => ['milestones', ['title' => 'M']], 'note' => ['notes', ['title' => 'N', 'content' => 'x']],
            'decision' => ['decisions', ['title' => 'D', 'decision' => 'x']], 'block' => ['schedule-blocks', ['title' => 'B', 'starts_at' => '2026-10-05 10:00', 'ends_at' => '2026-10-05 11:00']],
            'recurring' => ['recurring-tasks', ['title' => 'R', 'frequency' => 'daily']],
        ];
    }

    #[DataProvider('resources')]
    public function test_create_and_edit_with_empty_optional_fields_never_fail(string $ep, array $required): void
    {
        if ($ep === 'milestones') {
            $required['project_id'] = $this->withHeaders($this->h)->postJson('/api/projects', ['title' => 'P'])->json('id');
        }
        $payload = array_merge(array_fill_keys(self::EMPTY, null), $required);
        $id = $this->withHeaders($this->h)->postJson('/api/'.$ep, $payload)->assertCreated()->json('id');
        $edit = array_fill_keys(self::EMPTY, null);
        if ($ep === 'milestones') unset($edit['project_id']);
        $this->withHeaders($this->h)->putJson('/api/'.$ep.'/'.$id, $edit)->assertOk();
    }

    public function test_unchosen_selects_take_defaults_and_cleared_required_values_are_kept(): void
    {
        $task = $this->withHeaders($this->h)->postJson('/api/tasks', ['title' => 'T', 'priority' => null, 'status' => null, 'importance' => null])->assertCreated();
        $this->assertSame(['p3', 'inbox', 50], [$task->json('priority'), $task->json('status'), (int) $task->json('importance')]);
        $area = $this->withHeaders($this->h)->postJson('/api/areas', ['name' => 'A', 'type' => null])->assertCreated();
        $this->assertSame('custom', $area->json('type'));

        // (task priority is recalculated by the planner, so a plain stored field is used here)
        $this->withHeaders($this->h)->putJson('/api/tasks/'.$task->json('id'), ['weight' => 3, 'importance' => 80])->assertOk();
        $this->withHeaders($this->h)->putJson('/api/tasks/'.$task->json('id'), ['weight' => null, 'importance' => null, 'description' => null])->assertOk();
        $fresh = Task::find($task->json('id'));
        $this->assertSame([3.0, 80], [(float) $fresh->weight, (int) $fresh->importance]);
    }

    public function test_validation_and_http_errors_are_localized_and_never_leak_internals(): void
    {
        $r = $this->withHeaders($this->h)->postJson('/api/recurring-tasks', ['frequency' => 'nope']);
        $r->assertStatus(422)->assertJsonStructure(['errors' => ['title', 'frequency']]);
        $this->assertStringContainsString('خطای دیگر', $r->json('message'), 'summary suffix is translated');

        $nf = $this->withHeaders($this->h)->getJson('/api/tasks/999999')->assertNotFound();
        $this->assertSame(__('errors.not_found', [], 'fa'), $nf->json('message'));
        $this->assertStringNotContainsString('App\\', $nf->getContent());

        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/__boom', fn () => throw new \RuntimeException('SQLSTATE secret internals'));
        $boom = $this->getJson('/api/__boom')->assertStatus(500)->assertJsonStructure(['message', 'reference']);
        $this->assertStringNotContainsString('secret', $boom->getContent());
        $this->assertStringContainsString($boom->json('reference'), $boom->json('message'));
    }

    public function test_database_constraint_errors_become_a_clear_422(): void
    {
        app()->setLocale('fa');
        $pdo = new \PDOException('SQLSTATE[23502]: Not null violation: null value in column "title" of relation "tasks" violates not-null constraint');
        $pdo->errorInfo = ['23502', 7, 'null value in column "title"'];
        $res = ApiErrors::query(new QueryException('pgsql', 'insert into tasks', [], $pdo));
        $this->assertSame(422, $res->getStatusCode());
        $body = $res->getData(true);
        $this->assertArrayHasKey('title', $body['errors']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($body));

        $pdo->errorInfo = ['42P01', 7, 'relation missing'];
        $this->assertSame(500, ApiErrors::query(new QueryException('pgsql', 'select', [], $pdo))->getStatusCode());
    }

    public function test_planner_ui_marks_invalid_fields_and_sends_no_empty_values_on_create(): void
    {
        $html = $this->actingAs($this->user)->get('/planner')->assertOk()->getContent();
        $this->assertStringContainsString('function showErrors(form,errors)', $html);
        $this->assertStringContainsString('collect(e.target,true)', $html);
        $this->assertStringContainsString('"fix_fields"', $html);
    }
}
