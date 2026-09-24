<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Planner\PlannerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class TelegramPlannerBotService
{
    private const TTL = 30 * 60;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PlannerService $planner,
    ) {}

    public function start(string|int $chatId, string $username, ?string $code = null): void
    {
        if ($code !== null && $code !== '') {
            $userId = Cache::pull('telegram:link:'.strtoupper($code));
            if (! $userId) { $this->telegram->sendMessage($chatId, 'کد اتصال نامعتبر یا منقضی شده است. از پنل یک کد جدید بساز.'); return; }
            $user = User::find($userId);
            if (! $user || ! $user->is_active) { $this->telegram->sendMessage($chatId, 'این حساب فعال نیست.'); return; }
            if ($user->telegram_chat_id !== null && (string)$user->telegram_chat_id !== (string)$chatId) {
                $this->telegram->sendMessage($chatId, 'این حساب قبلاً به یک Telegram دیگر متصل شده است. ابتدا از پنل اتصال قبلی را قطع کن.'); return;
            }
            $user->update(['telegram_chat_id'=>(string)$chatId,'telegram_username'=>$username !== '' ? $username : null,'telegram_linked_at'=>now()]);
            $this->telegram->sendMessage($chatId, 'اتصال با موفقیت انجام شد. حساب شما: '.$user->name, $this->mainKeyboard());
            return;
        }

        $user = User::where('telegram_chat_id',(string)$chatId)->first();
        if (! $user) {
            $this->telegram->sendMessage($chatId, 'برای امنیت، هنوز این Telegram به هیچ حساب Planner متصل نیست. از پنل Planner در بخش امنیت یک کد اتصال بساز و سپس /start CODE را برای من بفرست.');
            return;
        }
        $this->syncIdentity($user,$username);
        $this->telegram->sendMessage($chatId, 'سلام '.$user->name.' 👋\nHaman Planner آماده است.', $this->mainKeyboard());
    }

    public function handleText(string|int $chatId, string $username, string $text): void
    {
        $user=$this->authorized($chatId,$username);
        if(!$user) return;
        $state=$this->state($chatId);
        if(($state['mode'] ?? null)==='input') { $this->consumeInput($user,$chatId,$text,$state); return; }
        $this->telegram->sendMessage($chatId,'از منوی دکمه‌ای انتخاب کن یا /start را بزن.',$this->mainKeyboard());
    }

    public function handleCallback(string|int $chatId,string $username,string $callbackId,int $messageId,string $data): void
    {
        $user=$this->authorized($chatId,$username);
        if(!$user){ $this->telegram->answerCallback($callbackId,'دسترسی ندارید.'); return; }
        $this->telegram->answerCallback($callbackId);
        $parts=explode(':',$data); $action=$parts[0]??'home'; $id=isset($parts[1])?(int)$parts[1]:null;
        try {
            match($action) {
                'home'=>$this->home($chatId,$messageId),
                'tasks'=>$this->taskMenu($chatId,$messageId),
                'today'=>$this->taskList($chatId,$messageId,Carbon::now('Asia/Tehran')->toDateString(),'کارهای امروز'),
                'tomorrow'=>$this->taskList($chatId,$messageId,Carbon::tomorrow('Asia/Tehran')->toDateString(),'کارهای فردا'),
                'open'=>$this->taskDetail($chatId,$messageId,$id),
                'done'=>$this->complete($chatId,$messageId,$id),
                'defer'=>$this->defer($chatId,$messageId,$id),
                'cancel'=>$this->cancel($chatId,$messageId,$id),
                'edit'=>$this->editMenu($chatId,$messageId,$id),
                'ep'=>$this->beginTaskField($chatId,$messageId,$id,'progress','درصد پیشرفت را وارد کن (۰ تا ۱۰۰):'),
                'ed'=>$this->beginTaskField($chatId,$messageId,$id,'description','توضیحات جدید کار را بنویس:'),
                'ef'=>$this->beginTaskField($chatId,$messageId,$id,'failure_reason','دلیل عدم انجام/شکست را بنویس:'),
                'eb'=>$this->beginTaskField($chatId,$messageId,$id,'blocker','مانع کار را بنویس:'),
                'et'=>$this->beginTaskField($chatId,$messageId,$id,'actual_minutes','مدت اجرای واقعی را به دقیقه وارد کن:'),
                'sch'=>$this->beginTaskField($chatId,$messageId,$id,'planned_start','زمان شروع را مثل 2026-09-25 11:00 وارد کن:'),
                'report'=>$this->reportMenu($chatId,$messageId),
                'rday'=>$this->report($chatId,$messageId,'day','گزارش روزانه'),
                'rweek'=>$this->report($chatId,$messageId,'week','گزارش هفتگی'),
                'rmonth'=>$this->report($chatId,$messageId,'month','گزارش ماهانه'),
                'goals'=>$this->goals($chatId,$messageId),
                'projects'=>$this->projects($chatId,$messageId),
                'milestones'=>$this->milestones($chatId,$messageId),
                'reminders'=>$this->reminders($chatId,$messageId),
                'newtask'=>$this->beginTaskCreate($chatId,$messageId),
                'newtask_confirm'=>$this->confirmTaskCreate($chatId,$messageId),
                'back'=>$this->home($chatId,$messageId),
                default=>$this->home($chatId,$messageId),
            };
        } catch(\Throwable $e) {
            $this->telegram->editMessage($chatId,$messageId,'خطا در اجرای عملیات. دوباره تلاش کن.',[['text'=>'⬅️ بازگشت','callback_data'=>'home']]);
        }
    }

    private function authorized(string|int $chatId,string $username): ?User
    {
        $user=User::where('telegram_chat_id',(string)$chatId)->where('is_active',true)->first();
        if(!$user){ $this->telegram->sendMessage($chatId,'دسترسی فعال نیست. برای اتصال امن، از پنل Planner کد اتصال بگیر.'); return null; }
        $this->syncIdentity($user,$username);
        return $user;
    }

    private function syncIdentity(User $user,string $username): void
    {
        if((string)$user->telegram_username !== $username) $user->update(['telegram_username'=>$username !== '' ? $username : null]);
    }

    private function stateKey(string|int $chatId): string { return 'telegram:planner:state:'.(string)$chatId; }
    private function state(string|int $chatId): array { return Cache::get($this->stateKey($chatId),[]); }
    private function putState(string|int $chatId,array $state): void { Cache::put($this->stateKey($chatId),$state,now()->addSeconds(self::TTL)); }
    private function clearState(string|int $chatId): void { Cache::forget($this->stateKey($chatId)); }

    private function mainKeyboard(): array {
        return [
            [['text'=>'📅 برنامه امروز','callback_data'=>'today'],['text'=>'📋 کارها','callback_data'=>'tasks']],
            [['text'=>'🎯 اهداف','callback_data'=>'goals'],['text'=>'📁 پروژه‌ها','callback_data'=>'projects']],
            [['text'=>'🏁 مایلستون‌ها','callback_data'=>'milestones'],['text'=>'⏰ یادآوری‌ها','callback_data'=>'reminders']],
            [['text'=>'📊 گزارش‌ها','callback_data'=>'report']],
            [['text'=>'➕ ایجاد کار','callback_data'=>'newtask']],
        ];
    }
    private function home(string|int $chatId,int $messageId): void { $this->telegram->editMessage($chatId,$messageId,'🤖 Haman Planner\n\nیک بخش را انتخاب کن:',$this->mainKeyboard()); }
    private function taskMenu(string|int $chatId,int $messageId): void {
        $this->telegram->editMessage($chatId,$messageId,'📋 کارها',[
            [['text'=>'➕ ایجاد کار','callback_data'=>'newtask']],
            [['text'=>'📅 امروز','callback_data'=>'today'],['text'=>'📆 فردا','callback_data'=>'tomorrow']],
            [['text'=>'⬅️ بازگشت','callback_data'=>'home']],
        ]);
    }
    private function taskList(string|int $chatId,int $messageId,string $date,string $title): void {
        $tasks=Task::query()->whereNotIn('status',['completed','cancelled'])
            ->where(function($q)use($date){$q->whereDate('planned_start',$date)->orWhereDate('deadline',$date);})
            ->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")->orderBy('planned_start')->limit(30)->get();
        $text='📋 '.$title.'\n\n';
        if($tasks->isEmpty()) $text.='کاری پیدا نشد.';
        foreach($tasks as $i=>$task){$time=$task->planned_start?->timezone('Asia/Tehran')->format('H:i'); $text.=($i+1).'. '.($time?$time.' — ':'').$task->title.' | '.($task->progress)."%\n";}
        $kb=[]; foreach($tasks as $i=>$task) $kb[]=[['text'=>($i+1).' — '.$task->title,'callback_data'=>'open:'.$task->id]];
        $kb[]=[['text'=>'⬅️ بازگشت','callback_data'=>'tasks']];
        $this->telegram->editMessage($chatId,$messageId,mb_substr($text,0,4096),$kb);
    }
    private function taskDetail(string|int $chatId,int $messageId,?int $id): void {
        $task=Task::find($id); if(!$task){$this->telegram->editMessage($chatId,$messageId,'کار پیدا نشد.',[['text'=>'⬅️ بازگشت','callback_data'=>'tasks']]);return;}
        $text="📋 {$task->title}\n\nوضعیت: {$task->status}\nپیشرفت: {$task->progress}%\nاولویت: {$task->priority}\nاهمیت: {$task->importance}\nبرآورد: {$task->estimated_minutes} دقیقه\nواقعی: {$task->actual_minutes} دقیقه";
        if($task->planned_start)$text.='\nشروع: '.$task->planned_start->timezone('Asia/Tehran')->format('Y-m-d H:i');
        if($task->deadline)$text.='\nمهلت: '.$task->deadline->timezone('Asia/Tehran')->format('Y-m-d H:i');
        if($task->description)$text.='\nتوضیحات: '.$task->description;
        $kb=[
            [['text'=>'✅ انجام شد','callback_data'=>'done:'.$id],['text'=>'⏸ تعویق','callback_data'=>'defer:'.$id]],
            [['text'=>'✏️ ویرایش','callback_data'=>'edit:'.$id],['text'=>'📈 پیشرفت','callback_data'=>'ep:'.$id]],
            [['text'=>'📝 توضیحات','callback_data'=>'ed:'.$id],['text'=>'⏱ زمان واقعی','callback_data'=>'et:'.$id]],
            [['text'=>'📅 زمان‌بندی','callback_data'=>'sch:'.$id],['text'=>'❌ لغو','callback_data'=>'cancel:'.$id]],
            [['text'=>'🚧 مانع','callback_data'=>'eb:'.$id],['text'=>'⚠️ عدم موفقیت','callback_data'=>'ef:'.$id]],
            [['text'=>'⬅️ بازگشت','callback_data'=>'tasks']],
        ];
        $this->telegram->editMessage($chatId,$messageId,mb_substr($text,0,4096),$kb);
    }
    private function complete(string|int $chatId,int $messageId,?int $id): void { $task=Task::find($id); if($task)$this->planner->complete($task); $this->taskDetail($chatId,$messageId,$id); }
    private function defer(string|int $chatId,int $messageId,?int $id): void { $task=Task::find($id); if($task)$this->planner->defer($task); $this->taskDetail($chatId,$messageId,$id); }
    private function cancel(string|int $chatId,int $messageId,?int $id): void { $task=Task::find($id); if($task){$task->update(['status'=>'cancelled']);} $this->taskDetail($chatId,$messageId,$id); }

    private function editMenu(string|int $chatId,int $messageId,?int $id): void {
        $this->telegram->editMessage($chatId,$messageId,'✏️ کدام فیلد تغییر کند؟',[
            [['text'=>'📈 پیشرفت','callback_data'=>'ep:'.$id],['text'=>'📝 توضیحات','callback_data'=>'ed:'.$id]],
            [['text'=>'📅 زمان‌بندی','callback_data'=>'sch:'.$id],['text'=>'⏱ زمان واقعی','callback_data'=>'et:'.$id]],
            [['text'=>'🚧 مانع','callback_data'=>'eb:'.$id],['text'=>'⚠️ دلیل شکست','callback_data'=>'ef:'.$id]],
            [['text'=>'⬅️ بازگشت','callback_data'=>'open:'.$id]],
        ]);
    }
    private function beginTaskField(string|int $chatId,int $messageId,?int $id,string $field,string $prompt): void {
        if(!$id){return;} $this->putState($chatId,['mode'=>'input','kind'=>'task_field','task_id'=>$id,'field'=>$field,'message_id'=>$messageId]);
        $this->telegram->editMessage($chatId,$messageId,$prompt,[['text'=>'❌ لغو','callback_data'=>'open:'.$id]]);
    }
    private function beginTaskCreate(string|int $chatId,int $messageId): void {
        $this->putState($chatId,['mode'=>'input','kind'=>'create_task','step'=>'title','message_id'=>$messageId,'data'=>[]]);
        $this->telegram->editMessage($chatId,$messageId,'➕ ایجاد کار\n\nعنوان کار را بنویس:',[['text'=>'❌ لغو','callback_data'=>'tasks']]);
    }

    private function confirmTaskCreate(string|int $chatId,int $messageId): void {
        $state=$this->state($chatId);
        if(($state['mode']??null)!=='input' || ($state['kind']??null)!=='create_task' || ($state['step']??null)!=='confirm') {
            $this->telegram->editMessage($chatId,$messageId,'درخواست ایجاد کار منقضی شده است.',[['text'=>'⬅️ بازگشت','callback_data'=>'home']]);
            return;
        }
        $data=$state['data']??[];
        $task=$this->planner->createTask([
            'title'=>$data['title'],
            'description'=>$data['description']??null,
            'importance'=>$data['importance']??50,
            'estimated_minutes'=>$data['estimated_minutes']??30,
            'planned_start'=>$data['planned_start']??null,
            'deadline'=>$data['deadline']??null,
            'status'=>'inbox',
        ]);
        $this->clearState($chatId);
        $this->telegram->editMessage($chatId,$messageId,'✅ کار ایجاد شد:\n'.$task->title,[
            [['text'=>'📋 مشاهده کار','callback_data'=>'open:'.$task->id]],
            [['text'=>'📋 کارها','callback_data'=>'tasks'],['text'=>'🏠 خانه','callback_data'=>'home']],
        ]);
    }

    private function consumeInput(User $user,string|int $chatId,string $text,array $state): void {
        $kind=$state['kind']??'';
        if($kind==='task_field'){
            $task=Task::find((int)$state['task_id']); if(!$task){$this->clearState($chatId);$this->telegram->sendMessage($chatId,'کار پیدا نشد.',$this->mainKeyboard());return;}
            $field=$state['field'];
            if(in_array($field,['progress','actual_minutes'],true)&&!is_numeric($text)){ $this->telegram->sendMessage($chatId,'یک عدد معتبر وارد کن.'); return; }
            if($field==='progress') $task->update(['progress'=>max(0,min(100,(float)$text))]);
            elseif($field==='actual_minutes') $task->update(['actual_minutes'=>max(0,(int)$text)]);
            elseif($field==='planned_start') $task->update(['planned_start'=>Carbon::parse($text,'Asia/Tehran')]);
            elseif($field==='blocker') $this->planner->logBlocker($task,['blocker'=>$text]);
            elseif($field==='failure_reason') $this->planner->logFailure($task,['reason'=>$text]);
            else $task->update([$field=>$text]);
            $this->clearState($chatId); $this->taskDetail($chatId,(int)$state['message_id'],$task->id); return;
        }
        if($kind==='create_task'){
            $data=$state['data']??[]; $step=$state['step']??'title';
            if($step==='title'){$data['title']=$text;$state['step']='description';$state['data']=$data;$this->putState($chatId,$state);$this->telegram->sendMessage($chatId,'توضیحات را بنویس؛ اگر ندارد «-» بزن.');return;}
            if($step==='description'){$data['description']=$text==='-'?null:$text;$state['step']='importance';$state['data']=$data;$this->putState($chatId,$state);$this->telegram->sendMessage($chatId,'اهمیت را از ۰ تا ۱۰۰ وارد کن (مثلاً 80):');return;}
            if($step==='importance'){$data['importance']=max(0,min(100,(int)$text));$state['step']='estimated_minutes';$state['data']=$data;$this->putState($chatId,$state);$this->telegram->sendMessage($chatId,'زمان تخمینی به دقیقه (مثلاً 60):');return;}
            if($step==='estimated_minutes'){$data['estimated_minutes']=max(0,(int)$text);$state['step']='planned_start';$state['data']=$data;$this->putState($chatId,$state);$this->telegram->sendMessage($chatId,'زمان شروع را مثل 2026-09-25 11:00 وارد کن؛ برای بدون زمان «-»:');return;}
            if($step==='planned_start'){$data['planned_start']=$text==='-'?null:Carbon::parse($text,'Asia/Tehran');$state['step']='deadline';$state['data']=$data;$this->putState($chatId,$state);$this->telegram->sendMessage($chatId,'مهلت را مثل 2026-09-25 18:00 وارد کن؛ برای بدون مهلت «-»:');return;}
            if($step==='deadline'){$data['deadline']=$text==='-'?null:Carbon::parse($text,'Asia/Tehran');$state['step']='confirm';$state['data']=$data;$this->putState($chatId,$state);$this->telegram->sendMessage($chatId,'خلاصه:\n'.$data['title'].'\nاهمیت: '.$data['importance'].'\nبرآورد: '.$data['estimated_minutes'].' دقیقه\n'.($data['planned_start']?'شروع: '.$data['planned_start']->format('Y-m-d H:i'):'بدون زمان').'\n'.($data['deadline']?'مهلت: '.$data['deadline']->format('Y-m-d H:i'):'بدون مهلت'),[['text'=>'✅ ایجاد','callback_data'=>'newtask_confirm'],['text'=>'❌ لغو','callback_data'=>'tasks']]);return;}
        }
    }

    private function reportMenu(string|int $chatId,int $messageId): void { $this->telegram->editMessage($chatId,$messageId,'📊 گزارش‌ها',[[['text'=>'☀️ روزانه','callback_data'=>'rday'],['text'=>'📆 هفتگی','callback_data'=>'rweek']],[['text'=>'🗓 ماهانه','callback_data'=>'rmonth']],[['text'=>'⬅️ بازگشت','callback_data'=>'home']]]); }
    private function report(string|int $chatId,int $messageId,string $period,string $title): void {
        $to=Carbon::now('Asia/Tehran'); $from=match($period){'day'=>$to->copy()->startOfDay(),'week'=>$to->copy()->startOfWeek(),'month'=>$to->copy()->startOfMonth(),default=>$to->copy()->startOfMonth()};
        $m=app(\App\Services\Planner\AnalyticsService::class)->summary($from,$to);
        $text=$title."\n\nکار ایجادشده: {$m['tasks_created']}\nتکمیل‌شده: {$m['tasks_completed']}\nنرخ تکمیل: {$m['completion_rate']}%\nزمان تخمینی: {$m['estimated_minutes']} دقیقه\nزمان واقعی: {$m['actual_minutes']} دقیقه\nزمان اجرا: {$m['execution_minutes']} دقیقه\nکارهای عقب‌افتاده: {$m['overdue_open_tasks']}\nکارهای زمان‌بندی‌شده: {$m['scheduled_tasks']}\nمانع‌ها: {$m['blockers']}\nشکست‌ها: {$m['tasks_with_failures']}\nپیشرفت میانگین اهداف: {$m['average_goal_progress']}%";
        $this->telegram->editMessage($chatId,$messageId,mb_substr($text,0,4096),[[['text'=>'☀️ روزانه','callback_data'=>'rday'],['text'=>'📆 هفتگی','callback_data'=>'rweek']],[['text'=>'🗓 ماهانه','callback_data'=>'rmonth']],[['text'=>'⬅️ بازگشت','callback_data'=>'report']]]); 
    }
    private function goals(string|int $chatId,int $messageId): void {
        $items=Goal::whereNotIn('status',['completed','cancelled'])->orderByDesc('importance')->limit(20)->get();
        $text='🎯 اهداف فعال\n\n'.($items->isEmpty()?'موردی نیست.':$items->map(fn($x)=>"• {$x->title} — {$x->progress}%")->implode("\n"));
        $this->telegram->editMessage($chatId,$messageId,$text,[[['text'=>'⬅️ بازگشت','callback_data'=>'home']]]); 
    }
    private function projects(string|int $chatId,int $messageId): void {
        $items=Project::whereNotIn('status',['completed','cancelled'])->latest('id')->limit(20)->get();
        $text='📁 پروژه‌های فعال\n\n'.($items->isEmpty()?'موردی نیست.':$items->map(fn($x)=>"• {$x->title} — {$x->progress}%")->implode("\n"));
        $this->telegram->editMessage($chatId,$messageId,$text,[[['text'=>'⬅️ بازگشت','callback_data'=>'home']]]); 
    }
    private function milestones(string|int $chatId,int $messageId): void {
        $items=Milestone::whereNotIn('status',['completed','cancelled'])->latest('id')->limit(20)->get();
        $text='🏁 مایلستون‌های فعال\n\n'.($items->isEmpty()?'موردی نیست.':$items->map(fn($x)=>"• {$x->title} — {$x->progress}%")->implode("\n"));
        $this->telegram->editMessage($chatId,$messageId,$text,[[['text'=>'⬅️ بازگشت','callback_data'=>'home']]]); 
    }
    private function reminders(string|int $chatId,int $messageId): void {
        $items=Reminder::where('status','pending')->orderBy('scheduled_at')->limit(20)->get();
        $text='⏰ یادآوری‌های فعال\n\n'.($items->isEmpty()?'موردی نیست.':$items->map(fn($x)=>'• '.$x->scheduled_at->timezone('Asia/Tehran')->format('Y-m-d H:i').' — '.($x->payload['message']??'یادآوری'))->implode("\n"));
        $this->telegram->editMessage($chatId,$messageId,$text,[[['text'=>'⬅️ بازگشت','callback_data'=>'home']]]); 
    }
}