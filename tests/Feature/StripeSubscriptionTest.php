<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LifecycleMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class StripeSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const WHSEC = 'whsec_testsecret123';
    private User $user;
    /** @var array<string,array> fake Stripe objects */
    private array $stripe = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.providers.zarinpal' => ['enabled' => true, 'merchant_id' => 'MERCHANT-TEST', 'sandbox' => true, 'currency' => 'IRT'],
            'billing.providers.stripe' => ['enabled' => true, 'secret' => 'sk_test_x', 'webhook_secret' => self::WHSEC, 'currency' => 'USD'],
        ]);
        Mail::fake();
        $this->seed(PlanSeeder::class);
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'en']);

        $this->stripe['session'] = ['id' => 'cs_sub_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_sub_1'];
        Http::fake(function (Request $r) {
            $url = $r->url();
            if ($url === 'https://api.stripe.com/v1/checkout/sessions') {
                return Http::response($this->stripe['session']);
            }
            if (str_starts_with($url, 'https://api.stripe.com/v1/checkout/sessions/')) {
                return Http::response(($this->stripe['verify'] ?? []) + ['id' => 'cs_sub_1', 'mode' => 'subscription', 'payment_status' => 'paid', 'amount_total' => 600, 'currency' => 'usd',
                    'client_reference_id' => (string) Payment::latest('id')->value('id'), 'subscription' => 'sub_1', 'customer' => 'cus_1', 'invoice' => 'in_first']);
            }
            if (str_starts_with($url, 'https://api.stripe.com/v1/subscriptions/')) {
                if (!empty($this->stripe['subscriptions_fail'])) {
                    return Http::response(['error' => ['message' => 'boom']], 500);
                }
                return Http::response(['id' => basename(parse_url($url, PHP_URL_PATH)), 'cancel_at_period_end' => $r['cancel_at_period_end'] ?? null]);
            }
            return Http::response([], 404);
        });
    }

    private function subscribe(string $plan = 'pro'): Subscription
    {
        $p = Plan::where('code', $plan)->first();
        $this->actingAs($this->user)->withSession(['locale' => 'en'])
            ->post('/billing/checkout', ['plan' => $p->id, 'interval' => 'monthly', 'provider' => 'stripe'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_sub_1');
        $payment = Payment::latest('id')->first();
        $this->get(URL::temporarySignedRoute('billing.callback', now()->addDay(), ['payment' => $payment->id]).'&session_id=cs_sub_1')->assertRedirect(route('billing.index'));
        return Subscription::where('user_id', $this->user->id)->latest('id')->first();
    }

    private function webhook(array $event, ?string $secret = self::WHSEC, ?int $t = null)
    {
        $payload = json_encode($event);
        $t ??= time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, (string) $secret);
        return $this->call('POST', '/api/billing/webhook/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    private function event(string $id, string $type, array $object): array
    {
        return ['id' => $id, 'type' => $type, 'data' => ['object' => $object]];
    }

    public function test_checkout_creates_an_auto_renewing_subscription_after_server_verification(): void
    {
        $sub = $this->subscribe();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions' && $r['mode'] === 'subscription'
            && $r['line_items[0][price_data][recurring][interval]'] === 'month' && $r['subscription_data[metadata][user_id]'] === (string) $this->user->id);
        $this->assertTrue($sub->auto_renew);
        $this->assertSame('sub_1', $sub->provider_reference);
        $this->assertSame('cus_1', $sub->provider_customer_id);
        $this->assertSame('active', $sub->status);
        $this->assertSame('pro', app(Entitlements::class)->plan($this->user)->code);
        $this->assertSame('subscription', Payment::first()->meta['mode']);
        Mail::assertQueued(LifecycleMail::class, fn ($m) => $m->type === 'subscription_started');

        // The webhook for the same checkout is a no-op (already activated).
        $this->webhook($this->event('evt_cs', 'checkout.session.completed', ['id' => 'cs_sub_1', 'metadata' => ['payment_id' => (string) Payment::first()->id]]))
            ->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_webhook_can_activate_when_the_browser_never_returns(): void
    {
        $pro = Plan::where('code', 'pro')->first();
        $this->actingAs($this->user)->post('/billing/checkout', ['plan' => $pro->id, 'interval' => 'monthly', 'provider' => 'stripe']);
        $payment = Payment::first();
        $this->assertSame(0, Subscription::count());
        $this->webhook($this->event('evt_cs2', 'checkout.session.completed', ['id' => 'cs_sub_1', 'metadata' => ['payment_id' => (string) $payment->id]]))
            ->assertOk()->assertJson(['status' => 'processed']);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('sub_1', Subscription::first()->provider_reference);
    }

    public function test_signature_is_required_and_verified(): void
    {
        $sub = $this->subscribe();
        $renew = $this->event('evt_bad', 'invoice.paid', ['id' => 'in_2', 'subscription' => 'sub_1', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 600, 'currency' => 'usd']);

        $this->postJson('/api/billing/webhook/stripe', $renew)->assertStatus(400);                        // no header
        $this->webhook($renew, 'whsec_wrong')->assertStatus(400);                                          // wrong secret
        $this->webhook($renew, self::WHSEC, time() - 3600)->assertStatus(400);                             // replayed old timestamp
        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(1, Payment::count());
        $this->assertEquals($sub->current_period_end, $sub->fresh()->current_period_end);

        config(['billing.providers.stripe.webhook_secret' => null]);
        $this->webhook($renew)->assertStatus(503);
    }

    public function test_renewal_extends_the_period_once_even_if_the_event_is_repeated(): void
    {
        $sub = $this->subscribe();
        $end = now()->addMonths(2)->startOfSecond();
        $invoice = ['id' => 'in_2', 'subscription' => 'sub_1', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 600, 'currency' => 'usd',
            'lines' => ['data' => [['period' => ['start' => now()->addMonth()->timestamp, 'end' => $end->timestamp]]]]];

        $this->webhook($this->event('evt_r1', 'invoice.paid', $invoice))->assertOk()->assertJson(['status' => 'processed']);
        $this->webhook($this->event('evt_r1', 'invoice.paid', $invoice))->assertOk()->assertJson(['status' => 'duplicate']);
        // Stripe also sends invoice.payment_succeeded for the same invoice: still one payment.
        $this->webhook($this->event('evt_r2', 'invoice.payment_succeeded', $invoice))->assertOk();

        $this->assertSame(2, Payment::where('status', 'paid')->count());
        $renewal = Payment::where('provider_reference', 'in_2')->first();
        $this->assertSame($sub->id, $renewal->subscription_id);
        $this->assertSame(2, Invoice::count());
        $this->assertSame($end->timestamp, $sub->fresh()->current_period_end->timestamp);

        // Newer API shape (parent.subscription_details) is understood too.
        $this->webhook($this->event('evt_r3', 'invoice.paid', ['id' => 'in_3', 'parent' => ['subscription_details' => ['subscription' => 'sub_1']], 'billing_reason' => 'subscription_cycle', 'amount_paid' => 600, 'currency' => 'usd']))
            ->assertJson(['status' => 'processed']);
        $this->assertSame(3, Payment::where('status', 'paid')->count());
        // Unknown subscriptions are ignored.
        $this->webhook($this->event('evt_r4', 'invoice.paid', ['id' => 'in_x', 'subscription' => 'sub_unknown', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 600, 'currency' => 'usd']))
            ->assertJson(['status' => 'ignored']);
    }

    public function test_failed_renewal_keeps_access_during_grace_then_expires(): void
    {
        $sub = $this->subscribe();
        $this->webhook($this->event('evt_f1', 'invoice.payment_failed', ['id' => 'in_f', 'subscription' => 'sub_1', 'billing_reason' => 'subscription_cycle']))->assertJson(['status' => 'processed']);
        $this->webhook($this->event('evt_f2', 'invoice.payment_failed', ['id' => 'in_f', 'subscription' => 'sub_1', 'billing_reason' => 'subscription_cycle']))->assertOk();
        $this->assertSame('past_due', $sub->fresh()->status);
        Mail::assertQueued(LifecycleMail::class, fn ($m) => $m->type === 'payment_failed');
        $this->assertSame(1, \DB::table('notification_deliveries')->where('type', 'payment_failed')->count(), 'one email per failed invoice');
        $this->assertSame(1, \App\Models\ProductEvent::where('event', 'payment_failed')->count());

        $this->travelTo($sub->current_period_end->copy()->addDays(3));
        $this->assertSame('pro', app(Entitlements::class)->plan($this->user)->code, 'grace period');
        $this->assertSame(0, app(BillingService::class)->processLifecycle()['expired']);
        $this->get('/billing')->assertOk()->assertSee(__('billing.status.past_due', [], 'en'));

        $this->travelTo($sub->current_period_end->copy()->addDays(8));
        $this->assertSame(1, app(BillingService::class)->processLifecycle()['expired']);
        app(Entitlements::class)->forget($this->user);
        $this->assertSame('free', app(Entitlements::class)->plan($this->user)->code);
    }

    public function test_cancel_and_resume_go_through_stripe_and_webhooks_sync_state(): void
    {
        $this->freezeSecond();
        $sub = $this->subscribe();
        $this->post('/billing/cancel')->assertRedirect(route('billing.index'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_1' && $r['cancel_at_period_end'] === 'true');
        $this->assertSame('canceled', $sub->fresh()->status);
        Mail::assertQueued(LifecycleMail::class, fn ($m) => $m->type === 'subscription_canceled');

        $this->post('/billing/resume')->assertRedirect(route('billing.index'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_1' && $r['cancel_at_period_end'] === 'false');
        $this->assertSame('active', $sub->fresh()->status);

        // Canceled from the Stripe dashboard / customer portal.
        $this->webhook($this->event('evt_u1', 'customer.subscription.updated', ['id' => 'sub_1', 'status' => 'active', 'cancel_at_period_end' => true,
            'items' => ['data' => [['current_period_end' => now()->addDays(20)->timestamp]]]]))->assertJson(['status' => 'processed']);
        $this->assertSame('canceled', $sub->fresh()->status);
        $this->assertSame(now()->addDays(20)->timestamp, $sub->fresh()->current_period_end->timestamp);
        $this->assertSame('pro', app(Entitlements::class)->plan($this->user)->code, 'access until the period end');

        $this->webhook($this->event('evt_d1', 'customer.subscription.deleted', ['id' => 'sub_1', 'status' => 'canceled']))->assertJson(['status' => 'processed']);
        $this->assertSame('expired', $sub->fresh()->status);
        app(Entitlements::class)->forget($this->user);
        $this->assertSame('free', app(Entitlements::class)->plan($this->user)->code);
    }

    public function test_stripe_failure_on_cancel_does_not_change_local_state(): void
    {
        $sub = $this->subscribe();
        $this->stripe['subscriptions_fail'] = true;
        $this->from('/billing')->post('/billing/cancel')->assertSessionHasErrors('billing');
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_switching_plans_cancels_the_old_stripe_subscription(): void
    {
        $old = $this->subscribe('pro');
        $this->stripe['verify'] = ['amount_total' => 1500, 'subscription' => 'sub_2'];
        $new = $this->subscribe('business');
        $this->assertSame('sub_2', $new->provider_reference);
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertFalse($old->fresh()->auto_renew);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_1');
        $this->assertSame('business', app(Entitlements::class)->plan($this->user)->code);
    }

    public function test_without_a_webhook_secret_stripe_keeps_one_time_payments_and_zarinpal_is_unchanged(): void
    {
        config(['billing.providers.stripe.webhook_secret' => null]);
        $pro = Plan::where('code', 'pro')->first();
        $this->actingAs($this->user)->post('/billing/checkout', ['plan' => $pro->id, 'interval' => 'monthly', 'provider' => 'stripe']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions' && $r['mode'] === 'payment' && !isset($r['line_items[0][price_data][recurring][interval]']));
        $this->assertSame('one_time', Payment::first()->meta['mode']);

        // A one-time Stripe subscription does not auto renew and is expired on time by the lifecycle job.
        $this->stripe['verify'] = ['mode' => 'payment', 'subscription' => null, 'payment_intent' => 'pi_1'];
        $this->get(URL::temporarySignedRoute('billing.callback', now()->addDay(), ['payment' => Payment::first()->id]).'&session_id=cs_sub_1');
        $sub = Subscription::first();
        $this->assertFalse($sub->auto_renew);
        $this->assertSame('cs_sub_1', $sub->provider_reference);
        $this->travelTo($sub->current_period_end->copy()->addMinute());
        $this->assertSame(1, app(BillingService::class)->processLifecycle()['expired']);
    }

    public function test_admin_can_store_the_webhook_secret_encrypted(): void
    {
        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'is_admin' => true, 'onboarded_at' => now()]);
        config(['billing.providers.stripe.webhook_secret' => null]);
        $this->actingAs($admin)->post(route('admin.payment-settings.save'), ['stripe_enabled' => 1, 'stripe_webhook_secret' => 'nope'])->assertSessionHasErrors('stripe_webhook_secret');
        $this->actingAs($admin)->post(route('admin.payment-settings.save'), ['stripe_enabled' => 1, 'stripe_webhook_secret' => 'whsec_Panel123'])->assertRedirect();
        $raw = \App\Models\AppSetting::where('key', 'pay_stripe_webhook_secret')->value('value');
        $this->assertStringNotContainsString('whsec_Panel123', (string) $raw);
        $this->assertSame('whsec_Panel123', \App\Support\PaymentSettings::get('stripe_webhook_secret'));
        $html = $this->get(route('admin.payment-settings'))->assertOk()->getContent();
        $this->assertStringNotContainsString('whsec_Panel123', $html);
        $this->assertStringContainsString('/api/billing/webhook/stripe', $html);
    }
}
