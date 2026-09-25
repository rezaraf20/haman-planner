<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Area;
use App\Models\DailyPlan;
use App\Models\Goal;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class MultiUserWebTest extends TestCase
{
    use RefreshDatabase;

    private User $a;
    private User $b;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test-token', 'services.haman_planner.registration' => true]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->a = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('password-a1'), 'is_active' => true, 'telegram_chat_id' => '1001']);
        $this->b = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('password-b1'), 'is_active' => true]);
    }

    private function token(User $u): array
    {
        $plain = 'tok-'.$u->id.'-'.str_repeat('x', 40);
        ApiToken::firstOrCreate(['token_hash' => hash('sha256', $plain)], ['user_id' => $u->id, 'name' => 't', 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    // ---------------------------------------------------------------- auth pages

    public function test_login_page_has_register_and_forgot_links_and_no_admin_hint(): void
    {
        config(['app.env' => 'testing']);
        putenv('PLANNER_ADMIN_EMAIL=admin@hamantech.ir');
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('/register', $html);
        $this->assertStringContainsString('/forgot-password', $html);
        $this->assertStringNotContainsString('admin@hamantech.ir', $html);
        $this->assertStringNotContainsString('رمز مدیریتی', $html);
    }

    public function test_user_can_register_and_is_a_regular_active_user(): void
    {
        $this->get('/register')->assertOk();
        $this->post('/register', [
            'name' => 'Sara', 'email' => 'Sara@Example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertRedirect(route('account.settings'));

        $user = User::where('email', 'sara@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->is_admin);
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_duplicates_weak_passwords_and_bots(): void
    {
        $this->post('/register', ['name' => 'X', 'email' => 'A@EXAMPLE.COM', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertSessionHasErrors('email');
        $this->post('/register', ['name' => 'X', 'email' => 'new@example.com', 'password' => 'onlyletters', 'password_confirmation' => 'onlyletters'])
            ->assertSessionHasErrors('password');
        $this->post('/register', ['name' => 'Bot', 'email' => 'bot@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'website' => 'spam'])
            ->assertRedirect(route('login'));
        $this->assertSame(2, User::count());
    }

    public function test_registration_can_be_disabled(): void
    {
        config(['services.haman_planner.registration' => false]);
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertNotFound();
        $this->assertStringNotContainsString('/register', $this->get('/login')->getContent());
    }

    public function test_login_is_case_insensitive_on_email(): void
    {
        $this->post('/login', ['email' => 'A@Example.COM', 'password' => 'password-a1'])->assertRedirect(route('planner.app'));
        $this->assertAuthenticatedAs($this->a);
    }

    public function test_forgot_password_sends_email_and_telegram_without_enumeration(): void
    {
        Notification::fake();
        $known = $this->post('/forgot-password', ['email' => 'a@example.com'])->assertRedirect()->getSession()->get('status');
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertRedirect()->getSession()->get('status');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($this->a, ResetPasswordNotification::class);
        Notification::assertCount(1);
        $sent = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), 'sendMessage'));
        $this->assertCount(1, $sent);
        $this->assertSame('1001', (string) $sent->first()[0]->data()['chat_id']);
        $this->assertStringContainsString('/reset-password/', $sent->first()[0]->data()['text']);
    }

    public function test_password_can_be_reset_with_valid_token_only(): void
    {
        $token = Password::broker()->createToken($this->b);
        $this->get('/reset-password/'.$token.'?email=b@example.com')->assertOk();

        $this->post('/reset-password', ['token' => 'wrong', 'email' => 'b@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => $token, 'email' => 'b@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('newpass123', $this->b->fresh()->password));
        $this->post('/login', ['email' => 'b@example.com', 'password' => 'newpass123'])->assertRedirect(route('planner.app'));
    }

    // ---------------------------------------------------------------- isolation

    public function test_api_lists_and_search_only_show_own_data(): void
    {
        Task::create(['user_id' => $this->a->id, 'title' => 'Alpha own']);
        Task::create(['user_id' => $this->b->id, 'title' => 'Alpha foreign']);
        Goal::create(['user_id' => $this->b->id, 'title' => 'Foreign goal']);

        $tasks = $this->withHeaders($this->token($this->a))->getJson('/api/tasks')->assertOk()->json('data');
        $this->assertSame(['Alpha own'], array_column($tasks, 'title'));
        $this->assertSame([], $this->withHeaders($this->token($this->a))->getJson('/api/goals')->assertOk()->json('data'));
        $found = $this->withHeaders($this->token($this->a))->getJson('/api/search?q=Alpha')->assertOk()->json('tasks');
        $this->assertSame(['Alpha own'], array_column($found, 'title'));
        $dash = json_encode($this->withHeaders($this->token($this->a))->getJson('/api/system/dashboard')->assertOk()->json());
        $this->assertStringNotContainsString('Alpha foreign', $dash);
    }

    public function test_api_cannot_read_update_or_delete_another_users_records(): void
    {
        $foreign = Task::create(['user_id' => $this->b->id, 'title' => 'B task']);
        $h = $this->token($this->a);

        $this->withHeaders($h)->getJson('/api/tasks/'.$foreign->id)->assertNotFound();
        $this->withHeaders($h)->putJson('/api/tasks/'.$foreign->id, ['title' => 'hacked'])->assertNotFound();
        $this->withHeaders($h)->deleteJson('/api/tasks/'.$foreign->id)->assertNotFound();
        $this->withHeaders($h)->postJson('/api/tasks/'.$foreign->id.'/execution-logs', ['started_at' => now()->toDateTimeString(), 'duration_minutes' => 5])->assertNotFound();

        $this->assertSame('B task', $foreign->fresh()->title);
        $this->assertSame(0, $foreign->executionLogs()->withoutGlobalScopes()->count());
    }

    public function test_api_cannot_attach_another_users_records_by_id(): void
    {
        $foreignGoal = Goal::create(['user_id' => $this->b->id, 'title' => 'B goal']);
        $foreignArea = Area::create(['user_id' => $this->b->id, 'name' => 'B area']);
        $foreignTask = Task::create(['user_id' => $this->b->id, 'title' => 'B task']);
        $own = Task::create(['user_id' => $this->a->id, 'title' => 'A task']);
        $h = $this->token($this->a);

        $this->withHeaders($h)->postJson('/api/tasks', ['title' => 'x', 'goal_id' => $foreignGoal->id])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/notes', ['title' => 'n', 'content' => 'c', 'area_id' => $foreignArea->id])->assertStatus(422);
        $dep = $this->withHeaders($h)->postJson('/api/tasks/'.$own->id.'/dependencies', ['depends_on_task_id' => $foreignTask->id]);
        $this->assertContains($dep->status(), [404, 422]);
        $this->assertSame(0, \App\Models\TaskDependency::withoutGlobalScopes()->count());
        $this->withHeaders($h)->postJson('/api/schedule-blocks', ['task_id' => $foreignTask->id, 'starts_at' => '2026-10-01 10:00', 'ends_at' => '2026-10-01 11:00'])->assertStatus(422);

        $created = $this->withHeaders($h)->postJson('/api/tasks', ['title' => 'mine'])->assertCreated()->json();
        $this->assertSame($this->a->id, (int) $created['user_id']);
    }

    public function test_daily_plans_are_per_user(): void
    {
        $this->withHeaders($this->token($this->a))->getJson('/api/daily-plans/2026-10-01')->assertOk();
        $this->withHeaders($this->token($this->b))->getJson('/api/daily-plans/2026-10-01')->assertOk();
        $this->assertSame(2, DailyPlan::withoutGlobalScopes()->whereDate('plan_date', '2026-10-01')->count());
    }

    public function test_reminders_go_only_to_the_owners_telegram(): void
    {
        $this->withHeaders($this->token($this->a))->postJson('/api/reminders', ['scheduled_at' => '2026-10-01 10:00', 'message' => 'hi'])
            ->assertCreated();
        $this->assertSame('1001', Reminder::withoutGlobalScopes()->first()->payload['chat_id']);

        $this->withHeaders($this->token($this->a))->postJson('/api/reminders', ['scheduled_at' => '2026-10-01 10:00', 'chat_id' => '999'])
            ->assertStatus(422);
        $this->withHeaders($this->token($this->b))->postJson('/api/reminders', ['scheduled_at' => '2026-10-01 10:00'])
            ->assertStatus(422); // B has no linked Telegram
    }

    public function test_admin_area_is_hidden_and_forbidden_for_regular_users(): void
    {
        $html = $this->actingAs($this->b)->get('/planner')->assertOk()->getContent();
        $this->assertStringNotContainsString("/admin/users'", $html);
        $this->assertStringContainsString('/settings/security', $html);
        $this->actingAs($this->b)->get('/admin/users')->assertForbidden();
        $this->withHeaders($this->token($this->b))->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_reminder_worker_still_sees_all_users(): void
    {
        Reminder::create(['user_id' => $this->a->id, 'type' => 'telegram', 'scheduled_at' => now()->subMinute(), 'status' => 'pending', 'payload' => ['chat_id' => '1001', 'message' => 'a']]);
        Reminder::create(['user_id' => $this->b->id, 'type' => 'telegram', 'scheduled_at' => now()->subMinute(), 'status' => 'pending', 'payload' => ['chat_id' => '2002', 'message' => 'b']]);
        $this->assertSame(2, app(\App\Services\Planner\ReminderService::class)->dispatchDue());
    }
}
