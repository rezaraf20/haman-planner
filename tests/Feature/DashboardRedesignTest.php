<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ExecutionLog;
use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardRedesignTest extends TestCase
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
        $plain = 'dr-'.$u->id.str_repeat('d', 40);
        ApiToken::create(['user_id' => $u->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    public function test_today_summary_counts_only_my_real_data(): void
    {
        $mk = fn (array $a, ?User $u = null) => Task::create($a + ['user_id' => ($u ?? $this->u)->id]);
        $doneTask = $mk(['title' => 'done', 'status' => 'completed', 'completed_at' => '2025-11-12 09:00:00', 'estimated_minutes' => 30]);
        $mk(['title' => 'left', 'deadline' => '2025-11-12 18:00:00', 'estimated_minutes' => 60]);
        $mk(['title' => 'late', 'deadline' => '2025-11-10 09:00:00']);
        $mk(['title' => 'tomorrow', 'deadline' => '2025-11-13 09:00:00']);
        Goal::create(['user_id' => $this->u->id, 'title' => 'Ship v2']);
        ExecutionLog::create(['user_id' => $this->u->id, 'task_id' => $doneTask->id, 'started_at' => '2025-11-12 08:00:00', 'ended_at' => '2025-11-12 08:45:00', 'duration_minutes' => 45]);

        $other = $this->user('o@example.com');
        $mk(['title' => 'not mine', 'deadline' => '2025-11-12 18:00:00'], $other);
        Goal::create(['user_id' => $other->id, 'title' => 'Secret goal']);

        $r = $this->withHeaders($this->h)->getJson('/api/planning/today')->assertOk();
        $this->assertSame(1, $r->json('completed'));
        $this->assertSame(1, $r->json('remaining'));
        $this->assertSame(1, $r->json('overdue'));
        $this->assertSame(50, $r->json('percent'));
        $this->assertSame(90, $r->json('estimated_minutes'));
        $this->assertSame(45, $r->json('logged_minutes'));
        $this->assertSame(['Ship v2'], array_column($r->json('goals'), 'title'));
        $this->assertNull($r->json('insight'), 'no insight without enough history');
        $this->assertSame(\App\Http\Controllers\Api\PlanningController::WIDGETS, $r->json('layout.order'));
    }

    public function test_empty_day_has_no_fake_percentage(): void
    {
        $this->withHeaders($this->h)->getJson('/api/planning/today')->assertOk()->assertJsonPath('percent', null);
    }

    public function test_layout_is_validated_and_new_widgets_are_appended(): void
    {
        $this->withHeaders($this->h)->postJson('/api/planning/layout', ['order' => ['goals', 'now'], 'hidden' => ['insight']])->assertOk();
        $layout = $this->withHeaders($this->h)->getJson('/api/planning/today')->json('layout');
        $this->assertSame(['goals', 'now'], array_slice($layout['order'], 0, 2));
        $this->assertCount(count(\App\Http\Controllers\Api\PlanningController::WIDGETS), $layout['order']);
        $this->assertSame(['insight'], $layout['hidden']);

        $this->withHeaders($this->h)->postJson('/api/planning/layout', ['order' => ['<script>'], 'hidden' => []])->assertStatus(422);
        $this->assertSame(['insight'], $this->u->fresh()->preference('dashboard_layout')['hidden']);
    }

    public function test_theme_preference_is_saved_and_rendered(): void
    {
        $this->actingAs($this->u)->postJson('/settings/theme', ['theme' => 'dark'])->assertOk()->assertJsonPath('theme', 'dark');
        $this->actingAs($this->u)->postJson('/settings/theme', ['theme' => 'neon'])->assertStatus(422);
        $this->assertSame('dark', $this->u->fresh()->preference('theme'));

        $page = $this->actingAs($this->u->fresh())->get('/planner')->assertOk();
        $page->assertSee('data-theme="dark"', false);
        $page->assertSee('planner-filters.js', false);
        $page->assertSee('toggleSide', false);
        $this->actingAs($this->u->fresh())->get('/settings')->assertOk()->assertSee('theme-opt', false);
    }
}
