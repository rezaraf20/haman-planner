<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Billing\Gateways\ZibalGateway;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ZibalGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    /** mutable fake Zibal state */
    private array $z = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'billing.providers.zibal' => ['enabled' => true, 'merchant' => 'MERCHANT-ZB', 'sandbox' => false, 'currency' => 'IRT'],
            'billing.providers.zarinpal' => ['enabled' => true, 'merchant_id' => 'MERCHANT-TEST', 'sandbox' => true, 'currency' => 'IRT'],
            'billing.providers.stripe' => ['enabled' => true, 'secret' => 'sk_test_x', 'currency' => 'USD'],
        ]);
        $this->seed(PlanSeeder::class);
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'fa']);
        $this->z = ['trackId' => 3722304104, 'verify' => null, 'inquiry' => null, 'http' => 200];
        Http::fake(function (Request $r) {
            if ($this->z['http'] !== 200) {
                return Http::response([], $this->z['http']);
            }
            return match ($r->url()) {
                ZibalGateway::BASE.'/v1/request' => Http::response(['trackId' => ++$this->z['trackId'], 'result' => 100, 'message' => 'success']),
                ZibalGateway::BASE.'/v1/verify' => Http::response($this->z['verify'] ?? $this->paidBody()),
                ZibalGateway::BASE.'/v1/inquiry' => Http::response($this->z['inquiry'] ?? ['result' => 100, 'status' => 1] + $this->paidBody()),
                default => Http::response([], 404),
            };
        });
    }

    private function paidBody(?int $rial = null): array
    {
        $p = Payment::latest('id')->first();
        return ['result' => 100, 'message' => 'success', 'status' => 1, 'amount' => $rial ?? ZibalGateway::toRial((int) $p->amount),
            'refNumber' => 123456789, 'orderId' => (string) $p->id, 'cardNumber' => '6037991234567890', 'paidAt' => '2026-09-28T16:00:00'];
    }

    private function checkout(string $plan = 'pro'): Payment
    {
        $p = Plan::where('code', $plan)->first();
        $this->actingAs($this->user)->withSession(['locale' => 'fa'])
            ->post('/billing/checkout', ['plan' => $p->id, 'interval' => 'monthly', 'provider' => 'zibal'])
            ->assertRedirect(ZibalGateway::BASE.'/start/'.$this->z['trackId']);
        return Payment::latest('id')->first();
    }

    private function back(array $query): \Illuminate\Testing\TestResponse
    {
        return $this->get('/billing/return/zibal?'.http_build_query($query));
    }

    // ---------------------------------------------------------------- currency boundary

    public function test_toman_to_rial_conversion_is_exactly_times_ten(): void
    {
        $this->assertSame(100000, ZibalGateway::toRial(10000));        // 10,000 T
        $this->assertSame(1000000, ZibalGateway::toRial(100000));      // 100,000 T
        $this->assertSame(10000000, ZibalGateway::toRial(1000000));    // 1,000,000 T
        $this->assertSame(0, ZibalGateway::toRial(0));
        $this->expectException(\InvalidArgumentException::class);
        ZibalGateway::toRial(-1);
    }

    public function test_request_sends_rials_once_and_stores_tomans(): void
    {
        foreach ([10000, 100000, 1000000] as $toman) {
            Plan::where('code', 'pro')->update(['prices' => json_encode(['IRT' => ['monthly' => $toman, 'yearly' => $toman * 10]])]);
            $payment = $this->checkout();
            $this->assertSame($toman, (int) $payment->amount, 'payment and invoice stay in tomans');
            $this->assertSame('IRT', $payment->currency);
            Http::assertSent(fn (Request $r) => $r->url() === ZibalGateway::BASE.'/v1/request' && $r['amount'] === $toman * 10
                && $r['merchant'] === 'MERCHANT-ZB' && $r['orderId'] === (string) $payment->id && str_ends_with($r['callbackUrl'], '/billing/return/zibal'));
            $this->assertSame((string) $this->z['trackId'], $payment->provider_reference);
        }
    }

    // ---------------------------------------------------------------- callback / verify

    public function test_successful_payment_is_verified_server_side_and_activated_once(): void
    {
        $payment = $this->checkout();
        $q = ['success' => 1, 'status' => 2, 'trackId' => $this->z['trackId'], 'orderId' => $payment->id];
        $this->back($q)->assertRedirect(route('billing.index'));
        // Duplicate callback, and Zibal answering "already verified" (201): nothing happens twice.
        $this->z['verify'] = ['result' => 201, 'message' => 'already verified'];
        $this->back($q)->assertRedirect(route('billing.index'));
        $this->back($q);

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('123456789', $payment->transaction_reference);
        $this->assertSame('603799******7890', $payment->meta['verification']['card']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Subscription::where('user_id', $this->user->id)->count());
        $this->assertSame((int) $payment->amount, (int) Invoice::first()->amount, 'invoice in tomans');
        $this->assertSame('pro', app(Entitlements::class)->plan($this->user)->code);
        Http::assertSent(fn (Request $r) => $r->url() === ZibalGateway::BASE.'/v1/verify' && $r['merchant'] === 'MERCHANT-ZB' && $r['trackId'] === $this->z['trackId']);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($x) => $x[0]->url() === ZibalGateway::BASE.'/v1/verify'), 'paid payments are not re-verified');
    }

    public function test_already_verified_is_accepted_only_after_inquiry_confirms(): void
    {
        $payment = $this->checkout();
        $this->z['verify'] = ['result' => 201, 'message' => 'already verified'];
        $this->z['inquiry'] = ['result' => 100, 'status' => 2, 'amount' => ZibalGateway::toRial((int) $payment->amount)];
        $this->back(['success' => 1, 'trackId' => $this->z['trackId'], 'orderId' => $payment->id]);
        $this->assertSame('failed', $payment->fresh()->status, 'inquiry status must be 1 (paid and verified)');

        $p2 = $this->checkout();
        $this->z['inquiry'] = null;
        $this->back(['success' => 1, 'trackId' => $this->z['trackId'], 'orderId' => $p2->id]);
        $this->assertSame('paid', $p2->fresh()->status);
    }

    public function test_browser_success_flag_is_never_trusted(): void
    {
        $payment = $this->checkout();
        $this->z['verify'] = ['result' => 202, 'message' => 'not paid', 'status' => -1];
        $this->back(['success' => 1, 'status' => 1, 'trackId' => $this->z['trackId'], 'orderId' => $payment->id]);
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_wrong_amount_or_order_is_rejected(): void
    {
        $p1 = $this->checkout();
        $this->z['verify'] = $this->paidBody((int) $p1->amount); // tomans instead of rials → 10x too little
        $this->back(['success' => 1, 'trackId' => $this->z['trackId'], 'orderId' => $p1->id]);
        $this->assertSame('failed', $p1->fresh()->status);
        $this->assertSame('amount_mismatch', $p1->fresh()->failure_reason);

        $p2 = $this->checkout();
        $this->z['verify'] = ['orderId' => '999999'] + $this->paidBody();
        $this->back(['success' => 1, 'trackId' => $this->z['trackId'], 'orderId' => $p2->id]);
        $this->assertSame('order_mismatch', $p2->fresh()->failure_reason);

        $p3 = $this->checkout();
        $this->z['verify'] = null;
        $this->back(['success' => 1, 'trackId' => $this->z['trackId'], 'orderId' => $p1->id]); // tampered orderId
        $this->assertSame('order_mismatch', $p3->fresh()->failure_reason);
        $this->assertSame(0, Subscription::count());
    }

    public function test_unknown_track_and_canceled_payment(): void
    {
        $this->back(['success' => 1, 'trackId' => '123'])->assertRedirect();
        $this->back(['success' => 1, 'trackId' => 'abc'])->assertRedirect();
        $this->assertSame(0, Subscription::count());

        $payment = $this->checkout();
        $this->z['verify'] = ['result' => 202, 'message' => 'failed', 'status' => 3];
        $this->back(['success' => 0, 'status' => 3, 'trackId' => $this->z['trackId'], 'orderId' => $payment->id])->assertSessionHas('status');
        $this->assertSame('canceled', $payment->fresh()->status);
    }

    public function test_temporary_zibal_outage_keeps_the_payment_pending(): void
    {
        $payment = $this->checkout();
        $this->z['http'] = 502;
        $this->back(['success' => 1, 'trackId' => $this->z['trackId'], 'orderId' => $payment->id]);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->z['http'] = 200;
        $this->back(['success' => 1, 'trackId' => $this->z['trackId'], 'orderId' => $payment->id]);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_request_failure_and_sandbox_merchant(): void
    {
        config(['billing.providers.zibal.sandbox' => true, 'billing.providers.zibal.merchant' => null]);
        $this->assertTrue(app(ZibalGateway::class)->isConfigured());
        $this->checkout();
        Http::assertSent(fn (Request $r) => $r->url() === ZibalGateway::BASE.'/v1/request' && $r['merchant'] === 'zibal');

        config(['billing.providers.zibal.sandbox' => false]);
        $this->assertFalse(app(ZibalGateway::class)->isConfigured(), 'no merchant, no sandbox → hidden');
    }

    // ---------------------------------------------------------------- billing page

    public function test_persian_users_see_toman_gateways_and_english_users_see_stripe(): void
    {
        $fa = $this->actingAs($this->user)->get('/billing')->assertOk()->getContent();
        $this->assertStringContainsString('value="zibal"', $fa);
        $this->assertStringContainsString('value="zarinpal"', $fa);
        $this->assertStringNotContainsString('value="stripe"', $fa);

        $this->user->update(['locale' => 'en']);
        $en = $this->actingAs($this->user->fresh())->get('/billing')->assertOk()->getContent();
        $this->assertStringContainsString('value="stripe"', $en);
        $this->assertStringNotContainsString('value="zibal"', $en);
    }

    public function test_checkout_redirect_to_every_gateway_is_allowed_by_the_csp(): void
    {
        // Browsers check form-action against the redirect after POST /billing/checkout.
        $csp = (string) $this->actingAs($this->user)->get('/billing')->headers->get('Content-Security-Policy');
        preg_match('/form-action ([^;]+)/', $csp, $m);
        $allowed = explode(' ', trim($m[1]));
        $this->checkout();
        $target = Payment::latest('id')->first();
        foreach ([ZibalGateway::BASE.'/start/1', 'https://payment.zarinpal.com/pg/StartPay/A1', 'https://sandbox.zarinpal.com/pg/StartPay/A1', 'https://checkout.stripe.com/c/pay/cs_1'] as $url) {
            $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
            $this->assertContains($origin, $allowed, $origin.' must be allowed in form-action');
        }
        $this->assertNotNull($target);
    }

    public function test_admin_configures_zibal_without_seeing_the_merchant(): void
    {
        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-123', 'is_active' => true, 'is_admin' => true, 'onboarded_at' => now()]);
        config(['billing.providers.zibal.merchant' => null]);
        $this->actingAs($admin)->post(route('admin.payment-settings.save'), ['zibal_enabled' => 1, 'zibal_merchant' => 'bad merchant!'])->assertSessionHasErrors('zibal_merchant');
        $this->post(route('admin.payment-settings.save'), ['zibal_enabled' => 1, 'zibal_merchant' => 'abcdef-123456'])->assertRedirect();
        $this->assertSame('abcdef-123456', \App\Support\PaymentSettings::get('zibal_merchant'));
        $this->assertStringNotContainsString('abcdef-123456', (string) \App\Models\AppSetting::where('key', 'pay_zibal_merchant')->value('value'));
        $html = $this->get(route('admin.payment-settings'))->assertOk()->getContent();
        $this->assertStringNotContainsString('abcdef-123456', $html);
        $this->assertStringContainsString('/billing/return/zibal', $html);
        $this->get('/admin/system')->assertOk()->assertSee(__('admin.health.zibal', [], 'en'));
    }
}
