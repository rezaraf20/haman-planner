<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ExecutionLog;
use App\Models\Goal;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Support\LocalDate;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LocalizationAccountAndSeoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Reza', 'email' => 'r@example.com', 'password' => Hash::make('secret123'), 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
    }

    // ---------------------------------------------------------------- language resolution & switcher

    public function test_default_language_is_persian_rtl(): void
    {
        $r = $this->withHeaders(['Accept-Language' => 'de-DE,de;q=0.9'])->get('/login')->assertOk()->assertHeader('Content-Language', 'fa');
        $this->assertStringContainsString('lang="fa"', $r->getContent());
        $this->assertStringContainsString('dir="rtl"', $r->getContent());
    }

    public function test_browser_language_is_used_for_guests(): void
    {
        $r = $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])->get('/login')->assertOk()->assertHeader('Content-Language', 'en');
        $this->assertStringContainsString('dir="ltr"', $r->getContent());
        $this->assertStringContainsString(__('auth.login_title', [], 'en'), $r->getContent());
    }

    public function test_switcher_keeps_the_page_and_remembers_the_choice(): void
    {
        $this->get('/language/en?to=/forgot-password')->assertRedirect('/forgot-password')->assertCookie('hp_locale', 'en');
        $this->get('/forgot-password')->assertHeader('Content-Language', 'en');

        // Switcher links on the page point back at the same page.
        $html = $this->get('/forgot-password')->getContent();
        $this->assertStringContainsString('/language/fa?to=', $html);
        $this->assertStringContainsString(urlencode('/forgot-password'), $html);
    }

    public function test_switcher_rejects_open_redirects_and_unknown_languages(): void
    {
        foreach (['//evil.example', 'https://evil.example', '/\\evil.example', 'evil'] as $to) {
            $this->get('/language/en?to='.urlencode($to))->assertRedirect('/');
        }
        $this->get('/language/de')->assertNotFound();
    }

    public function test_signed_in_users_language_is_saved_to_the_account_and_used_everywhere(): void
    {
        $this->actingAs($this->user)->get('/language/en?to=/settings')->assertRedirect('/settings');
        $this->assertSame('en', $this->user->fresh()->locale);

        // A fresh session (another device) still gets English from the account.
        $this->flushSession();
        $html = $this->actingAs($this->user->fresh())->get('/planner')->assertOk()->assertHeader('Content-Language', 'en')->getContent();
        $this->assertStringContainsString('dir="ltr"', $html);

        // And the API follows the account language too.
        $plain = str_repeat('z', 48);
        ApiToken::create(['user_id' => $this->user->id, 'token_hash' => hash('sha256', $plain), 'name' => 't', 'token_prefix' => substr($plain, 0, 12)]);
        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/api/tasks')->assertOk()->assertHeader('Content-Language', 'en');
        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->postJson('/api/tasks', [])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => !preg_match('/[\x{0600}-\x{06FF}]/u', (string) $m));
    }

    public function test_every_app_page_renders_in_both_languages_without_raw_keys(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('secret123'), 'is_active' => true, 'is_admin' => true, 'onboarded_at' => now()]);
        $ticket = SupportTicket::create(['user_id' => $admin->id, 'subject' => 'Help', 'status' => 'open']);
        $pages = ['/planner', '/settings', '/billing', '/support', '/support/'.$ticket->id, '/onboarding', '/admin', '/admin/users', '/admin/settings',
            '/admin/subscriptions', '/admin/plans', '/admin/payments', '/admin/system', '/admin/support', '/admin/support/'.$ticket->id, '/admin/access', '/admin/integrations', '/admin/content', '/admin/payment-settings'];
        foreach (['fa', 'en'] as $locale) {
            $admin->update(['locale' => $locale]);
            foreach ($pages as $page) {
                $html = $this->actingAs($admin->fresh())->get($page)->assertOk()->getContent();
                $this->assertStringContainsString('dir="'.($locale === 'fa' ? 'rtl' : 'ltr').'"', $html, $page);
                $this->assertDoesNotMatchRegularExpression('/[>"\s](common|planner|settings|billing|admin|support|onboarding|app|auth)\.[a-z_]+\.?[a-z_]*[<"\s]/', $html, "$locale $page shows a raw key");
                if ($locale === 'en') {
                    $visible = strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $html));
                    $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]{3,}/u', str_replace(['فارسی'], '', $visible), "Persian text on English $page");
                }
            }
        }
    }

    public function test_language_files_have_identical_keys(): void
    {
        $flatten = function (array $a, string $p = '') use (&$flatten): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) && $v !== [] && !array_is_list($v) ? $out = array_merge($out, $flatten($v, $p.$k.'.')) : $out[] = $p.$k;
            }
            return $out;
        };
        $fa = glob(base_path('resources/lang/fa/*.php'));
        $en = glob(base_path('resources/lang/en/*.php'));
        $this->assertSame(array_map('basename', $fa), array_map('basename', $en));
        foreach ($fa as $file) {
            $name = basename($file);
            if ($name === 'validation.php') {
                continue; // English rule messages fall back to Laravel's built-in ones; only attributes are overridden.
            }
            $faKeys = $flatten(require $file);
            $enKeys = $flatten(require base_path('resources/lang/en/'.$name));
            sort($faKeys);
            sort($enKeys);
            $this->assertSame([], array_values(array_diff($faKeys, $enKeys)), "en/$name is missing keys");
            $this->assertSame([], array_values(array_diff($enKeys, $faKeys)), "fa/$name is missing keys");
        }
    }

    // ---------------------------------------------------------------- dates & timezones

    public function test_persian_dates_use_jalali_calendar_and_persian_digits(): void
    {
        $c = Carbon::parse('2026-03-21 10:05', 'Asia/Tehran');
        $this->assertSame('۱۴۰۵/۰۱/۰۱', LocalDate::date($c, 'fa', 'Asia/Tehran'));
        $this->assertStringContainsString('2026', LocalDate::date($c, 'en', 'Asia/Tehran'));
        $this->assertSame([2026, 3, 21], LocalDate::jalaliToGregorian(1405, 1, 1));
        $this->assertSame('123', LocalDate::latinDigits('۱۲۳'));
        // The same instant is shown in the viewer's timezone.
        $this->assertStringContainsString('6:35 AM', LocalDate::dateTime($c, 'en', 'Europe/London'));
        $this->assertStringContainsString('۱۰:۰۵', LocalDate::dateTime($c, 'fa', 'Asia/Tehran'));
    }

    public function test_offset_datetimes_from_the_browser_are_stored_as_the_same_instant(): void
    {
        $plain = str_repeat('q', 48);
        ApiToken::create(['user_id' => $this->user->id, 'token_hash' => hash('sha256', $plain), 'name' => 't', 'token_prefix' => substr($plain, 0, 12)]);
        $id = $this->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->postJson('/api/tasks', ['title' => 'tz', 'deadline' => '2026-10-01T09:00:00+01:00'])->assertCreated()->json('id');
        $stored = Task::find($id)->deadline;
        $this->assertTrue($stored->equalTo(Carbon::parse('2026-10-01T08:00:00Z')), 'stored '.$stored->toIso8601String());
    }

    // ---------------------------------------------------------------- SEO & headers

    public function test_marketing_pages_have_seo_metadata_and_are_indexable(): void
    {
        foreach (['/' => 'fa', '/en' => 'en', '/pricing' => 'fa', '/en/pricing' => 'en', '/privacy' => 'fa', '/en/terms' => 'en'] as $url => $locale) {
            $r = $this->get($url)->assertOk();
            $this->assertNull($r->headers->get('X-Robots-Tag'), $url);
            $html = $r->getContent();
            $this->assertStringContainsString('lang="'.$locale.'"', $html);
            $this->assertMatchesRegularExpression('#<title>[^<]+</title>#', $html);
            $this->assertStringContainsString('name="description"', $html);
            $this->assertStringContainsString('rel="canonical"', $html);
            $this->assertStringContainsString('hreflang="fa"', $html);
            $this->assertStringContainsString('hreflang="en"', $html);
            $this->assertStringContainsString('hreflang="x-default"', $html);
            $this->assertStringContainsString('property="og:title"', $html);
            $this->assertStringContainsString('name="twitter:card"', $html);
        }
        $this->assertStringContainsString('application/ld+json', $this->get('/en')->getContent());
        // Signed-in visitors are taken to the app from the home page, but can still preview it.
        $this->actingAs($this->user)->get('/en')->assertRedirect(route('planner.app'));
        $this->get('/en?preview=1')->assertOk();
        $this->get('/en/pricing')->assertOk();
    }

    public function test_private_pages_are_noindex_and_robots_and_sitemap_are_correct(): void
    {
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->user)->get('/planner')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        foreach (['Disallow: /planner', 'Disallow: /admin', 'Disallow: /api/', 'Disallow: /settings', 'Sitemap: '] as $line) {
            $this->assertStringContainsString($line, $robots);
        }
        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString(url('/en/pricing'), $sitemap);
        $this->assertStringContainsString('hreflang="fa"', $sitemap);
        $this->assertStringNotContainsString('/planner', $sitemap);
        $this->assertNotFalse(simplexml_load_string($sitemap));
    }

    // ---------------------------------------------------------------- onboarding

    public function test_new_users_are_guided_through_skippable_onboarding(): void
    {
        $new = User::create(['name' => 'New', 'email' => 'n@example.com', 'password' => Hash::make('secret123'), 'is_active' => true]);
        $this->actingAs($new)->get('/planner')->assertRedirect(route('onboarding'));

        $this->post('/onboarding/1', ['name' => 'Nina', 'locale' => 'en', 'timezone' => 'Europe/Berlin'])->assertRedirect(route('onboarding', ['step' => 2]));
        $this->post('/onboarding/2', ['goal' => 'Run a marathon', 'task' => 'Buy shoes'])->assertRedirect(route('onboarding', ['step' => 3]));
        $this->post('/onboarding/skip')->assertRedirect(route('planner.app'));

        $new->refresh();
        $this->assertSame(['Nina', 'en', 'Europe/Berlin'], [$new->name, $new->locale, $new->timezone]);
        $this->assertNotNull($new->onboarded_at);
        $this->assertSame($new->id, Goal::withoutGlobalScopes()->where('title', 'Run a marathon')->value('user_id'));
        $task = Task::withoutGlobalScopes()->where('title', 'Buy shoes')->first();
        $this->assertSame($new->id, $task->user_id);
        $this->get('/planner')->assertOk();
    }

    public function test_onboarding_can_be_skipped_immediately_and_existing_users_are_not_interrupted(): void
    {
        $new = User::create(['name' => 'S', 'email' => 's@example.com', 'password' => Hash::make('secret123'), 'is_active' => true]);
        $this->actingAs($new)->post('/onboarding/skip')->assertRedirect(route('planner.app'));
        $this->get('/planner')->assertOk();
        $this->actingAs($this->user)->get('/planner')->assertOk();
    }

    // ---------------------------------------------------------------- account & privacy

    public function test_preferences_update_language_timezone_and_notifications(): void
    {
        $this->actingAs($this->user)->post('/settings/preferences', [
            'locale' => 'en', 'timezone' => 'America/New_York', 'ai_response_language' => 'fa', 'ai_enabled' => '1',
        ])->assertRedirect(route('account.settings'));
        $u = $this->user->fresh();
        $this->assertSame(['en', 'America/New_York'], [$u->locale, $u->timezone]);
        $this->assertFalse($u->preference('notify_reminders_telegram'));
        $this->assertTrue($u->preference('ai_enabled'));
        $this->assertSame('fa', $u->preference('ai_response_language'));

        $this->post('/settings/preferences', ['locale' => 'xx', 'timezone' => 'Mars/Base', 'ai_response_language' => 'auto'])->assertSessionHasErrors(['locale', 'timezone']);
    }

    public function test_email_change_requires_current_password_and_password_change_works(): void
    {
        $this->actingAs($this->user)->post('/settings/profile', ['name' => 'R', 'email' => 'new@example.com'])->assertSessionHasErrors('current_password');
        $this->post('/settings/profile', ['name' => 'R', 'email' => 'new@example.com', 'current_password' => 'secret123'])->assertSessionHasNoErrors();
        $this->assertSame('new@example.com', $this->user->fresh()->email);

        $this->post('/settings/security/password', ['current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertSessionHasErrors('current_password');
        $this->post('/settings/security/password', ['current_password' => 'secret123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('newpass123', $this->user->fresh()->password));
    }

    public function test_export_contains_only_own_data_and_no_secrets(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => Hash::make('secret123'), 'is_active' => true]);
        Task::create(['user_id' => $this->user->id, 'title' => 'Mine']);
        Task::create(['user_id' => $other->id, 'title' => 'Theirs']);

        $json = $this->actingAs($this->user)->get('/settings/export')->assertOk()->streamedContent();
        $data = json_decode($json, true);
        $this->assertSame('r@example.com', $data['account']['email']);
        $this->assertSame(['Mine'], array_column($data['planner']['tasks'], 'title'));
        $this->assertStringNotContainsString('Theirs', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString((string) $this->user->password, $json);
    }

    public function test_account_deletion_is_confirmed_and_removes_only_own_data(): void
    {
        $this->seed(PlanSeeder::class);
        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => Hash::make('secret123'), 'is_active' => true]);
        $goal = Goal::create(['user_id' => $this->user->id, 'title' => 'G']);
        $project = Project::create(['user_id' => $this->user->id, 'title' => 'P', 'goal_id' => $goal->id]);
        $task = Task::create(['user_id' => $this->user->id, 'title' => 'T', 'goal_id' => $goal->id, 'project_id' => $project->id]);
        ExecutionLog::create(['user_id' => $this->user->id, 'task_id' => $task->id, 'started_at' => now(), 'duration_minutes' => 5]);
        Task::create(['user_id' => $other->id, 'title' => 'Keep me']);
        $plan = Plan::where('code', 'pro')->first();
        $payment = Payment::create(['user_id' => $this->user->id, 'plan_id' => $plan->id, 'provider' => 'zarinpal', 'amount' => 190000, 'currency' => 'IRT', 'status' => 'paid', 'billing_interval' => 'monthly', 'customer_email' => 'r@example.com']);
        $invoice = app(BillingService::class)->issueInvoice($payment, $this->user, $plan);

        $this->actingAs($this->user)->post('/settings/delete', ['current_password' => 'wrong', 'confirm' => 'حذف'])->assertSessionHasErrors('current_password');
        $this->post('/settings/delete', ['current_password' => 'secret123', 'confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->assertNotNull($this->user->fresh());

        $this->post('/settings/delete', ['current_password' => 'secret123', 'confirm' => 'حذف'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(User::find($this->user->id));
        $this->assertSame(0, Task::withoutGlobalScopes()->where('title', 'T')->count());
        $this->assertSame(0, ExecutionLog::withoutGlobalScopes()->count());
        $this->assertSame(1, Task::withoutGlobalScopes()->where('user_id', $other->id)->count());
        // The accounting record survives, pseudonymised.
        $invoice->refresh();
        $this->assertStringStartsWith('deleted-user-', $invoice->billing_name);
        $this->assertNull($invoice->billing_email);
        $this->assertNull($payment->fresh()->customer_email);
    }

    public function test_the_last_admin_cannot_delete_their_account(): void
    {
        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('secret123'), 'is_active' => true, 'is_admin' => true, 'onboarded_at' => now()]);
        $this->actingAs($admin)->post('/settings/delete', ['current_password' => 'secret123', 'confirm' => 'حذف'])->assertSessionHasErrors('confirm');
        $this->assertNotNull($admin->fresh());
    }

    public function test_telegram_can_be_disconnected_from_settings(): void
    {
        $this->user->update(['telegram_chat_id' => '4242', 'telegram_username' => 'reza']);
        $this->actingAs($this->user)->post('/settings/telegram/unlink')->assertRedirect();
        $this->assertNull($this->user->fresh()->telegram_chat_id);
        $this->assertNull($this->user->fresh()->telegram_username);
    }
}
