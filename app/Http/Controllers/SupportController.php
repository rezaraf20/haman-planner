<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Support\SupportNotifier;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Support tickets for the signed-in user; every query is limited to the user's own tickets. */
final class SupportController extends Controller
{
    private const RULES = ['required' => ':attribute الزامی است.', 'max' => ':attribute بیش از حد طولانی است.', 'min' => ':attribute خیلی کوتاه است.'];
    private const ATTRS = ['subject' => 'موضوع', 'body' => 'متن پیام'];

    public function __construct(private readonly SupportNotifier $notifier) {}

    private function ensureEnabled(Request $request): void
    {
        abort_unless(AppSettings::bool('support_enabled') || $request->user()?->is_admin, 404);
    }

    private function own(Request $request, int $id): SupportTicket
    {
        return SupportTicket::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->ensureEnabled($request);
        $tickets = SupportTicket::query()->where('user_id', $request->user()->id)->latest('last_reply_at')->latest('id')->paginate(20);
        return view('support.index', ['tickets' => $tickets, 'note' => (string) AppSettings::get('support_note')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureEnabled($request);
        $data = $request->validate(['subject' => 'required|string|min:3|max:200', 'body' => 'required|string|min:3|max:5000'], self::RULES, self::ATTRS);
        $open = SupportTicket::query()->where('user_id', $request->user()->id)->where('status', '!=', 'closed')->count();
        if ($open >= 10) {
            return back()->withInput()->withErrors(['subject' => 'حداکثر ۱۰ تیکت باز می‌توانید داشته باشید. ابتدا تیکت‌های قبلی را ببندید.']);
        }
        $ticket = DB::transaction(function () use ($request, $data) {
            $ticket = SupportTicket::create(['user_id' => $request->user()->id, 'subject' => trim($data['subject']), 'status' => 'open', 'last_reply_at' => now()]);
            SupportMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'is_staff' => false, 'body' => trim($data['body'])]);
            return $ticket;
        });
        $this->notifier->notifyStaff($ticket, $data['body'], true);
        return redirect()->route('support.show', $ticket)->with('status', 'تیکت ثبت شد. پاسخ پشتیبانی در همین صفحه (و در صورت اتصال، در Telegram) به شما اطلاع داده می‌شود.');
    }

    public function show(Request $request, int $ticket): View
    {
        $this->ensureEnabled($request);
        $ticket = $this->own($request, $ticket)->load('messages.user');
        return view('support.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $this->ensureEnabled($request);
        $ticket = $this->own($request, $ticket);
        $data = $request->validate(['body' => 'required|string|min:1|max:5000'], self::RULES, self::ATTRS);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'is_staff' => false, 'body' => trim($data['body'])]);
        $ticket->update(['status' => 'open', 'last_reply_at' => now()]);
        $this->notifier->notifyStaff($ticket, $data['body'], false);
        return redirect()->route('support.show', $ticket)->with('status', 'پیام شما ارسال شد.');
    }

    public function close(Request $request, int $ticket): RedirectResponse
    {
        $ticket = $this->own($request, $ticket);
        $ticket->update(['status' => 'closed']);
        return redirect()->route('support.show', $ticket)->with('status', 'تیکت بسته شد. برای بازکردن دوباره کافی است پیام جدیدی بفرستید.');
    }
}
