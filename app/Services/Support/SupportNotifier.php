<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Facades\Mail;

/** Best-effort ticket notifications, each in the recipient's own language. Failures never break the request. */
final class SupportNotifier
{
    public function __construct(private readonly TelegramService $telegram) {}

    /** A user opened a ticket or replied: tell every active admin with Telegram linked. */
    public function notifyStaff(SupportTicket $ticket, string $body, bool $isNew): void
    {
        $user = $ticket->user;
        $admins = User::query()->where('is_admin', true)->where('is_active', true)->whereNotNull('telegram_chat_id')->get();
        foreach ($admins as $admin) {
            $text = __($isNew ? 'support.notify_staff_new' : 'support.notify_staff_reply', [
                'id' => $ticket->id, 'name' => $user?->name ?? '—', 'email' => $user?->email ?? '—',
                'subject' => $ticket->subject, 'body' => mb_substr($body, 0, 1500), 'url' => route('admin.support.show', $ticket),
            ], $admin->preferredLocale());
            $this->safe(fn () => $this->telegram->sendMessage($admin->telegram_chat_id, $text));
        }
    }

    /** Staff replied: tell the ticket owner on Telegram (if linked) and by email (if they want it). */
    public function notifyUser(SupportTicket $ticket, string $body): void
    {
        $user = $ticket->user;
        if (!$user || !$user->is_active) {
            return;
        }
        $locale = $user->preferredLocale();
        $text = __('support.notify_user', [
            'id' => $ticket->id, 'subject' => $ticket->subject, 'body' => mb_substr($body, 0, 1500), 'url' => route('support.show', $ticket),
        ], $locale);
        if ($user->telegram_chat_id) {
            $this->safe(fn () => $this->telegram->sendMessage($user->telegram_chat_id, $text));
        }
        if ($user->preference('notify_support_email')) {
            $this->safe(fn () => Mail::raw($text, function ($m) use ($user, $ticket, $locale): void {
                $m->to($user->email, $user->name)->subject(__('support.notify_user_subject', ['id' => $ticket->id], $locale));
            }));
        }
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
