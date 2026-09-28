<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingService;
use App\Support\LandingContent;
use App\Support\Locales;
use App\Support\PaymentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin → Landing content and Admin → Payment settings. */
final class AdminContentController extends Controller
{
    // ---------------------------------------------------------------- public pages content

    public function content(Request $request): View
    {
        $lang = Locales::normalize((string) $request->query('lang')) ?? app()->getLocale();
        return view('admin.content', [
            'lang' => $lang,
            'sections' => LandingContent::sections(),
            'hideLegalNotice' => LandingContent::hideLegalNotice(),
        ]);
    }

    public function saveContent(Request $request): RedirectResponse
    {
        $data = $request->validate(['lang' => ['required', Rule::in(Locales::SUPPORTED)]]);
        $input = [];
        foreach (array_keys(LandingContent::fields()) as $field) {
            $name = str_replace('.', '__', $field);
            if ($request->has($name)) {
                $input[$name] = $this->bounded($request->input($name));
            }
        }
        LandingContent::save($data['lang'], $input, $request->boolean('hide_legal_notice'));
        return redirect()->route('admin.content', ['lang' => $data['lang']])->with('status', __('admin.content_saved'));
    }

    public function resetContent(Request $request): RedirectResponse
    {
        $data = $request->validate(['lang' => ['required', Rule::in(Locales::SUPPORTED)]]);
        LandingContent::reset($data['lang']);
        return redirect()->route('admin.content', ['lang' => $data['lang']])->with('status', __('admin.content_reset_done'));
    }

    /** Caps sizes so the settings row stays small (strings 4000 chars, at most 40 rows). */
    private function bounded(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($row) => is_array($row) ? array_map(fn ($c) => mb_substr((string) $c, 0, 4000), array_slice($row, 0, 3)) : '',
                array_slice($value, 0, 40));
        }
        return mb_substr((string) $value, 0, 4000);
    }

    // ---------------------------------------------------------------- payment providers

    public function payments(BillingService $billing): View
    {
        $fields = [];
        foreach (array_keys(PaymentSettings::FIELDS) as $f) {
            $fields[$f] = ['value' => PaymentSettings::get($f), 'masked' => PaymentSettings::masked($f), 'source' => PaymentSettings::source($f)];
        }
        return view('admin.payment-settings', [
            'fields' => $fields,
            'gateways' => $billing->gateways(),
            'callbackBase' => rtrim((string) config('app.url'), '/').'/billing/callback/…',
            'appUrlIsHttps' => str_starts_with((string) config('app.url'), 'https://'),
            'stripeWebhookUrl' => rtrim((string) config('app.url'), '/').'/api/billing/webhook/stripe',
            'stripeWebhookEvents' => ['checkout.session.completed', 'invoice.paid', 'invoice.payment_failed', 'customer.subscription.updated', 'customer.subscription.deleted'],
            'lastWebhook' => \App\Models\WebhookEvent::query()->where('provider', 'stripe')->latest('id')->first(),
        ]);
    }

    public function savePayments(Request $request): RedirectResponse
    {
        $request->validate([
            'zarinpal_merchant_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'stripe_secret' => ['nullable', 'string', 'max:255', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9+\/=]+$/'],
        ], [
            'stripe_webhook_secret.regex' => __('admin.pay_webhook_invalid'),
            'zarinpal_merchant_id.regex' => __('admin.pay_merchant_invalid'),
            'stripe_secret.regex' => __('admin.pay_stripe_invalid'),
        ]);

        PaymentSettings::put([
            'zarinpal_enabled' => $request->boolean('zarinpal_enabled'),
            'zarinpal_sandbox' => $request->boolean('zarinpal_sandbox'),
            'stripe_enabled' => $request->boolean('stripe_enabled'),
            'zarinpal_merchant_id' => $request->boolean('clear_zarinpal_merchant_id') ? null : (string) $request->input('zarinpal_merchant_id', ''),
            'stripe_secret' => $request->boolean('clear_stripe_secret') ? null : (string) $request->input('stripe_secret', ''),
            'stripe_webhook_secret' => $request->boolean('clear_stripe_webhook_secret') ? null : (string) $request->input('stripe_webhook_secret', ''),
        ]);
        return redirect()->route('admin.payment-settings')->with('status', __('admin.pay_saved'));
    }
}
