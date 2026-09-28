<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminBusinessController;
use App\Mail\LifecycleMail;
use App\Models\AiInteraction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::create($attrs + ['name' => 'U', 'email' => 'u@example.com', 'password' => Hash::make('secret123'), 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'en']);
    }

    public function test_web_pages_send_a_self_only_csp_and_request_id(): void
    {
        $r = $this->get('/login')->assertOk();
        $csp = (string) $r->headers->get('Content-Security-Policy');
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "frame-ancestors 'self'", "connect-src 'self'", 'https://checkout.stripe.com', 'https://payment.zarinpal.com'] as $part) {
            $this->assertStringContainsString($part, $csp);
        }
        $this->assertStringNotContainsString('*', $csp);
        $this->assertNotEmpty($r->headers->get('X-Request-Id'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('same-origin', $r->headers->get('Cross-Origin-Opener-Policy'));

        // A client-supplied ID is echoed only when it is well-formed.
        $this->get('/login', ['X-Request-Id' => 'abc-123'])->assertHeader('X-Request-Id', 'abc-123');
        $this->assertNotSame('<script>', $this->get('/login', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id'));

        config(['app.csp_report_only' => true]);
        $this->assertNotNull($this->get('/login')->headers->get('Content-Security-Policy-Report-Only'));
        config(['app.csp' => false]);
        $this->assertNull($this->get('/login')->headers->get('Content-Security-Policy'));
    }

    public function test_every_script_and_stylesheet_is_same_origin(): void
    {
        $u = $this->user();
        foreach (['/', '/en', '/login', '/planner', '/settings', '/billing'] as $page) {
            $html = $this->actingAs($u)->get($page)->getContent();
            preg_match_all('#<(?:script|link)[^>]+(?:src|href)="(https?://[^"]+)"#', $html, $m);
            foreach ($m[1] as $url) {
                $this->assertStringStartsWith(rtrim((string) config('app.url'), '/'), $url, "$page loads a third-party asset: $url");
            }
        }
    }

    public function test_force_https_redirects_and_https_app_url_makes_cookies_secure(): void
    {
        config(['app.force_https' => true]);
        $this->app['env'] = 'production';
        $this->get('http://localhost/login')->assertRedirect('https://localhost/login')->assertStatus(301);
        $this->app['env'] = 'testing';

        $this->assertNull(config('session.secure'));
        config(['app.url' => 'https://planner.example.com']);
        (new \App\Providers\AppServiceProvider($this->app))->boot();
        $this->assertTrue(config('session.secure'));
    }

    public function test_email_verification_flow(): void
    {
        Mail::fake();
        $u = $this->user();
        $this->assertNull($u->email_verified_at);
        $this->actingAs($u)->get('/settings')->assertSee(__('settings.email_unverified_badge', [], 'en'));
        $this->post(route('email.verification.send'))->assertRedirect();
        $mail = Mail::queued(LifecycleMail::class)->first(fn ($m) => $m->type === 'verify_email');
        $this->assertNotNull($mail);
        $url = (string) $mail->params['url'];
        $this->assertStringContainsString('signature=', $url);

        // Tampered or expired links do not verify.
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();
        $this->assertNull($u->fresh()->email_verified_at);

        $fresh = URL::temporarySignedRoute('email.verify', now()->addHour(), ['user' => $u->id, 'hash' => sha1('u@example.com')]);
        $this->get($fresh)->assertRedirect(route('account.settings'));
        $this->assertNotNull($u->fresh()->email_verified_at);

        // Changing the address resets verification, sends a new link, and old links stop working.
        $this->post(route('account.profile'), ['name' => 'U', 'email' => 'new@example.com', 'current_password' => 'secret123'])->assertRedirect();
        $this->assertNull($u->fresh()->email_verified_at);
        $this->get($fresh)->assertForbidden();
        Mail::assertQueued(LifecycleMail::class, fn ($m) => $m->type === 'verify_email' && $m->hasTo('new@example.com'));
    }

    public function test_password_change_rotates_the_remember_token(): void
    {
        $u = $this->user();
        $u->forceFill(['remember_token' => 'old-token'])->save();
        $this->actingAs($u)->post(route('account.password'), ['current_password' => 'secret123', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'])->assertRedirect();
        $this->assertNotSame('old-token', $u->fresh()->remember_token);
        $this->assertTrue(Hash::check('NewSecret123', $u->fresh()->password));
    }

    public function test_ai_interactions_always_carry_a_request_id(): void
    {
        $u = $this->user();
        $a = AiInteraction::create(['user_id' => $u->id, 'provider' => 'x', 'model' => 'm', 'intent' => 'T', 'input_hash' => 'h', 'status' => 'completed', 'created_at' => now()]);
        $this->assertNotEmpty($a->request_id);
    }

    public function test_secrets_are_masked_in_admin_log_view(): void
    {
        $masked = AdminBusinessController::maskSecrets('stripe whsec_abcDEF123 key sk_live_abc123 google ya29.a0AfH6SMB token Bearer abcdefghijkl');
        foreach (['whsec_abcDEF123', 'sk_live_abc123', 'ya29.a0AfH6SMB', 'abcdefghijkl'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked);
        }
    }

    public function test_admin_users_page_shows_usage_signals_but_no_planner_content(): void
    {
        $admin = $this->user(['email' => 'admin@example.com', 'is_admin' => true]);
        $u = User::create(['name' => 'Customer', 'email' => 'c@example.com', 'password' => Hash::make('x12345678'), 'is_active' => true, 'onboarded_at' => now()]);
        \App\Models\Task::create(['user_id' => $u->id, 'title' => 'Very private task title']);
        \Illuminate\Support\Facades\DB::table('usage_counters')->insert(['user_id' => $u->id, 'metric' => 'ai_requests', 'period' => now()->format('Y-m'), 'used' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $html = $this->actingAs($admin)->get('/admin/users')->assertOk()->getContent();
        $this->assertStringContainsString(__('admin.col.ai_month', [], 'en').': 7', $html);
        $this->assertStringNotContainsString('Very private task title', $html);
        $this->get('/admin/system')->assertOk()->assertSee(__('admin.health.stripe_webhooks', [], 'en'));
    }
}
