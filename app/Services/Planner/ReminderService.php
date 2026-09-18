<?php

declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Reminder;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class ReminderService
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function due(?Carbon $until = null)
    {
        Reminder::query()->where('status','processing')->where('updated_at','<',now()->subMinutes(10))->update(['status'=>'pending']);
        return Reminder::query()->where('status','pending')->where('scheduled_at','<=',$until??now())
            ->where(function($q){$q->whereNull('next_attempt_at')->orWhere('next_attempt_at','<=',now());})
            ->orderBy('scheduled_at')->get();
    }

    public function dispatchDue(?Carbon $until = null): int
    {
        $count=0; foreach($this->due($until) as $reminder){if($this->dispatch($reminder))$count++;} return $count;
    }

    public function dispatch(Reminder $reminder): bool
    {
        $payload=is_array($reminder->payload)?$reminder->payload:[];
        $chatId=$payload['chat_id']??null;
        if($chatId===null){$reminder->update(['status'=>'failed','payload'=>array_merge($payload,['error'=>'chat_id is missing'])]);return false;}
        $claimed=Reminder::query()->whereKey($reminder->id)->where('status','pending')->update(['status'=>'processing']);
        if($claimed!==1)return false;
        $text=(string)($payload['message']??$this->defaultMessage($reminder));
        try{
            $this->telegram->sendMessage($chatId,$text);
            $reminder->update(['status'=>'sent','next_attempt_at'=>null,'payload'=>array_merge((array)$reminder->fresh()->payload,['sent_at'=>now()->toIso8601String()])]);
            return true;
        }catch(\Throwable $e){
            Log::warning('Haman Planner reminder delivery failed',['reminder_id'=>$reminder->id,'error'=>$e->getMessage()]);
            $current=$reminder->fresh(); $attempts=((int)($current?->attempts??0))+1; $max=(int)($current?->max_attempts??3);
            if($attempts >= $max){$status='failed';$next=null;}else{$status='pending';$next=now()->addMinutes(2 ** min($attempts,4));}
            $current?->update(['status'=>$status,'attempts'=>$attempts,'next_attempt_at'=>$next,'payload'=>array_merge((array)($current?->payload??$payload),['error'=>'delivery_failed','last_attempt_at'=>now()->toIso8601String()])]);
            return false;
        }
    }

    private function defaultMessage(Reminder $reminder): string
    {
        $taskTitle=$reminder->task?->title; return $taskTitle ? "Reminder: {$taskTitle}" : 'Haman Planner reminder';
    }
}
