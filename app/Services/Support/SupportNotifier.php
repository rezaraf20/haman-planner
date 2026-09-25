<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Facades\Mail;

/** Best-effort notifications for support tickets; failures never break the request. */
final class SupportNotifier
{
    public function __construct(private readonly TelegramService $telegram) {}

    /** A user opened a ticket or replied: tell every active admin with Telegram linked. */
    public function notifyStaff(SupportTicket $ticket, string $body, bool $isNew): void
    {
        $user = $ticket->user;
        $text = ($isNew ? '🎫 تیکت جدید' : '💬 پاسخ کاربر')." #{$ticket->id}\n"
            .'از: '.($user?->name ?? '—').' ('.($user?->email ?? '—').")\n"
            .'موضوع: '.$ticket->subject."\n\n"
            .mb_substr($body, 0, 1500)."\n\n"
            .route('admin.support.show', $ticket);
        $admins = User::query()->where('is_admin', true)->where('is_active', true)->whereNotNull('telegram_chat_id')->get();
        foreach ($admins as $admin) {
            $this->safe(fn () => $this->telegram->sendMessage($admin->telegram_chat_id, $text));
        }
    }

    /** Staff replied: tell the ticket owner on Telegram (if linked) and by email. */
    public function notifyUser(SupportTicket $ticket, string $body): void
    {
        $user = $ticket->user;
        if (!$user || !$user->is_active) {
            return;
        }
        $url = route('support.show', $ticket);
        $text = "📩 پاسخ پشتیبانی به تیکت #{$ticket->id}\nموضوع: {$ticket->subject}\n\n".mb_substr($body, 0, 1500)."\n\n{$url}";
        if ($user->telegram_chat_id) {
            $this->safe(fn () => $this->telegram->sendMessage($user->telegram_chat_id, $text));
        }
        $this->safe(fn () => Mail::raw($text, function ($m) use ($user, $ticket): void {
            $m->to($user->email, $user->name)->subject('پاسخ پشتیبانی Haman Planner — تیکت #'.$ticket->id);
        }));
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
