<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\User;
use App\Services\AI\AIProviderFactory;
use App\Support\PlannerUserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AiConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai.api_key' => null]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'is_admin' => true, 'onboarded_at' => now(), 'locale' => 'en']);
    }

    private function answer(int $prompt = 12, int $completion = 3): array
    {
        return ['choices' => [['message' => ['content' => 'ok']]], 'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion]];
    }

    public function test_admin_adds_a_connection_with_an_encrypted_key_that_is_never_shown(): void
    {
        $this->actingAs($this->admin)->post(route('admin.ai.store'), [
            'name' => 'Main', 'driver' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-or-secret-123456', 'priority' => 1,
            'monthly_token_budget' => 1000, 'input_price_per_million' => 0.15, 'output_price_per_million' => 0.6, 'is_active' => 1,
        ])->assertRedirect(route('admin.ai'));
        $p = AiProvider::first();
        $this->assertSame('sk-or-secret-123456', $p->api_key);
        $this->assertStringNotContainsString('sk-or-secret', (string) \DB::table('ai_providers')->value('api_key'));
        $this->assertArrayNotHasKey('api_key', $p->toArray());
        $html = $this->get(route('admin.ai'))->assertOk()->getContent();
        $this->assertStringNotContainsString('sk-or-secret-123456', $html);
        $this->assertStringContainsString('••••3456', $html);

        // Editing without a key keeps the old one.
        $this->post(route('admin.ai.update', $p), ['name' => 'Main 2', 'driver' => 'openrouter', 'model' => 'x', 'api_key' => '', 'priority' => 1, 'is_active' => 1])->assertRedirect();
        $this->assertSame('sk-or-secret-123456', $p->fresh()->api_key);
        $this->assertSame('Main 2', $p->fresh()->name);
        // custom needs a base URL
        $this->post(route('admin.ai.store'), ['name' => 'C', 'driver' => 'custom', 'model' => 'm', 'api_key' => 'k'])->assertSessionHasErrors('base_url');

        $member = User::create(['name' => 'M', 'email' => 'm@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'onboarded_at' => now()]);
        $this->actingAs($member)->get(route('admin.ai'))->assertForbidden();
    }

    public function test_calls_fall_back_in_priority_order_and_usage_is_recorded(): void
    {
        $bad = AiProvider::create(['name' => 'Primary', 'driver' => 'custom', 'base_url' => 'https://bad.test/v1', 'api_key' => 'k1', 'model' => 'm1', 'priority' => 1, 'is_active' => true]);
        $good = AiProvider::create(['name' => 'Backup', 'driver' => 'custom', 'base_url' => 'https://good.test/v1', 'api_key' => 'k2', 'model' => 'm2', 'priority' => 2, 'is_active' => true]);
        AiProvider::create(['name' => 'Off', 'driver' => 'custom', 'base_url' => 'https://off.test/v1', 'api_key' => 'k3', 'model' => 'm3', 'priority' => 0, 'is_active' => false]);
        Http::fake(['https://bad.test/*' => Http::response(['error' => ['message' => 'quota']], 429), 'https://good.test/*' => Http::response($this->answer(100, 20))]);

        $user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret-pass-123', 'is_active' => true]);
        PlannerUserContext::set($user->id);
        $r = AIProviderFactory::make()->chat([['role' => 'user', 'content' => 'hi']], ['_feature' => 'recommendations']);
        PlannerUserContext::clear();

        $this->assertSame('ok', $r['choices'][0]['message']['content']);
        Http::assertNotSent(fn (Request $x) => str_contains($x->url(), 'off.test'));
        Http::assertNotSent(fn (Request $x) => isset($x['_feature']));
        $this->assertSame(['provider' => 'Backup', 'model' => 'm2', 'id' => $good->id], AIProviderFactory::lastUsed());
        $ok = AiUsage::where('success', true)->first();
        $this->assertSame([$good->id, 100, 20, 120, 'recommendations', $user->id], [$ok->ai_provider_id, $ok->prompt_tokens, $ok->completion_tokens, $ok->total_tokens, $ok->feature, $ok->user_id]);
        $this->assertTrue(AiUsage::where('success', false)->where('ai_provider_id', $bad->id)->exists());
        $this->assertNotNull($bad->fresh()->last_error);
        $this->assertSame(120, $good->fresh()->tokensThisMonth());
    }

    public function test_a_connection_over_its_monthly_budget_is_skipped(): void
    {
        $capped = AiProvider::create(['name' => 'Capped', 'driver' => 'custom', 'base_url' => 'https://a.test/v1', 'api_key' => 'k1', 'model' => 'm1', 'priority' => 1, 'is_active' => true, 'monthly_token_budget' => 100]);
        AiProvider::create(['name' => 'Next', 'driver' => 'custom', 'base_url' => 'https://b.test/v1', 'api_key' => 'k2', 'model' => 'm2', 'priority' => 2, 'is_active' => true]);
        AiUsage::create(['ai_provider_id' => $capped->id, 'provider' => 'Capped', 'model' => 'm1', 'total_tokens' => 150, 'created_at' => now()]);
        Http::fake(['https://a.test/*' => Http::response($this->answer()), 'https://b.test/*' => Http::response($this->answer())]);

        AIProviderFactory::make()->chat([['role' => 'user', 'content' => 'x']]);
        Http::assertNotSent(fn (Request $x) => str_contains($x->url(), 'a.test'));
        $this->assertSame(0, $capped->remainingBudget());
        $this->assertTrue($capped->overBudget());
        // Last month's usage does not count.
        AiUsage::query()->update(['created_at' => now()->subMonth()]);
        $this->assertFalse($capped->fresh()->overBudget());
    }

    public function test_without_connections_the_env_settings_still_work(): void
    {
        $this->assertFalse(AIProviderFactory::available());
        config(['services.ai.provider' => 'openai', 'services.ai.api_key' => 'env-key', 'services.ai.base_url' => 'https://env.test/v1', 'services.ai.model' => 'gpt-x']);
        Http::fake(['https://env.test/*' => Http::response($this->answer())]);
        $this->assertTrue(AIProviderFactory::available());
        AIProviderFactory::make()->chat([['role' => 'user', 'content' => 'x']]);
        Http::assertSent(fn (Request $x) => $x->url() === 'https://env.test/v1/chat/completions' && $x['model'] === 'gpt-x' && $x->hasHeader('Authorization', 'Bearer env-key'));
        $this->assertSame('openai (.env)', AiUsage::first()->provider);
        $this->assertNull(AiUsage::first()->ai_provider_id);
    }

    public function test_test_button_and_balance_for_openrouter_and_deepseek(): void
    {
        $or = AiProvider::create(['name' => 'OR', 'driver' => 'openrouter', 'api_key' => 'k1', 'model' => 'm', 'is_active' => true]);
        $ds = AiProvider::create(['name' => 'DS', 'driver' => 'deepseek', 'api_key' => 'k2', 'model' => 'deepseek-chat', 'is_active' => true]);
        $oa = AiProvider::create(['name' => 'OA', 'driver' => 'openai', 'api_key' => 'k3', 'model' => 'gpt-4o-mini', 'is_active' => true]);
        Http::fake([
            'https://openrouter.ai/api/v1/credits' => Http::response(['data' => ['total_credits' => 10, 'total_usage' => 2.5]]),
            'https://openrouter.ai/api/v1/chat/completions' => Http::response($this->answer(5, 1)),
            'https://api.deepseek.com/user/balance' => Http::response(['is_available' => true, 'balance_infos' => [['currency' => 'USD', 'total_balance' => '4.20']]]),
        ]);
        $this->actingAs($this->admin)->post(route('admin.ai.test', $or))->assertSessionHas('status');
        $this->assertSame('test', AiUsage::first()->feature);
        $this->post(route('admin.ai.balance', $or))->assertSessionHas('status');
        $this->assertSame(7.5, $or->fresh()->balance['amount']);
        $this->post(route('admin.ai.balance', $ds))->assertSessionHas('status');
        $this->assertSame(4.2, $ds->fresh()->balance['amount']);
        $this->post(route('admin.ai.balance', $oa))->assertSessionHasErrors('ai');
        $this->get(route('admin.ai'))->assertOk()->assertSee('7.50 USD')->assertSee(__('admin.ai.balance_unsupported', [], 'en'));
    }

    public function test_ai_features_turn_on_when_a_connection_is_added_in_admin(): void
    {
        $planning = app(\App\Services\Planner\PlanningAssistantService::class);
        $user = User::create(['name' => 'U', 'email' => 'u2@example.com', 'password' => 'secret-pass-123', 'is_active' => true]);
        $this->assertFalse($planning->aiAvailable($user));
        AiProvider::create(['name' => 'P', 'driver' => 'openai', 'api_key' => 'k', 'model' => 'm', 'is_active' => true]);
        $this->assertTrue($planning->aiAvailable($user));
    }
}
