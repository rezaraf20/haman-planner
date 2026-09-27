<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class BillingAndEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.ai.provider' => 'openai', 'services.ai.api_key' => 'k', 'services.ai.base_url' => 'https://ai.test/v1', 'services.ai.model' => 'm',
            'billing.providers.zarinpal' => ['enabled' => true, 'merchant_id' => 'MERCHANT-TEST', 'sandbox' => true, 'currency' => 'IRT'],
            'billing.providers.stripe' => ['enabled' => true, 'secret' => 'sk_test_x', 'currency' => 'USD'],
        ]);
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'onboarded_at' => now()]);
    }

    private function api(User $u): array
    {
        $plain = 'tok-'.$u->id.'-'.str_repeat('y', 40);
        ApiToken::firstOrCreate(['token_hash' => hash('sha256', $plain)], ['user_id' => $u->id, 'name' => 't', 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function ents(): Entitlements
    {
        return app(Entitlements::class);
    }

    private function aiOk(): void
    {
        Http::fake([
            'https://ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['summary' => 'ok', 'risks' => [], 'recommendations' => []])]]]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    // ---------------------------------------------------------------- entitlements

    public function test_without_any_plan_everything_is_allowed(): void
    {
        $this->assertTrue($this->ents()->canUse($this->user, 'ai_planner'));
        $this->assertNull($this->ents()->limit($this->user, 'ai_requests'));
        $this->ents()->consume($this->user, 'ai_requests');
        $this->assertNull($this->ents()->remaining($this->user, 'ai_requests'));
    }

    public function test_ai_requests_are_metered_and_blocked_with_402_when_exhausted(): void
    {
        $this->seed(PlanSeeder::class);
        Plan::where('code', 'free')->update(['limits' => json_encode(['ai_requests' => 2])]);
        $this->aiOk();
        $h = $this->api($this->user);

        $this->withHeaders($h)->postJson('/api/planner/ai-recommendations', [])->assertOk();
        $this->withHeaders($h)->postJson('/api/planner/ai-recommendations', [])->assertOk();
        $this->withHeaders($h)->postJson('/api/planner/ai-recommendations', [])
            ->assertStatus(402)->assertJsonPath('metric', 'ai_requests')->assertJsonPath('limit', 2)
            ->assertJsonPath('upgrade_url', route('billing.index'));

        $this->assertSame(2, (int) UsageCounter::where('user_id', $this->user->id)->where('metric', 'ai_requests')->value('used'));
        $this->assertSame(0, $this->ents()->remaining($this->user, 'ai_requests'));
    }

    public function test_failed_ai_call_refunds_the_unit(): void
    {
        $this->seed(PlanSeeder::class);
        Http::fake(['https://ai.test/*' => Http::response('down', 500)]);
        $this->withHeaders($this->api($this->user))->postJson('/api/planner/ai-recommendations', []);
        $this->assertSame(0, $this->ents()->used($this->user, 'ai_requests'));
    }

    public function test_admins_are_never_limited(): void
    {
        $this->seed(PlanSeeder::class);
        Plan::where('code', 'free')->update(['limits' => json_encode(['ai_requests' => 0, 'open_tasks' => 0]), 'features' => json_encode(['ai_planner' => false])]);
        $admin = User::create(['name' => 'Ad', 'email' => 'ad@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'is_admin' => true]);
        $this->aiOk();
        $this->withHeaders($this->api($admin))->postJson('/api/planner/ai-recommendations', [])->assertOk();
        $this->withHeaders($this->api($admin))->postJson('/api/tasks', ['title' => 'x'])->assertCreated();
    }

    public function test_count_limits_block_new_records_but_keep_existing_ones(): void
    {
        $this->seed(PlanSeeder::class);
        Task::create(['user_id' => $this->user->id, 'title' => 'Existing 1']);
        Task::create(['user_id' => $this->user->id, 'title' => 'Existing 2']);
        Plan::where('code', 'free')->update(['limits' => json_encode(['open_tasks' => 2])]);
        $h = $this->api($this->user);

        $this->withHeaders($h)->postJson('/api/tasks', ['title' => 'Third'])->assertStatus(402)->assertJsonPath('metric', 'open_tasks');
        $this->assertSame(2, Task::withoutGlobalScopes()->where('user_id', $this->user->id)->count());
        $this->withHeaders($h)->getJson('/api/tasks')->assertOk()->assertJsonCount(2, 'data');

        // Completing one frees a slot.
        Task::withoutGlobalScopes()->where('title', 'Existing 1')->update(['status' => 'completed']);
        $this->withHeaders($h)->postJson('/api/tasks', ['title' => 'Third'])->assertCreated();
    }

    public function test_missing_feature_is_reported_as_402(): void
    {
        $this->seed(PlanSeeder::class);
        Plan::where('code', 'free')->update(['features' => json_encode(['ai_planner' => false])]);
        $this->assertFalse($this->ents()->canUse($this->user, 'ai_planner'));
        $this->withHeaders($this->api($this->user))->postJson('/api/planner/ai-recommendations', [])
            ->assertStatus(402)->assertJsonPath('metric', 'feature_ai_planner');
    }

    // ---------------------------------------------------------------- trial / cancel / expiry

    public function test_trial_cancel_resume_and_expiry_lifecycle(): void
    {
        $this->seed(PlanSeeder::class);
        $billing = app(BillingService::class);
        $pro = Plan::where('code', 'pro')->first();

        $this->actingAs($this->user)->post('/billing/trial', ['plan' => $pro->id])->assertRedirect(route('billing.index'));
        $this->assertSame('pro', $this->ents()->plan($this->user)->code);
        $this->assertSame(500, $this->ents()->limit($this->user, 'ai_requests'));

        // A second trial is refused.
        try {
            $billing->startTrial($this->user, $pro);
            $this->fail('A second trial must be refused');
        } catch (BillingException $e) {
            $this->assertSame('billing.errors.trial_used', $e->getMessage());
        }

        // Cancel keeps access until the period ends; resume restores it.
        $this->post('/billing/cancel')->assertRedirect(route('billing.index'));
        $sub = Subscription::where('user_id', $this->user->id)->first();
        $this->assertSame('canceled', $sub->status);
        $this->assertSame('pro', app(Entitlements::class)->plan($this->user)->code);
        $this->post('/billing/resume')->assertRedirect();
        $this->assertSame('trialing', $sub->fresh()->status);

        // After the period, the lifecycle job expires it and the default plan applies.
        $this->travel(15)->days();
        $this->assertSame(1, $billing->processLifecycle()['expired']);
        $this->assertSame('free', app(Entitlements::class)->plan($this->user)->code);
    }

    public function test_renewal_reminder_is_sent_once(): void
    {
        $this->seed(PlanSeeder::class);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->user->update(['telegram_chat_id' => '777', 'locale' => 'en']);
        app(BillingService::class)->grant($this->user, Plan::where('code', 'pro')->first(), 1);
        Subscription::where('user_id', $this->user->id)->update(['current_period_end' => now()->addDays(2)]);

        $this->assertSame(1, app(BillingService::class)->processLifecycle(app(\App\Services\Telegram\TelegramService::class))['reminded']);
        $this->assertSame(0, app(BillingService::class)->processLifecycle(app(\App\Services\Telegram\TelegramService::class))['reminded']);
        $sent = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), 'sendMessage'));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Pro', $sent->first()[0]->data()['text']);
    }

    // ---------------------------------------------------------------- Zarinpal

    private function zarinpalFake(int $verifyCode = 100): void
    {
        Http::fake([
            'https://sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000123'], 'errors' => []]),
            'https://sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => $verifyCode, 'ref_id' => 98765, 'card_pan' => '5022****1234'], 'errors' => []]),
        ]);
    }

    private function checkout(string $provider, string $locale = 'fa', string $interval = 'monthly'): Payment
    {
        $pro = Plan::where('code', 'pro')->first();
        $this->actingAs($this->user)->withSession(['locale' => $locale])
            ->post('/billing/checkout', ['plan' => $pro->id, 'interval' => $interval, 'provider' => $provider])
            ->assertRedirect();
        return Payment::latest('id')->first();
    }

    private function callbackUrl(Payment $p, array $query): string
    {
        return URL::temporarySignedRoute('billing.callback', now()->addDay(), ['payment' => $p->id]).'&'.http_build_query($query);
    }

    public function test_zarinpal_successful_payment_activates_plan_once(): void
    {
        $this->seed(PlanSeeder::class);
        $this->zarinpalFake();
        $payment = $this->checkout('zarinpal');

        $this->assertSame('pending', $payment->status);
        $this->assertSame(190000, (int) $payment->amount);
        $this->assertSame('IRT', $payment->currency);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'request.json') && $r['amount'] === 190000 && $r['merchant_id'] === 'MERCHANT-TEST'
            && str_contains($r['callback_url'], 'signature='));

        // A forged authority is rejected even though the provider would confirm the real one.
        $forged = $this->checkout('zarinpal');
        $this->get($this->callbackUrl($forged, ['Authority' => 'FORGED', 'Status' => 'OK']));
        $this->assertSame('failed', $forged->fresh()->status);
        $this->assertSame(0, Subscription::count());

        $url = $this->callbackUrl($payment, ['Authority' => 'A0000000000000000000000000000123', 'Status' => 'OK']);
        $this->get($url)->assertRedirect(route('billing.index'));
        $this->get($url)->assertRedirect(route('billing.index')); // replay

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('98765', $payment->transaction_reference);
        $this->assertSame(1, Subscription::where('user_id', $this->user->id)->where('status', 'active')->count());
        $this->assertSame(1, Invoice::where('user_id', $this->user->id)->count());
        $this->assertSame('pro', app(Entitlements::class)->plan($this->user)->code);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), 'verify.json')));

        $invoice = Invoice::first();
        $this->get(route('billing.invoice', $invoice))->assertOk()->assertSee($invoice->number);
    }

    public function test_zarinpal_failed_or_canceled_payment_never_activates(): void
    {
        $this->seed(PlanSeeder::class);
        $this->zarinpalFake(-51);

        $p1 = $this->checkout('zarinpal');
        $this->get($this->callbackUrl($p1, ['Authority' => 'A0000000000000000000000000000123', 'Status' => 'NOK']));
        $this->assertSame('canceled', $p1->fresh()->status);

        $p2 = $this->checkout('zarinpal');
        $this->get($this->callbackUrl($p2, ['Authority' => 'A0000000000000000000000000000123', 'Status' => 'OK']));
        $this->assertSame('failed', $p2->fresh()->status);


        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_callback_requires_a_valid_signature(): void
    {
        $this->seed(PlanSeeder::class);
        $this->zarinpalFake();
        $payment = $this->checkout('zarinpal');
        $this->get('/billing/callback/'.$payment->id.'?Authority=A0000000000000000000000000000123&Status=OK')->assertForbidden();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_provider_errors_and_unconfigured_providers_do_not_create_access(): void
    {
        $this->seed(PlanSeeder::class);
        Http::fake(['https://sandbox.zarinpal.com/*' => Http::response(['data' => [], 'errors' => ['code' => -9, 'message' => 'bad']], 422)]);
        $pro = Plan::where('code', 'pro')->first();
        $this->actingAs($this->user)->from('/billing')->post('/billing/checkout', ['plan' => $pro->id, 'interval' => 'monthly', 'provider' => 'zarinpal'])
            ->assertRedirect('/billing')->assertSessionHasErrors('billing');
        $this->assertSame('failed', Payment::first()->status);

        config(['billing.providers.stripe.enabled' => false]);
        $this->from('/billing')->post('/billing/checkout', ['plan' => $pro->id, 'interval' => 'monthly', 'provider' => 'stripe'])
            ->assertSessionHasErrors('billing');
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, Subscription::count());

        // Free plan cannot be "bought".
        $free = Plan::where('code', 'free')->first();
        $this->post('/billing/checkout', ['plan' => $free->id, 'interval' => 'monthly', 'provider' => 'zarinpal'])->assertSessionHasErrors('billing');
    }

    // ---------------------------------------------------------------- Stripe

    public function test_stripe_payment_is_verified_with_stripe(): void
    {
        $this->seed(PlanSeeder::class);
        $amount = 600;
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
            'https://api.stripe.com/v1/checkout/sessions/cs_test_1' => function () use (&$amount) {
                return Http::response(['id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => $amount, 'currency' => 'usd',
                    'client_reference_id' => (string) Payment::latest('id')->value('id'), 'payment_intent' => 'pi_1']);
            },
        ]);

        // Wrong amount reported by the provider → rejected.
        $amount = 1;
        $p1 = $this->checkout('stripe', 'en');
        $this->assertSame('USD', $p1->currency);
        $this->get($this->callbackUrl($p1, ['session_id' => 'cs_test_1']));
        $this->assertSame('failed', $p1->fresh()->status);
        $this->assertSame(0, Subscription::count());

        $amount = 600;
        $p2 = $this->checkout('stripe', 'en');
        $this->get($this->callbackUrl($p2, ['session_id' => 'cs_test_1']))->assertRedirect(route('billing.index'));
        $this->assertSame('paid', $p2->fresh()->status);
        $this->assertSame('pi_1', $p2->fresh()->transaction_reference);
        $this->assertSame('pro', app(Entitlements::class)->plan($this->user)->code);
    }

    // ---------------------------------------------------------------- pages & isolation

    public function test_billing_pages_render_in_both_languages_and_invoices_are_private(): void
    {
        $this->seed(PlanSeeder::class);
        foreach (['fa', 'en'] as $locale) {
            $this->user->update(['locale' => $locale]);
            $html = $this->actingAs($this->user)->get('/billing')->assertOk()->getContent();
            $this->assertStringContainsString($locale === 'fa' ? 'dir="rtl"' : 'dir="ltr"', $html);
            $this->assertStringContainsString(Plan::where('code', 'pro')->first()->localizedName($locale), $html);
        }

        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'onboarded_at' => now()]);
        $payment = Payment::create(['user_id' => $other->id, 'plan_id' => Plan::first()->id, 'provider' => 'zarinpal', 'amount' => 1, 'currency' => 'IRT', 'status' => 'paid', 'billing_interval' => 'monthly']);
        $invoice = app(BillingService::class)->issueInvoice($payment, $other, Plan::first());
        $this->actingAs($this->user)->get(route('billing.invoice', $invoice))->assertNotFound();
        $this->actingAs($other)->get(route('billing.invoice', $invoice))->assertOk();
    }
}
