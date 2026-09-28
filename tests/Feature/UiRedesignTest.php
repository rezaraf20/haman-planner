<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ExecutionLog;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UiRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'u@example.com', string $locale = 'en'): User
    {
        return User::create(['name' => 'Sara', 'email' => $email, 'password' => 'secret-pass-123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => $locale, 'timezone' => 'UTC']);
    }

    private function h(User $u): array
    {
        $plain = 'ui-'.$u->id.str_repeat('q', 40);
        ApiToken::create(['user_id' => $u->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    public function test_app_shell_uses_one_design_system_in_both_directions(): void
    {
        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $loc => $dir) {
            $u = $this->user($loc.'@example.com', $loc);
            $html = $this->actingAs($u)->get('/planner')->assertOk()->getContent();
            $this->assertStringContainsString('dir="'.$dir.'"', $html);
            $this->assertStringContainsString('css/haman.css', $html);
            $this->assertStringContainsString('id="i-today"', $html, 'icon sprite');
            $this->assertStringContainsString('class="skip-link"', $html);
            $this->assertStringContainsString('class="tabbar"', $html, 'mobile navigation');
            foreach (['group_today', 'group_planning', 'group_execution', 'group_analysis'] as $g) {
                $this->assertStringContainsString(e(__('app.nav.'.$g, [], $loc)), $html);
            }
            // every screen keeps its entry point
            foreach (['today', 'daily', 'calendar', 'inbox', 'ai-planner', 'areas', 'goals', 'projects', 'milestones', 'recurring', 'tasks', 'execution', 'dependencies', 'reminders', 'failures', 'reviews', 'analytics', 'reports', 'notes', 'decisions', 'activity', 'ai', 'pending', 'search'] as $v) {
                $this->assertStringContainsString('data-view="'.$v.'"', $html, $v);
            }
            foreach (['/settings', '/billing', '/support'] as $page) {
                $this->assertStringContainsString('css/haman.css', $this->get($page)->assertOk()->getContent(), $page);
            }
            auth()->logout();
            $this->assertStringContainsString('css/haman.css', $this->get('/login')->getContent());
        }
        $css = (string) file_get_contents(public_path('css/haman.css'));
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('[data-theme="dark"]', $css, 'dark tokens prepared');
    }

    public function test_every_new_ui_string_exists_in_both_languages(): void
    {
        foreach (['home', 'row', 'empty', 'view_sub'] as $group) {
            $fa = __('app.'.$group, [], 'fa');
            $en = __('app.'.$group, [], 'en');
            $this->assertIsArray($fa);
            $this->assertSame(array_keys($en), array_keys($fa), $group);
        }
    }

    public function test_week_summary_is_computed_from_own_data(): void
    {
        $this->travelTo(now('UTC')->startOfWeek()->addDays(2)->setTime(12, 0)); // Wednesday
        $u = $this->user();
        $other = $this->user('o@example.com');
        Task::create(['user_id' => $u->id, 'title' => 'Done this week', 'status' => 'completed', 'deadline' => now()->addDay(), 'completed_at' => now()]);
        Task::create(['user_id' => $u->id, 'title' => 'Open this week', 'status' => 'planned', 'deadline' => now()->addDays(3)]);
        Task::create(['user_id' => $u->id, 'title' => 'Late', 'status' => 'planned', 'deadline' => now()->subDays(3)]);
        Task::create(['user_id' => $u->id, 'title' => 'Soon', 'status' => 'planned', 'deadline' => now()->addHours(20)]);
        $t = Task::create(['user_id' => $other->id, 'title' => 'Other secret', 'status' => 'completed', 'deadline' => now()->addDay(), 'completed_at' => now()]);
        ExecutionLog::create(['user_id' => $u->id, 'task_id' => Task::where('title', 'Done this week')->value('id'), 'started_at' => now()->subHour(), 'ended_at' => now(), 'duration_minutes' => 60]);

        $r = $this->withHeaders($this->h($u))->getJson('/api/planning/week')->assertOk()->json();
        $this->assertSame(3, $r['tasks_total'], 'the overdue task from last week is not part of this week');
        $this->assertSame(1, $r['tasks_completed']);
        $this->assertSame(33, $r['percent']);
        $this->assertSame(60, $r['actual_minutes']);
        $this->assertSame(1, $r['overdue_count']);
        $this->assertSame('Late', $r['overdue'][0]['title']);
        $this->assertSame(['Soon'], array_column($r['due_soon_unscheduled'], 'title'));
        $this->assertStringNotContainsString('Other secret', json_encode($r));

        $empty = $this->withHeaders($this->h($other))->getJson('/api/planning/week')->json();
        $this->assertSame(1, $empty['tasks_total']);
        $fresh = $this->user('n@example.com');
        $this->assertNull($this->withHeaders($this->h($fresh))->getJson('/api/planning/week')->json('percent'), 'no fake 0%');
    }
}
