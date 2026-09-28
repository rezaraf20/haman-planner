<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Reminder;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Business views for admins: aggregated, never user planner content. */
final class AdminBusinessController extends Controller
{
    public function subscriptions(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $subs = Subscription::query()->with(['user:id,name,email', 'plan'])
            ->when(in_array($status, Subscription::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(40)->withQueryString();
        $counts = Subscription::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        return view('admin.subscriptions', ['subs' => $subs, 'status' => $status, 'counts' => $counts]);
    }

    public function plans(Request $request): View
    {
        $plans = Plan::query()->orderBy('sort_order')->get();
        $edit = $request->query('plan') === 'new' ? new Plan(['is_active' => true, 'is_public' => true]) : ($plans->firstWhere('id', (int) $request->query('plan')) ?? null);
        return view('admin.plans', ['plans' => $plans, 'edit' => $edit]);
    }

    public function savePlan(Request $request): RedirectResponse
    {
        $id = $request->integer('id') ?: null;
        $rules = [
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('plans', 'code')->ignore($id)],
            'name_fa' => ['required', 'string', 'max:60'], 'name_en' => ['required', 'string', 'max:60'],
            'desc_fa' => ['nullable', 'string', 'max:300'], 'desc_en' => ['nullable', 'string', 'max:300'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
        foreach (array_keys((array) config('billing.currencies')) as $cur) {
            foreach (Plan::INTERVALS as $iv) {
                $rules["price_{$cur}_{$iv}"] = ['nullable', 'integer', 'min:0'];
            }
        }
        foreach (array_keys((array) config('billing.metrics')) as $m) {
            $rules["limit_$m"] = ['nullable', 'integer', 'min:0'];
        }
        $d = $request->validate($rules);

        $prices = [];
        foreach (array_keys((array) config('billing.currencies')) as $cur) {
            foreach (Plan::INTERVALS as $iv) {
                $v = $d["price_{$cur}_{$iv}"] ?? null;
                if ($v !== null) $prices[$cur][$iv] = (int) $v;
            }
        }
        $limits = [];
        foreach (array_keys((array) config('billing.metrics')) as $m) {
            $limits[$m] = $d["limit_$m"] ?? null;
        }
        $features = [];
        foreach ((array) config('billing.features') as $f) {
            $features[$f] = $request->boolean("feature_$f");
        }

        DB::transaction(function () use ($id, $d, $prices, $limits, $features, $request): void {
            $plan = $id ? Plan::query()->findOrFail($id) : new Plan();
            $plan->fill([
                'code' => $d['code'],
                'name' => ['fa' => $d['name_fa'], 'en' => $d['name_en']],
                'description' => ['fa' => $d['desc_fa'] ?? '', 'en' => $d['desc_en'] ?? ''],
                'prices' => $prices, 'limits' => $limits, 'features' => $features,
                'trial_days' => (int) ($d['trial_days'] ?? 0), 'sort_order' => (int) ($d['sort_order'] ?? 0),
                'is_default' => $request->boolean('is_default'), 'is_active' => $request->boolean('is_active'), 'is_public' => $request->boolean('is_public'),
            ])->save();
            if ($plan->is_default) {
                Plan::query()->whereKeyNot($plan->id)->update(['is_default' => false]);
            }
        });
        return redirect()->route('admin.plans')->with('status', __('admin.plan_saved'));
    }

    public function payments(BillingService $billing): View
    {
        $revenue = fn ($from) => Payment::query()->where('status', 'paid')->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->selectRaw('currency, sum(amount) as total, count(*) as n')->groupBy('currency')->get();
        return view('admin.payments', [
            'payments' => Payment::query()->with(['user:id,name,email', 'plan'])->latest('id')->paginate(40),
            'revenueAll' => $revenue(null),
            'revenueMonth' => $revenue(now()->startOfMonth()),
            'gateways' => $billing->gateways(),
        ]);
    }

    public function grant(Request $request, User $user, BillingService $billing): RedirectResponse
    {
        $d = $request->validate(['plan' => ['required', 'integer'], 'months' => ['required', 'integer', 'min:1', 'max:36']]);
        $plan = Plan::query()->findOrFail($d['plan']);
        $billing->grant($user, $plan, (int) $d['months']);
        return back()->with('status', __('admin.granted', ['plan' => $plan->localizedName(), 'name' => $user->name, 'months' => $d['months']]));
    }

    public function analytics(\App\Services\Analytics\ProductAnalytics $analytics): View
    {
        return view('admin.analytics', ['m' => $analytics->all()]);
    }

    public function system(): View
    {
        $check = function (callable $fn) {
            try { return $fn(); } catch (\Throwable) { return null; }
        };
        $dbOk = (bool) $check(fn () => DB::connection()->getPdo() !== null);
        $heartbeat = $check(fn () => DB::table('app_settings')->where('key', 'scheduler_heartbeat')->value('value'));
        $heartbeatAt = $heartbeat ? \Illuminate\Support\Carbon::parse($heartbeat) : null;

        $health = [
            'database' => [$dbOk ? 'ok' : 'down', $dbOk ? __('admin.ok') : __('admin.down')],
            'queue' => $this->countRow($check(fn () => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0), 50),
            'failed_jobs' => $this->countRow($check(fn () => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count() : 0), 1),
            'scheduler' => [$heartbeatAt && $heartbeatAt->gt(now()->subMinutes(5)) ? 'ok' : 'warn', $heartbeatAt ? \App\Support\LocalDate::dateTime($heartbeatAt) : __('admin.never')],
            'overdue_reminders' => $this->countRow($check(fn () => Reminder::withoutGlobalScopes()->where('status', 'pending')->where('scheduled_at', '<', now()->subMinutes(10))->count()), 1),
            'failed_reminders' => $this->countRow($check(fn () => Reminder::withoutGlobalScopes()->where('status', 'failed')->where('updated_at', '>=', now()->subDays(7))->count()), 1),
            'telegram' => $this->configuredRow(filled(config('services.telegram.bot_token')) && filled(config('services.telegram.webhook_secret'))),
            'ai' => $this->configuredRow(filled(config('services.ai.api_key')) || config('services.ai.provider') === 'ollama'),
            'mail' => $this->configuredRow(filled(config('mail.default')) && config('mail.default') !== 'log' && (config('mail.default') !== 'smtp' || filled(config('mail.mailers.smtp.host')))),
            'storage' => $check(fn () => $this->storageRow()) ?? ['down', '—'],
            'zarinpal' => $this->configuredRow($check(fn () => app(\App\Services\Billing\Gateways\ZarinpalGateway::class)->isConfigured()) === true),
            'stripe' => $check(fn () => $this->stripeRow()) ?? ['down', '—'],
            'stripe_webhooks' => $check(fn () => $this->webhookRow()) ?? ['down', '—'],
            'google_calendar' => $check(fn () => $this->calendarRow()) ?? ['down', '—'],
            'app' => ['ok', PHP_VERSION.' / '.app()->version()],
        ];

        $failedJobs = $check(fn () => Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->latest('failed_at')->limit(10)->get(['id', 'queue', 'failed_at', 'exception'])
                ->map(fn ($j) => ['id' => $j->id, 'queue' => $j->queue, 'failed_at' => $j->failed_at, 'error' => mb_substr(strtok((string) $j->exception, "\n") ?: '', 0, 240)])
            : collect()) ?? collect();

        return view('admin.system', ['health' => $health, 'failedJobs' => $failedJobs, 'logErrors' => $this->recentErrors()]);
    }

    private function storageRow(): array
    {
        // The attachments directory is created on first upload; check the nearest existing parent.
        $dir = (string) config('filesystems.disks.attachments.root', storage_path('app/attachments'));
        while (!is_dir($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }
        $free = @disk_free_space($dir);
        $writable = is_writable($dir);
        $freeText = $free === false ? '—' : \App\Support\LocalDate::number((int) round($free / 1024 / 1024 / 1024, 0)).' GB';
        $used = Schema::hasTable('attachments') ? (int) DB::table('attachments')->sum('size') : 0;
        $state = !$writable ? 'down' : ($free !== false && $free < 1024 ** 3 ? 'warn' : 'ok');
        return [$state, __('admin.storage_value', ['free' => $freeText, 'used' => \App\Support\LocalDate::number((int) round($used / 1024 / 1024)).' MB'])];
    }

    private function stripeRow(): array
    {
        $g = app(\App\Services\Billing\Gateways\StripeGateway::class);
        if (!$g->isConfigured()) {
            return ['warn', __('admin.not_configured')];
        }
        return ['ok', $g->supportsRecurring() ? __('admin.pay_mode_recurring') : __('admin.pay_mode_one_time')];
    }

    private function webhookRow(): array
    {
        if (!Schema::hasTable('webhook_events')) {
            return ['warn', '—'];
        }
        $failed = DB::table('webhook_events')->where('status', 'failed')->where('updated_at', '>=', now()->subDays(7))->count();
        $last = DB::table('webhook_events')->where('provider', 'stripe')->max('created_at');
        $text = __('admin.webhook_value', ['failed' => \App\Support\LocalDate::number($failed), 'last' => $last ? \App\Support\LocalDate::dateTime($last) : __('admin.never')]);
        return [$failed > 0 ? 'warn' : 'ok', $text];
    }

    private function calendarRow(): array
    {
        if (!app(\App\Services\Calendar\GoogleCalendarProvider::class)->isConfigured()) {
            return ['warn', __('admin.not_configured')];
        }
        $total = Schema::hasTable('calendar_connections') ? DB::table('calendar_connections')->count() : 0;
        $errors = Schema::hasTable('calendar_connections') ? DB::table('calendar_connections')->where('status', '!=', 'active')->count() : 0;
        return [$errors > 0 ? 'warn' : 'ok', __('admin.calendar_value', ['n' => \App\Support\LocalDate::number($total), 'errors' => \App\Support\LocalDate::number($errors)])];
    }

    private function countRow(?int $n, int $warnAt): array
    {
        if ($n === null) return ['down', '—'];
        return [$n >= $warnAt ? 'warn' : 'ok', \App\Support\LocalDate::number($n)];
    }

    private function configuredRow(bool $ok): array
    {
        return [$ok ? 'ok' : 'warn', $ok ? __('admin.configured') : __('admin.not_configured')];
    }

    /** First line of the latest ERROR/CRITICAL log entries — no stack traces, bounded read. */
    /** Never echo credentials that may have ended up in a log line. */
    public static function maskSecrets(string $text): string
    {
        $text = (string) preg_replace('/(bot)\d+:[A-Za-z0-9_-]+/', '$1***', $text);
        $text = (string) preg_replace('/\b(sk|rk)_(live|test)_[A-Za-z0-9]+/', '$1_$2_***', $text);
        $text = (string) preg_replace('/\bwhsec_[A-Za-z0-9+\/=]+/', 'whsec_***', $text);
        $text = (string) preg_replace('/\bya29\.[A-Za-z0-9._-]+/', 'ya29.***', $text);
        $text = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/i', '$1***', $text);
        foreach ([config('services.telegram.bot_token'), config('services.ai.api_key'), config('billing.providers.stripe.secret'),
            config('billing.providers.zarinpal.merchant_id'), config('database.connections.pgsql.password'), config('app.key'),
            config('services.haman_planner.api_token'), config('services.telegram.webhook_secret'), ...\App\Support\PaymentSettings::secrets(), ...\App\Support\IntegrationSettings::secrets()] as $secret) {
            if (is_string($secret) && strlen($secret) >= 6) {
                $text = str_replace($secret, '***', $text);
            }
        }
        return $text;
    }

    private function recentErrors(): array
    {
        $file = storage_path('logs/laravel.log');
        if (!is_file($file) || !is_readable($file)) return [];
        $size = filesize($file);
        $fh = fopen($file, 'rb');
        if (!$fh) return [];
        fseek($fh, max(0, $size - 256 * 1024));
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $out = [];
        foreach (array_reverse(explode("\n", $chunk)) as $line) {
            if (preg_match('/^\[([^\]]+)\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $m)) {
                $msg = self::maskSecrets($m[3]);
                $out[] = ['at' => $m[1], 'level' => $m[2], 'message' => mb_substr((string) $msg, 0, 240)];
                if (count($out) >= 20) break;
            }
        }
        return $out;
    }
}
