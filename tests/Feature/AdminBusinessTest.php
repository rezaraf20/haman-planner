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
}
