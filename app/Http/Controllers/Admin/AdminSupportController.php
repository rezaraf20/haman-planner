<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Support\SupportNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AdminSupportController extends Controller
{
    public function __construct(private readonly SupportNotifier $notifier) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'open');
        $tickets = SupportTicket::query()->with('user')
            ->when(array_key_exists($status, SupportTicket::STATUSES), fn ($q) => $q->where('status', $status))
            ->latest('last_reply_at')->latest('id')->paginate(30)->withQueryString();
        $counts = SupportTicket::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        return view('admin.support-index', ['tickets' => $tickets, 'status' => $status, 'counts' => $counts]);
    }

    public function show(SupportTicket $ticket): View
    {
        return view('admin.support-show', ['ticket' => $ticket->load(['messages.user', 'user'])]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|min:1|max:5000'], ['body.required' => 'متن پاسخ الزامی است.']);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'is_staff' => true, 'body' => trim($data['body'])]);
        $ticket->update(['status' => $request->boolean('close') ? 'closed' : 'answered', 'last_reply_at' => now()]);
        $this->notifier->notifyUser($ticket->fresh('user'), $data['body']);
        return redirect()->route('admin.support.show', $ticket)->with('status', 'پاسخ ارسال شد و به کاربر اطلاع داده شد.');
    }

    public function status(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $status = (string) $request->input('status');
        abort_unless(array_key_exists($status, SupportTicket::STATUSES), 422);
        $ticket->update(['status' => $status]);
        return back()->with('status', 'وضعیت تیکت تغییر کرد.');
    }
}
