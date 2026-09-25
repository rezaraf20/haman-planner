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
        ];

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
            ->orderByDesc('users.id')
            ->paginate(30)->withQueryString();

        return view('admin.users', ['users' => $users, 'q' => $q, 'filter' => $filter]);
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
            return back()->withErrors(['user' => 'نمی‌توانید وضعیت یا نقش حساب خودتان را تغییر دهید.']);
        }
        $user->forceFill([$field => !$user->{$field}])->save();
        $label = $field === 'is_active' ? ($user->is_active ? 'فعال' : 'غیرفعال') : ($user->is_admin ? 'مدیر' : 'کاربر عادی');
        return back()->with('status', "«{$user->name}» اکنون {$label} است.");
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
            'support_note' => 'nullable|string|max:2000',
            'announcement' => 'nullable|string|max:500',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:300',
            'remove_logo' => 'nullable|boolean',
        ], [
            'logo.mimes' => 'لوگو باید PNG، JPG یا WEBP باشد.',
            'logo.max' => 'حجم لوگو حداکثر ۳۰۰ کیلوبایت است.',
            'app_name.required' => 'نام پلتفرم الزامی است.',
        ]);

        $values = [
            'app_name' => trim($data['app_name']),
            'app_tagline' => trim((string) ($data['app_tagline'] ?? '')),
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
        AppSettings::put($values);

        return redirect()->route('admin.settings')->with('status', 'تنظیمات ذخیره شد.');
    }
}
