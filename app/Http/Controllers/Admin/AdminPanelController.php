<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Platform administration (admin only). Shows account-level information and usage
 * counts; it never exposes the content of other users' planners.
 */
final class AdminPanelController extends Controller
{
    public function overview(): View
    {
        $now = Carbon::now();
        $users = User::query();
        $stats = [
            'users' => (clone $users)->count(),
            'active_accounts' => (clone $users)->where('is_active', true)->count(),
            'admins' => (clone $users)->where('is_admin', true)->count(),
            'telegram' => (clone $users)->whereNotNull('telegram_chat_id')->count(),
            'new_7' => (clone $users)->where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'new_30' => (clone $users)->where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'seen_1' => (clone $users)->where('last_seen_at', '>=', $now->copy()->subDay())->count(),
            'seen_7' => (clone $users)->where('last_seen_at', '>=', $now->copy()->subDays(7))->count(),
            'seen_30' => (clone $users)->where('last_seen_at', '>=', $now->copy()->subDays(30))->count(),
            'tasks' => Task::withoutGlobalScopes()->count(),
            'tasks_7' => Task::withoutGlobalScopes()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'open_tickets' => SupportTicket::query()->where('status', 'open')->count(),
            'paying' => \App\Models\Subscription::query()->current()->where('status', '!=', 'trialing')->whereNotNull('billing_interval')->count(),
            'trialing' => \App\Models\Subscription::query()->current()->where('status', 'trialing')->count(),
            'ai_month' => (int) \App\Models\UsageCounter::query()->where('metric', 'ai_requests')->where('period', now()->format('Y-m'))->sum('used'),
            'ai_30' => \App\Models\AiInteraction::withoutGlobalScopes()->where('created_at', '>=', $now->copy()->subDays(30))->count(),
        ];
        $revenue30 = \App\Models\Payment::query()->where('status', 'paid')->where('paid_at', '>=', $now->copy()->subDays(30))
            ->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency')->all();
        $funnel = \App\Models\ProductEvent::query()->where('created_at', '>=', $now->copy()->subDays(30))
            ->whereIn('event', \App\Services\Analytics\ProductEvents::FUNNEL)
            ->selectRaw('event, count(distinct coalesce(user_id, id)) as c')->groupBy('event')->pluck('c', 'event')->all();

        $from = $now->copy()->subDays(13)->startOfDay();
        $raw = User::query()->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd')->all();
        $signups = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $signups[$day] = (int) ($raw[$day] ?? 0);
        }

        return view('admin.overview', [
            'stats' => $stats,
            'signups' => $signups,
            'revenue30' => $revenue30,
            'funnel' => $funnel,
            'recent' => User::query()->latest('id')->limit(8)->get(),
            'tickets' => SupportTicket::query()->with('user')->where('status', 'open')->latest('last_reply_at')->limit(5)->get(),
        ]);
    }

    public function users(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', '');
        $taskCounts = Task::withoutGlobalScopes()->select('user_id', DB::raw('count(*) as c'))->groupBy('user_id');

        $users = User::query()
            ->leftJoinSub($taskCounts, 'tc', 'tc.user_id', '=', 'users.id')
            ->select('users.*', DB::raw('COALESCE(tc.c, 0) as tasks_count'))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $w->where(fn ($x) => $x->where('users.name', 'ilike', $like)->orWhere('users.email', 'ilike', $like)
                    ->orWhere('users.telegram_username', 'ilike', $like)->orWhere('users.telegram_chat_id', 'ilike', $like));
            })
            ->when($filter === 'telegram', fn ($w) => $w->whereNotNull('users.telegram_chat_id'))
            ->when($filter === 'no_telegram', fn ($w) => $w->whereNull('users.telegram_chat_id'))
            ->when($filter === 'inactive', fn ($w) => $w->where('users.is_active', false))
            ->when($filter === 'admins', fn ($w) => $w->where('users.is_admin', true))
            ->when($filter === 'paying', fn ($w) => $w->whereIn('users.id', \App\Models\Subscription::query()->current()->whereNotNull('billing_interval')->select('user_id')))
            ->orderByDesc('users.id')
            ->paginate(30)->withQueryString();

        $plansByUser = \App\Models\Subscription::query()->current()->with('plan')->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
        // Operational signals only (counts/flags) — no planner content is shown to admins.
        $ids = $users->pluck('id');
        $aiUsage = DB::table('usage_counters')->where('metric', 'ai_requests')->where('period', now()->format('Y-m'))->whereIn('user_id', $ids)->pluck('used', 'user_id');
        $calendars = \Illuminate\Support\Facades\Schema::hasTable('calendar_connections')
            ? DB::table('calendar_connections')->whereIn('user_id', $ids)->pluck('status', 'user_id') : collect();
        return view('admin.users', [
            'users' => $users, 'q' => $q, 'filter' => $filter, 'subs' => $plansByUser, 'aiUsage' => $aiUsage, 'calendars' => $calendars,
            'plans' => \App\Models\Plan::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'defaultPlan' => \App\Models\Plan::query()->where('is_default', true)->first(),
        ]);
    }

    public function exportUsers(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['id', 'name', 'email', 'telegram_username', 'telegram_chat_id', 'telegram_linked_at', 'registered_at', 'last_seen_at', 'is_active', 'is_admin']);
            User::query()->orderBy('id')->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $u) {
                    fputcsv($out, [$u->id, $u->name, $u->email, $u->telegram_username, $u->telegram_chat_id, $u->telegram_linked_at, $u->created_at, $u->last_seen_at, $u->is_active ? 1 : 0, $u->is_admin ? 1 : 0]);
                }
            });
            fclose($out);
        }, 'haman-planner-users-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function toggleUser(Request $request, User $user): RedirectResponse
    {
        $field = (string) $request->input('field');
        abort_unless(in_array($field, ['is_active', 'is_admin'], true), 422);
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => __('admin.cannot_change_self')]);
        }
        $user->forceFill([$field => !$user->{$field}])->save();
        $label = $field === 'is_active' ? __($user->is_active ? 'admin.state_active' : 'admin.state_inactive') : __($user->is_admin ? 'admin.state_admin' : 'admin.state_user');
        return back()->with('status', __('admin.role_changed', ['name' => $user->name, 'state' => $label]));
    }

    public function settings(): View
    {
        return view('admin.settings', ['s' => AppSettings::all()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_name' => 'required|string|max:60',
            'app_tagline' => 'nullable|string|max:120',
            'app_tagline_en' => 'nullable|string|max:120',
            'support_note' => 'nullable|string|max:2000',
            'announcement' => 'nullable|string|max:500',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:300',
            'remove_logo' => 'nullable|boolean',
            'font_fa' => 'nullable|in:'.implode(',', \App\Support\Fonts::FA_OPTIONS),
            'font_en' => 'nullable|in:'.implode(',', \App\Support\Fonts::EN_OPTIONS),
            'font_fa_name' => 'nullable|string|max:60',
            'font_regular' => 'nullable|file|max:1024',
            'font_bold' => 'nullable|file|max:1024',
        ], [
            'logo.mimes' => __('admin.logo_mimes'),
            'logo.max' => __('admin.logo_max'),
        ]);

        $values = [
            'app_name' => trim($data['app_name']),
            'app_tagline' => trim((string) ($data['app_tagline'] ?? '')),
            'app_tagline_en' => trim((string) ($data['app_tagline_en'] ?? '')),
            'support_note' => trim((string) ($data['support_note'] ?? '')),
            'announcement' => trim((string) ($data['announcement'] ?? '')),
            'registration_enabled' => $request->boolean('registration_enabled'),
            'support_enabled' => $request->boolean('support_enabled'),
        ];
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            // Stored in the database (not the container filesystem) so it survives rebuilds.
            $values['logo'] = 'data:'.$file->getMimeType().';base64,'.base64_encode((string) file_get_contents($file->getRealPath()));
        } elseif ($request->boolean('remove_logo')) {
            $values['logo'] = null;
        }
        // Fonts: uploaded files are checked by their signature (woff/woff2 only) and kept in the database.
        foreach (['regular' => 'font_regular', 'bold' => 'font_bold'] as $weight => $input) {
            if ($request->hasFile($input)) {
                $bytes = (string) file_get_contents($request->file($input)->getRealPath());
                $format = \App\Support\Fonts::isFontFile($bytes);
                if ($format === null) {
                    return back()->withErrors([$input => __('admin.font_invalid')])->withInput();
                }
                \App\Support\Fonts::storeCustom($weight, $bytes, $format);
            } elseif ($request->boolean('remove_'.$input)) {
                \App\Support\Fonts::storeCustom($weight, null);
            }
        }
        $values['font_en'] = $data['font_en'] ?? 'poppins';
        $values['font_fa_name'] = trim((string) ($data['font_fa_name'] ?? ''));
        $values['font_fa'] = ($data['font_fa'] ?? 'vazirmatn') === 'custom' && \App\Support\Fonts::hasCustom() ? 'custom' : 'vazirmatn';
        AppSettings::put($values);

        if (($data['font_fa'] ?? null) === 'custom' && !\App\Support\Fonts::hasCustom()) {
            return redirect()->route('admin.settings')->withErrors(['font_regular' => __('admin.font_needs_file')]);
        }
        return redirect()->route('admin.settings')->with('status', __('admin.settings_saved'));
    }
}
