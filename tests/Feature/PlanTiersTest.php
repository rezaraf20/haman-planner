<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LifecycleMail;
use App\Models\ApiToken;
use App\Models\Goal;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Planner\PlanningAssistantService;
use App\Support\AppSettings;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Free vs paid plans: limits, gated features, upgrade hints, sign-up trial and the catalogue migration. */
final class PlanTiersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function user(string $email = 'free@example.com'): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'en', 'timezone' => 'UTC']);
    }

    private function h(User $u): array
    {
        $plain = 'pt-'.$u->id.str_repeat('p', 40);
        ApiToken::firstOrCreate(['token_hash' => hash('sha256', $plain)], ['user_id' => $u->id, 'name' => 't', 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function makePro(User $u): void
    {
        Subscription::create(['user_id' => $u->id, 'plan_id' => Plan::where('code', 'pro')->value('id'), 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'provider' => 'zibal']);
        app(Entitlements::class)->forget($u);
    }

    public function test_free_plan_limits_recurring_series_and_goals_but_paid_does_not(): void
    {
        $free = $this->user();
        for ($i = 1; $i <= 3; $i++) {
            $this->withHeaders($this->h($free))->postJson('/api/recurring-tasks', ['title' => 'R'.$i, 'frequency' => 'daily'])->assertCreated();
        }
        $this->withHeaders($this->h($free))->postJson('/api/recurring-tasks', ['title' => 'R4', 'frequency' => 'daily'])
            ->assertStatus(402)->assertJsonPath('metric', 'active_recurring')->assertJsonPath('upgrade_url', route('billing.index'));

        for ($i = 1; $i <= 3; $i++) {
            Goal::create(['user_id' => $free->id, 'title' => 'G'.$i]);
        }
        $this->withHeaders($this->h($free))->postJson('/api/goals', ['title' => 'G4'])->assertStatus(402);

        $pro = $this->user('pro@example.com');
        $this->makePro($pro);
        for ($i = 1; $i <= 5; $i++) {
            $this->withHeaders($this->h($pro))->postJson('/api/recurring-tasks', ['title' => 'P'.$i, 'frequency' => 'daily'])->assertCreated();
        }
        $this->assertSame(5, app(Entitlements::class)->used($pro, 'active_recurring'));
    }

    public function test_free_plan_gates_calendar_advanced_ai_and_long_analytics(): void
    {
        $free = $this->user();
        $e = app(Entitlements::class);
        $this->assertFalse($e->canUse($free, 'calendar'));
        $this->assertFalse($e->canUse($free, 'advanced_ai_planning'));
        $this->assertSame(15, $e->limit($free, 'ai_requests'));
        $this->assertSame(20, $e->limit($free, 'attachment_storage_mb'));

        $this->withHeaders($this->h($free))->getJson('/api/system/dashboard?days=30')->assertOk()
            ->assertJsonPath('history_days', 7)->assertJsonPath('history_limited', true)->assertJsonPath('upgrade_url', route('billing.index'));
        $this->withHeaders($this->h($free))->getJson('/api/system/report?period=month')->assertOk()->assertJsonPath('history_days', 7);
        $this->withHeaders($this->h($free))->getJson('/api/planning/insights')->assertOk()->assertJsonPath('history_days', 7)->assertJsonPath('history_limited', true);

        // AI configured + free plan → the rule-based proposal says what Pro adds.
        config(['services.ai.provider' => 'openai', 'services.ai.api_key' => 'env-key', 'services.ai.base_url' => 'https://env.test/v1', 'services.ai.model' => 'gpt-x']);
        $this->assertTrue(app(PlanningAssistantService::class)->needsUpgradeForAi($free));

        $pro = $this->user('pro@example.com');
        $this->makePro($pro);
        $this->assertTrue($e->canUse($pro, 'calendar'));
        $this->assertFalse(app(PlanningAssistantService::class)->needsUpgradeForAi($pro));
        $this->withHeaders($this->h($pro))->getJson('/api/system/dashboard?days=30')->assertOk()
            ->assertJsonPath('history_days', 30)->assertJsonPath('history_limited', false)->assertJsonPath('upgrade_url', null);
        $this->withHeaders($this->h($pro))->getJson('/api/planning/insights')->assertOk()->assertJsonPath('history_days', 90);
    }

    public function test_new_accounts_get_the_trial_quietly_and_admin_can_turn_it_off(): void
    {
        Mail::fake();
        AppSettings::put(['registration_enabled' => '1']);
        $this->withSession(['locale' => 'en'])->post('/register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'Secret123x', 'password_confirmation' => 'Secret123x'])->assertRedirect();
        $u = User::where('email', 'new@example.com')->first();
        $sub = Subscription::where('user_id', $u->id)->first();
        $this->assertSame('trialing', $sub->status);
        $this->assertSame('pro', $sub->plan->code);
        $this->assertEqualsWithDelta(7, now()->diffInDays($sub->trial_ends_at), 0.01);
        $this->assertTrue(app(Entitlements::class)->canUse($u, 'calendar'));
        Mail::assertQueued(LifecycleMail::class, fn ($m) => $m->type === 'welcome');
        Mail::assertNotQueued(LifecycleMail::class, fn ($m) => $m->type === 'trial_started');

        AppSettings::put(['signup_trial' => '0']);
        $this->post('/logout');
        $this->withSession(['locale' => 'en'])->post('/register', ['name' => 'Two', 'email' => 'two@example.com', 'password' => 'Secret123x', 'password_confirmation' => 'Secret123x'])->assertRedirect();
        $this->assertFalse(Subscription::where('user_id', User::where('email', 'two@example.com')->value('id'))->exists());
    }

    public function test_catalogue_migration_updates_existing_plans_by_price_not_code(): void
    {
        // Simulate the old catalogue: everything on for everyone.
        $all = ['telegram' => true, 'ai_planner' => true, 'advanced_analytics' => true, 'priority_support' => false, 'recurring_tasks' => true, 'calendar' => true, 'advanced_ai_planning' => true, 'attachments' => true];
        DB::table('plans')->update(['features' => json_encode($all), 'trial_days' => 14]);
        DB::table('plans')->where('code', 'free')->update(['code' => 'starter-renamed', 'limits' => json_encode(['ai_requests' => 30, 'active_goals' => null, 'attachment_storage_mb' => 100])]);

        (require base_path('database/migrations/2026_09_30_000043_differentiate_free_and_paid_plans.php'))->up();

        $free = Plan::where('code', 'starter-renamed')->first();
        $this->assertSame(15, $free->limit('ai_requests'));
        $this->assertSame(3, $free->limit('active_goals'));
        $this->assertSame(3, $free->limit('active_recurring'));
        $this->assertFalse($free->hasFeature('calendar'));
        $this->assertFalse($free->hasFeature('advanced_analytics'));
        $this->assertTrue($free->hasFeature('telegram'));
        $pro = Plan::where('code', 'pro')->first();
        $this->assertSame(7, (int) $pro->trial_days);
        $this->assertNull($pro->limit('active_recurring'));
        $this->assertTrue($pro->hasFeature('calendar'));
        $this->assertSame(14, (int) Plan::where('code', 'business')->value('trial_days'), 'only the first paid plan gets the trial');
    }

    public function test_pricing_shows_trial_badge_and_differences(): void
    {
        $this->get('/en')->assertOk()->assertSee('Try it free for 7 days')->assertSee('Google Calendar sync')->assertSee('Active recurring tasks');
    }
}
