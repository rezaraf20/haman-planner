<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret123', 'is_active' => true, 'is_admin' => true, 'onboarded_at' => now()]);
        $this->member = User::create(['name' => 'Member', 'email' => 'm@example.com', 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now()]);
    }

    public function test_business_pages_are_admin_only(): void
    {
        foreach (['/admin', '/admin/subscriptions', '/admin/plans', '/admin/payments', '/admin/system', '/admin/users?filter=paying'] as $url) {
            $this->actingAs($this->member)->get($url)->assertForbidden();
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
        $pro = Plan::where('code', 'pro')->first();
        $this->actingAs($this->member)->post('/admin/users/'.$this->member->id.'/grant', ['plan' => $pro->id, 'months' => 12])->assertForbidden();
        $this->actingAs($this->member)->post('/admin/plans', ['code' => 'hack', 'name_fa' => 'x', 'name_en' => 'x'])->assertForbidden();
        $this->assertSame(0, Subscription::count());
        $this->assertNull(Plan::where('code', 'hack')->first());
    }

    public function test_admin_can_grant_a_plan_and_edit_plans(): void
    {
        $pro = Plan::where('code', 'pro')->first();
        $this->actingAs($this->admin)->from('/admin/users')->post('/admin/users/'.$this->member->id.'/grant', ['plan' => $pro->id, 'months' => 3])->assertRedirect('/admin/users');
        $this->assertSame('pro', app(Entitlements::class)->plan($this->member)->code);
        $this->assertStringContainsString('Member', $this->get('/admin/subscriptions')->getContent());

        $this->post('/admin/plans', [
            'id' => $pro->id, 'code' => 'pro', 'name_fa' => 'حرفه‌ای', 'name_en' => 'Pro Plus', 'price_USD_monthly' => 900, 'price_IRT_monthly' => 250000,
            'limit_ai_requests' => 800, 'feature_telegram' => '1', 'feature_ai_planner' => '1', 'is_active' => '1', 'is_public' => '1', 'trial_days' => 7, 'sort_order' => 2,
        ])->assertRedirect(route('admin.plans'));
        $pro->refresh();
        $this->assertSame('Pro Plus', $pro->localizedName('en'));
        $this->assertSame(900, $pro->price('USD', 'monthly'));
        $this->assertSame(800, $pro->limit('ai_requests'));
        $this->assertNull($pro->limit('open_tasks'));
        $this->assertFalse($pro->hasFeature('priority_support'));
        $this->assertSame(1, Plan::where('is_default', true)->count());
    }

    public function test_system_page_never_shows_secrets_from_logs(): void
    {
        config(['services.telegram.bot_token' => '123456:SECRET-bot-token-value', 'services.ai.api_key' => 'ai-key-very-secret']);
        $log = storage_path('logs/laravel.log');
        $backup = is_file($log) ? file_get_contents($log) : null;
        file_put_contents($log, '['.now()->toDateTimeString().'] testing.ERROR: call https://api.telegram.org/bot123456:SECRET-bot-token-value/sendMessage failed with key ai-key-very-secret and sk_live_abcdef123'."\n", FILE_APPEND);
        try {
            $html = $this->actingAs($this->admin)->get('/admin/system')->assertOk()->getContent();
            $this->assertStringContainsString('sendMessage failed', $html);
            $this->assertStringNotContainsString('SECRET-bot-token-value', $html);
            $this->assertStringNotContainsString('ai-key-very-secret', $html);
            $this->assertStringNotContainsString('sk_live_abcdef123', $html);
        } finally {
            $backup === null ? @unlink($log) : file_put_contents($log, $backup);
        }
    }

    // ---------------------------------------------------------------- landing content editor

    public function test_admin_can_edit_landing_content_per_language(): void
    {
        $this->actingAs($this->member)->get('/admin/content')->assertForbidden();
        $this->actingAs($this->member)->post('/admin/content', ['lang' => 'fa', 'marketing__hero_title' => 'x'])->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/content?lang=fa')->assertOk()->assertSee(__('marketing.hero_title', [], 'fa'));
        $this->post('/admin/content', [
            'lang' => 'fa',
            'marketing__hero_title' => 'تیتر تازه <script>alert(1)</script>',
            'marketing__faq' => [['سؤال یک', 'پاسخ یک'], ['', ''], ['سؤال دو', 'پاسخ دو']],
            'marketing__benefits' => "مزیت الف\n\nمزیت ب\n",
            'marketing__features_title' => __('marketing.features_title', [], 'fa'), // unchanged → not stored
        ])->assertRedirect(route('admin.content', ['lang' => 'fa']));

        auth()->logout();
        $fa = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('تیتر تازه &lt;script&gt;', $fa);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $fa);
        $this->assertStringContainsString('سؤال دو', $fa);
        $this->assertStringContainsString('مزیت ب', $fa);
        $this->assertStringNotContainsString(\App\Support\LandingContent::default('fa', 'marketing.faq')[0][0], $fa);
        $this->assertStringContainsString(__('marketing.features_title', [], 'fa'), $fa); // other texts untouched
        $this->assertStringContainsString('"@context":"https://schema.org"', $fa);
        $en = $this->get('/en')->assertOk()->getContent();
        $this->assertStringNotContainsString('تیتر تازه', $en);

        $stored = json_decode(\App\Models\AppSetting::find('landing_content')->value, true);
        $this->assertSame(['marketing.hero_title', 'marketing.benefits', 'marketing.faq'], array_keys($stored['fa']));
        $this->assertSame([['سؤال یک', 'پاسخ یک'], ['سؤال دو', 'پاسخ دو']], $stored['fa']['marketing.faq']);

        $this->actingAs($this->admin)->post('/admin/content/reset', ['lang' => 'fa'])->assertRedirect();
        auth()->logout();
        $this->assertStringNotContainsString('تیتر تازه', $this->get('/')->getContent());
    }

    public function test_legal_texts_and_draft_notice_are_editable(): void
    {
        $notice = __('legal.draft_notice', [], 'en');
        $this->assertStringContainsString($notice, $this->get('/en/privacy')->getContent());
        $this->actingAs($this->admin)->post('/admin/content', [
            'lang' => 'en', 'legal__privacy' => [['Who we are', 'HamanTech, Tehran.']], 'hide_legal_notice' => '1',
        ])->assertRedirect();
        auth()->logout();
        $html = $this->get('/en/privacy')->getContent();
        $this->assertStringContainsString('HamanTech, Tehran.', $html);
        $this->assertStringNotContainsString($notice, $html);
        $this->assertStringNotContainsString(\App\Support\LandingContent::default('en', 'legal.privacy')[0][0], $html);
    }

    // ---------------------------------------------------------------- payment settings

    public function test_payment_settings_are_saved_encrypted_and_used_by_the_gateways(): void
    {
        config(['billing.providers.zarinpal' => ['enabled' => false, 'merchant_id' => null, 'sandbox' => false], 'billing.providers.stripe' => ['enabled' => false, 'secret' => null]]);
        $this->actingAs($this->member)->get('/admin/payment-settings')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/payment-settings')->assertOk();

        $merchant = '11111111-2222-3333-4444-555555555555';
        $this->post('/admin/payment-settings', [
            'zarinpal_enabled' => '1', 'zarinpal_sandbox' => '1', 'zarinpal_merchant_id' => $merchant,
            'stripe_secret' => 'sk_test_abcdef123456',
        ])->assertRedirect(route('admin.payment-settings'))->assertSessionHasNoErrors();

        $raw = \App\Models\AppSetting::find('pay_zarinpal_merchant_id')->value;
        $this->assertStringNotContainsString($merchant, $raw);
        $this->assertStringNotContainsString('sk_test_abcdef123456', \App\Models\AppSetting::find('pay_stripe_secret')->value);

        $billing = app(\App\Services\Billing\BillingService::class);
        $this->assertTrue($billing->gateway('zarinpal')->isConfigured());
        $this->assertFalse($billing->gateway('stripe')->isConfigured()); // key saved but not enabled

        $html = $this->get('/admin/payment-settings')->getContent();
        $this->assertStringNotContainsString($merchant, $html);
        $this->assertStringNotContainsString('sk_test_abcdef123456', $html);
        $this->assertStringContainsString('••••5555', $html);

        // Blank keeps the saved secret; the checkout sends it to the sandbox host.
        $this->post('/admin/payment-settings', ['zarinpal_enabled' => '1', 'zarinpal_sandbox' => '1', 'zarinpal_merchant_id' => ''])->assertRedirect();
        \Illuminate\Support\Facades\Http::fake(['https://sandbox.zarinpal.com/*' => \Illuminate\Support\Facades\Http::response(['data' => ['code' => 100, 'authority' => 'A1'], 'errors' => []])]);
        $pro = Plan::where('code', 'pro')->first();
        $this->actingAs($this->member)->post('/billing/checkout', ['plan' => $pro->id, 'interval' => 'monthly', 'provider' => 'zarinpal'])
            ->assertRedirect('https://sandbox.zarinpal.com/pg/StartPay/A1');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r['merchant_id'] === $merchant);

        // Invalid values are rejected; clearing falls back to .env.
        $this->actingAs($this->admin)->post('/admin/payment-settings', ['stripe_secret' => 'pk_live_nope'])->assertSessionHasErrors('stripe_secret');
        $this->post('/admin/payment-settings', ['clear_zarinpal_merchant_id' => '1', 'zarinpal_enabled' => '1'])->assertRedirect();
        $this->assertNull(\App\Models\AppSetting::find('pay_zarinpal_merchant_id'));
        $this->assertFalse(app(\App\Services\Billing\BillingService::class)->gateway('zarinpal')->isConfigured());
    }

    // ---------------------------------------------------------------- fonts

    public function test_bundled_fonts_are_self_hosted_and_used_on_every_layout(): void
    {
        $this->assertFileExists(public_path('fonts/vazirmatn/Vazirmatn-wght.woff2'));
        $this->assertFileExists(public_path('fonts/poppins/poppins-latin-400-normal.woff2'));
        foreach (['/', '/login'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringContainsString('fonts/vazirmatn/Vazirmatn-wght.woff2', $html);
            $this->assertStringContainsString('fonts/poppins/poppins-latin-700-normal.woff2', $html);
            $this->assertTrue(str_contains($html, 'font-family:var(--font)') || str_contains($html, 'css/haman.css'), $url.' uses the font variable');
            $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        }
        // App pages share the design system stylesheet, which applies the font variable.
        $this->assertStringContainsString('font-family: var(--font)', (string) file_get_contents(public_path('css/haman.css')));
        $this->assertStringContainsString('css/haman.css', $this->actingAs($this->member)->get('/planner')->getContent());
        $this->assertStringContainsString('fonts/vazirmatn/Vazirmatn-wght.woff2', $this->get('/planner')->getContent());
    }

    public function test_admin_can_upload_a_persian_font_and_switch_the_english_font(): void
    {
        $woff2 = file_get_contents(public_path('fonts/vazirmatn/Vazirmatn-wght.woff2'));
        $upload = \Illuminate\Http\UploadedFile::fake()->createWithContent('IRANSansWeb.woff2', $woff2);
        $base = ['app_name' => 'Haman Planner'];

        $this->actingAs($this->member)->post('/admin/settings', $base + ['font_fa' => 'custom', 'font_regular' => $upload])->assertForbidden();

        $bad = \Illuminate\Http\UploadedFile::fake()->createWithContent('x.woff2', '<?php echo 1;');
        $this->actingAs($this->admin)->post('/admin/settings', $base + ['font_regular' => $bad])->assertSessionHasErrors('font_regular');
        $this->assertFalse(\App\Support\Fonts::hasCustom());

        $this->post('/admin/settings', $base + ['font_fa' => 'custom', 'font_fa_name' => 'IRANSans', 'font_en' => 'poppins', 'font_regular' => $upload])
            ->assertRedirect(route('admin.settings'))->assertSessionHasNoErrors();
        $html = $this->get('/en/pricing')->getContent();
        $this->assertStringNotContainsString('Vazirmatn-wght.woff2', $html);
        $this->assertMatchesRegularExpression('#/fonts/custom/regular\.font\?v=[0-9a-f]{12}#', $html);

        $font = $this->get(route('fonts.custom', ['weight' => 'regular']))->assertOk()->assertHeader('Content-Type', 'font/woff2');
        $this->assertSame($woff2, $font->getContent());
        $this->get('/fonts/custom/bold.font')->assertNotFound();

        $this->post('/admin/settings', $base + ['font_fa' => 'custom', 'font_en' => 'persian'])->assertRedirect();
        $html = $this->get('/en/pricing')->getContent();
        $this->assertStringNotContainsString('poppins-latin', $html);
        $this->assertStringContainsString('/fonts/custom/regular.font', $html);

        $this->post('/admin/settings', $base + ['font_fa' => 'custom', 'remove_font_regular' => '1'])->assertSessionHasErrors('font_regular');
        $this->assertStringContainsString('Vazirmatn-wght.woff2', $this->get('/en/pricing')->getContent());
    }
}
