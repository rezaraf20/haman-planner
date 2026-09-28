<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LifecycleMail;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use App\Services\Analytics\ProductAnalytics;
use App\Services\Notifications\LifecycleMailer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class LifecycleEmailsAndAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        static $i = 0;
        $i++;
        return User::create($attrs + ['name' => 'User '.$i, 'email' => "user{$i}@example.com", 'password' => 'secret-pass-123', 'is_active' => true, 'onboarded_at' => now()]);
    }

    // ---------------------------------------------------------------- lifecycle email

    public function test_emails_are_localized_deduplicated_and_respect_preferences(): void
    {
        Mail::fake();
        $fa = $this->user(['locale' => 'fa', 'name' => 'رضا']);
        $mailer = app(LifecycleMailer::class);

        $this->assertTrue($mailer->send($fa, 'welcome'));
        $this->assertFalse($mailer->send($fa, 'welcome'), 'deduplicated');
        $this->assertFalse($mailer->send($fa, 'unknown_type'));

        $mail = Mail::queued(LifecycleMail::class)->first();
        $this->assertSame('fa', $mail->locale);
        app()->setLocale('fa');
        $html = $mail->render();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('به همان پلنر خوش آمدید', $html);
        $this->assertStringContainsString('رضا', $html);
        $this->assertStringNotContainsString('unsubscribe', $html, 'essential email has no unsubscribe link');

        $en = $this->user(['locale' => 'en']);
        $this->assertTrue($mailer->send($en, 'inactive_reminder', [], 'inactive-x'));
        $m = Mail::queued(LifecycleMail::class)->last();
        app()->setLocale('en');
        $html = $m->render();
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertNotNull($m->unsubscribeUrl);
        $this->assertStringContainsString('List-Unsubscribe', json_encode($m->headers()->text));

        // Opted out of optional categories → not sent; essential billing mail still goes out.
        $en->update(['preferences' => ['email_product_updates' => false, 'notify_billing_email' => false]]);
        $this->assertFalse($mailer->send($en->fresh(), 'inactive_reminder', [], 'inactive-y'));
        $this->assertFalse($mailer->send($en->fresh(), 'renewal_reminder', [], 'r-1'));
        $this->assertTrue($mailer->send($en->fresh(), 'payment_failed', ['plan' => 'Pro', 'days' => 7], 'pf-1'));

        // Inactive accounts never get mail. No content is stored, only type + time.
        $en->update(['is_active' => false]);
        $this->assertFalse($mailer->send($en->fresh(), 'payment_failed', [], 'pf-2'));
        $this->assertSame(['id', 'user_id', 'type', 'dedupe_key', 'channel', 'sent_at'], array_keys((array) DB::table('notification_deliveries')->first()));
    }

    public function test_signed_unsubscribe_link_turns_the_category_off(): void
    {
        $u = $this->user(['locale' => 'en']);
        $url = LifecycleMailer::unsubscribeUrl($u, 'email_product_updates');

        $this->get($url)->assertOk()->assertSee(__('emails.unsubscribe', [], 'en'));
        $this->assertTrue($u->fresh()->preference('email_product_updates'), 'GET alone never unsubscribes');
        $this->post($url)->assertOk()->assertSee(__('emails.pref.email_product_updates', [], 'en'));
        $this->assertFalse($u->fresh()->preference('email_product_updates'));

        $this->post(str_replace('email_product_updates', 'notify_billing_email', $url))->assertForbidden();         // tampered
        $this->get(route('email.unsubscribe', ['user' => $u->id, 'preference' => 'email_product_updates']))->assertForbidden(); // unsigned
        $this->post(\URL::signedRoute('email.unsubscribe', ['user' => $u->id, 'preference' => 'ai_enabled']))->assertNotFound();
    }

    public function test_registration_sends_welcome_and_scheduled_emails_run_once(): void
    {
        Mail::fake();
        \App\Support\AppSettings::put(['registration_enabled' => '1']);
        $this->withSession(['locale' => 'en'])->post('/register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'Secret123x', 'password_confirmation' => 'Secret123x'])->assertRedirect();
        Mail::assertQueued(LifecycleMail::class, fn ($m) => $m->type === 'welcome' && $m->hasTo('new@example.com'));

        // Inactive for 8 days → one reminder, even if the job runs repeatedly.
        $idle = $this->user(['locale' => 'en']);
        DB::table('users')->where('id', $idle->id)->update(['last_seen_at' => now()->subDays(8)]);
        $gone = $this->user(['locale' => 'en']);
        DB::table('users')->where('id', $gone->id)->update(['last_seen_at' => now()->subDays(90)]);
        $this->artisan('planner:lifecycle-emails')->assertSuccessful();
        $this->artisan('planner:lifecycle-emails')->assertSuccessful();
        Mail::assertQueued(LifecycleMail::class, fn ($m) => $m->type === 'inactive_reminder' && $m->hasTo($idle->email));
        Mail::assertNotQueued(LifecycleMail::class, fn ($m) => $m->hasTo($gone->email));
        $this->assertSame(1, DB::table('notification_deliveries')->where('type', 'inactive_reminder')->count());

        // Weekly review email: opt-in, on the first day of the user's week (Monday for English).
        $reader = $this->user(['locale' => 'en', 'timezone' => 'UTC', 'preferences' => ['email_weekly_review' => true]]);
        Task::create(['user_id' => $reader->id, 'title' => 'Done thing', 'status' => 'completed', 'completed_at' => now()->subDays(3)]);
        $this->travelTo(now('UTC')->next('Monday')->setTime(9, 0));
        $this->artisan('planner:lifecycle-emails')->assertSuccessful();
        $this->artisan('planner:lifecycle-emails')->assertSuccessful();
        $this->assertSame(1, DB::table('notification_deliveries')->where('type', 'weekly_review')->where('user_id', $reader->id)->count());
        Mail::assertNotQueued(LifecycleMail::class, fn ($m) => $m->type === 'weekly_review' && !$m->hasTo($reader->email));
    }

    // ---------------------------------------------------------------- analytics

    public function test_activity_days_are_recorded_once_per_day(): void
    {
        $u = $this->user();
        $h = ['Authorization' => 'Bearer '.$this->token($u)];
        $this->withHeaders($h)->getJson('/api/tasks')->assertOk();
        $this->withHeaders($h)->getJson('/api/tasks')->assertOk();
        $this->assertSame(1, DB::table('user_activity_days')->where('user_id', $u->id)->count());
        $this->travel(1)->days();
        $this->withHeaders($h)->getJson('/api/tasks')->assertOk();
        $this->assertSame(2, DB::table('user_activity_days')->where('user_id', $u->id)->count());
    }

    private function token(User $u): string
    {
        $plain = 'an-'.$u->id.str_repeat('z', 40);
        \App\Models\ApiToken::create(['user_id' => $u->id, 'name' => 't', 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12)]);
        return $plain;
    }

    public function test_metrics_are_computed_from_real_data_and_never_faked(): void
    {
        $a = app(ProductAnalytics::class);
        // Empty database: every rate is "not enough data" (null), not 0 %.
        $empty = $a->all();
        $this->assertNull($empty['active']['dau']);
        foreach (['activation', 'trial_conversion', 'paid_conversion', 'churn', 'retention_w1', 'retention_m1'] as $k) {
            $this->assertNull($empty[$k]['rate'], $k);
        }
        $this->assertSame([], $empty['revenue']);

        $this->seed(PlanSeeder::class);
        $pro = Plan::where('code', 'pro')->first();
        // Two users signed up 16 days ago; one created a task on day 2 (activated), one never did.
        $u1 = $this->user(); $u2 = $this->user();
        DB::table('users')->whereIn('id', [$u1->id, $u2->id])->update(['created_at' => now()->subDays(16)]);
        $t = Task::create(['user_id' => $u1->id, 'title' => 'x']);
        DB::table('tasks')->where('id', $t->id)->update(['created_at' => now()->subDays(14)]);
        // Activity: u1 on days 0 and 8 after sign-up; tracking started 16 days ago.
        DB::table('user_activity_days')->insert([
            ['user_id' => $u1->id, 'day' => now()->subDays(16)->toDateString()],
            ['user_id' => $u1->id, 'day' => now()->subDays(8)->toDateString()],
            ['user_id' => $u2->id, 'day' => now()->toDateString()],
        ]);
        // Paid subscription (monthly 600 USD) and a yearly one in IRT.
        $s1 = Subscription::create(['user_id' => $u1->id, 'plan_id' => $pro->id, 'status' => 'active', 'billing_interval' => 'monthly', 'current_period_start' => now()->subDays(5), 'current_period_end' => now()->addDays(25), 'provider' => 'stripe']);
        Payment::create(['user_id' => $u1->id, 'subscription_id' => $s1->id, 'plan_id' => $pro->id, 'billing_interval' => 'monthly', 'provider' => 'stripe', 'amount' => 600, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now()->subDays(5)]);
        $s2 = Subscription::create(['user_id' => $u2->id, 'plan_id' => $pro->id, 'status' => 'active', 'billing_interval' => 'yearly', 'current_period_start' => now()->subDays(5), 'current_period_end' => now()->addYear(), 'provider' => 'zarinpal']);
        Payment::create(['user_id' => $u2->id, 'subscription_id' => $s2->id, 'plan_id' => $pro->id, 'billing_interval' => 'yearly', 'provider' => 'zarinpal', 'amount' => 1200000, 'currency' => 'IRT', 'status' => 'paid', 'paid_at' => now()->subDays(5)]);

        $m = $a->all();
        $this->assertSame(1, $m['active']['dau']);
        $this->assertNull($m['active']['mau'], 'only 16 days of tracking: MAU is not reported');
        $this->assertSame(2, $m['active']['mau_partial']);
        $this->assertSame(['n' => 1, 'of' => 2, 'rate' => 50.0], $m['activation']);
        $this->assertSame(['n' => 2, 'of' => 2, 'rate' => 100.0], $m['paid_conversion']);
        $this->assertSame(['n' => 1, 'of' => 2, 'rate' => 50.0], array_intersect_key($m['retention_w1'], array_flip(['n', 'of', 'rate'])));
        $this->assertNull($m['retention_m1']['rate'], 'cohort too young');
        $this->assertSame(['mrr' => 600, 'subscriptions' => 1, 'arpu' => 600], $m['revenue']['USD']);
        $this->assertSame(100000, $m['revenue']['IRT']['mrr']);
        $this->assertNull($m['churn']['rate'], 'no paid subscribers 30 days ago');

        $admin = $this->user(['is_admin' => true, 'locale' => 'fa']);
        $this->actingAs($admin)->get(route('admin.analytics'))->assertOk()->assertSee('MRR')->assertSee(__('admin.an.activation', [], 'fa'));
    }
}
