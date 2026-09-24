<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\AiInteraction;
use App\Models\Area;
use App\Models\DailyPlan;
use App\Models\Decision;
use App\Models\ExecutionLog;
use App\Models\FailureReason;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Note;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Review;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\AI\AIPlannerService;
use App\Services\Planner\AnalyticsService;
use App\Services\Planner\PlannerService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

final class TelegramPlannerBotService
{
    private const TTL = 1800;
    private const TZ = 'Asia/Tehran';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PlannerService $planner,
    ) {}

    public function start(string|int $chatId, string $username, ?string $code = null): void
    {
        if ($code !== null && $code !== '') {
            $userId = Cache::pull('telegram:link:' . strtoupper($code));
            $user = $userId ? User::find($userId) : null;
            if (!$user || !$user->is_active) {
                $this->telegram->sendMessage($chatId, 'کد اتصال نامعتبر یا منقضی شده است.');
                return;
            }
            if ($user->telegram_chat_id !== null && (string)$user->telegram_chat_id !== (string)$chatId) {
                $this->telegram->sendMessage($chatId, 'این حساب قبلاً به Telegram دیگری متصل شده است.');
                return;
            }
            $user->update([
                'telegram_chat_id'=>(string)$chatId,
                'telegram_username'=>$username !== '' ? $username : null,
                'telegram_linked_at'=>now(),
            ]);
            $this->telegram->sendMessage($chatId, 'اتصال با موفقیت انجام شد. سلام '.$user->name.' 👋', $this->mainKeyboard());
            return;
        }

        $user = User::where('telegram_chat_id',(string)$chatId)->where('is_active',true)->first();
        if (!$user) {
            $this->telegram->sendMessage($chatId, 'این Telegram هنوز به Planner متصل نیست. از پنل Planner یک کد اتصال بساز و /start CODE را ارسال کن.');
            return;
        }
        $this->syncIdentity($user,$username);
        $this->telegram->sendMessage($chatId, 'سلام '.$user->name.' 👋\nHaman Planner آماده است.', $this->mainKeyboard());
    }

    public function handleText(string|int $chatId, string $username, string $text): void
    {
        $user=$this->authorized($chatId,$username);
        if (!$user) return;
        $state=$this->state($chatId);
        if (($state['mode'] ?? null)==='input') {
            $this->consumeInput($user,$chatId,trim($text),$state);
            return;
        }
        $this->telegram->sendMessage($chatId,'از منوی دکمه‌ای انتخاب کن.',$this->mainKeyboard());
    }

    public function handleCallback(string|int $chatId,string $username,string $callbackId,int $messageId,string $data): void
    {
        $user=$this->authorized($chatId,$username);
        if (!$user) { $this->telegram->answerCallback($callbackId,'دسترسی ندارید.'); return; }
        $this->telegram->answerCallback($callbackId);
        $p=explode(':',$data);
        $a=$p[0]??'home';
        try {
            match($a) {
                'home'=>$this->home($chatId,$messageId),
                'today'=>$this->today($chatId,$messageId),
                'tomorrow'=>$this->taskList($chatId,$messageId,Carbon::tomorrow(self::TZ)->toDateString(),'📆 فردا'),
                'tasks'=>$this->entityList($chatId,$messageId,'task'),
                'taskmenu'=>$this->taskMenu($chatId,$messageId),
                'inbox'=>$this->inbox($chatId,$messageId),
                'search'=>$this->beginSearch($chatId,$messageId),
                'structure'=>$this->structureMenu($chatId,$messageId),
                'execution'=>$this->executionMenu($chatId,$messageId),
                'analysis'=>$this->analysisMenu($chatId,$messageId),
                'knowledge'=>$this->knowledgeMenu($chatId,$messageId),
                'areas'=>$this->entityList($chatId,$messageId,'area'),
                'goals'=>$this->entityList($chatId,$messageId,'goal'),
                'projects'=>$this->entityList($chatId,$messageId,'project'),
                'milestones'=>$this->entityList($chatId,$messageId,'milestone'),
                'notes'=>$this->entityList($chatId,$messageId,'note'),
                'decisions'=>$this->entityList($chatId,$messageId,'decision'),
                'reminders'=>$this->entityList($chatId,$messageId,'reminder'),
                'new'=>$this->beginCreate($chatId,$messageId,$p[1]??'task'),
                'view'=>$this->entityView($chatId,$messageId,$p[1]??'',isset($p[2])?(int)$p[2]:0),
                'edit'=>$this->editMenu($chatId,$messageId,$p[1]??'',isset($p[2])?(int)$p[2]:0),
                'field'=>$this->beginEditField($chatId,$messageId,$p[1]??'',isset($p[2])?(int)$p[2]:0,$p[3]??''),
                'delete'=>$this->deleteConfirm($chatId,$messageId,$p[1]??'',isset($p[2])?(int)$p[2]:0),
                'delok'=>$this->deleteEntity($chatId,$messageId,$p[1]??'',isset($p[2])?(int)$p[2]:0),
                'pick'=>$this->pickReference($chatId,$messageId,$p[1]??'',isset($p[2])?(int)$p[2]:0,$p[3]??'',isset($p[4])?(int)$p[4]:0),
                'enum'=>$this->handleEnumSelection($chatId,$messageId,$p[1]??'',isset($p[2])?(int)$p[2]:0,$p[3]??'', $p[4]??''),
                'done'=>$this->completeTask($chatId,$messageId,isset($p[1])?(int)$p[1]:0),
                'defer'=>$this->deferTask($chatId,$messageId,isset($p[1])?(int)$p[1]:0),
                'cancel'=>$this->cancelTask($chatId,$messageId,isset($p[1])?(int)$p[1]:0),
                'taskfield'=>$this->beginTaskField($chatId,$messageId,isset($p[1])?(int)$p[1]:0,$p[2]??''),
                'report'=>$this->reportsMenu($chatId,$messageId),
                'rday'=>$this->report($chatId,$messageId,'day'),
                'rweek'=>$this->report($chatId,$messageId,'week'),
                'rmonth'=>$this->report($chatId,$messageId,'month'),
                'daily'=>$this->daily($chatId,$messageId),
                'calendar'=>$this->calendar($chatId,$messageId),
                'executionlogs'=>$this->executionLogs($chatId,$messageId),
                'newexecution'=>$this->newExecution($chatId,$messageId),
                'dependencies'=>$this->dependencies($chatId,$messageId),
                'newdependency'=>$this->newDependency($chatId,$messageId),
                'reviews'=>$this->reviews($chatId,$messageId),
                'analytics'=>$this->analytics($chatId,$messageId),
                'failures'=>$this->failures($chatId,$messageId),
                'ai'=>$this->aiMenu($chatId,$messageId),
                'aiplanner'=>$this->beginAI($chatId,$messageId),
                'aiinteractions'=>$this->simpleSystem($chatId,$messageId,AiInteraction::class,'✧ AI Interactions'),
                'pending'=>$this->pending($chatId,$messageId),
                'activity'=>$this->activity($chatId,$messageId),
                'confirmcreate'=>$this->confirmCreate($chatId,$messageId),
                'confirmdelete'=>$this->confirmDelete($chatId,$messageId),
                'noop'=>null,
                default=>$this->home($chatId,$messageId),
            };
        } catch (\Throwable $e) {
            report($e);
            $this->telegram->editMessage($chatId,$messageId,'❌ خطا در اجرای عملیات. دوباره تلاش کن.',[[['text'=>'🏠 خانه','callback_data'=>'home']]]);
        }
    }

    private function authorized(string|int $chatId,string $username): ?User
    {
        $u=User::where('telegram_chat_id',(string)$chatId)->where('is_active',true)->first();
        if (!$u) { $this->telegram->sendMessage($chatId,'دسترسی فعال نیست. از پنل Planner کد اتصال بگیر.'); return null; }
        $this->syncIdentity($u,$username);
        return $u;
    }
    private function syncIdentity(User $u,string $username): void {
        if ((string)$u->telegram_username!==$username) $u->update(['telegram_username'=>$username!==''?$username:null]);
    }
    private function key(string|int $chatId): string { return 'telegram:planner:state:'.$chatId; }
    private function state(string|int $chatId): array { return Cache::get($this->key($chatId),[]); }
    private function putState(string|int $chatId,array $s): void { Cache::put($this->key($chatId),$s,now()->addSeconds(self::TTL)); }
    private function clearState(string|int $chatId): void { Cache::forget($this->key($chatId)); }

    private function mainKeyboard(): array {
        return [
            [['text'=>'◉ امروز','callback_data'=>'today'],['text'=>'✓ کارها','callback_data'=>'tasks']],
            [['text'=>'▣ Inbox','callback_data'=>'inbox'],['text'=>'⌕ جستجو','callback_data'=>'search']],
            [['text'=>'🏗 ساختار','callback_data'=>'structure']],
            [['text'=>'⚙ اجرا','callback_data'=>'execution']],
            [['text'=>'📊 تحلیل','callback_data'=>'analysis']],
            [['text'=>'🧠 دانش و AI','callback_data'=>'knowledge']],
        ];
    }
    private function home(string|int $c,int $m): void { $this->telegram->editMessage($c,$m,'🤖 Haman Planner\n\nیک بخش را انتخاب کن:',$this->mainKeyboard()); }
    private function back(string $to='home'): array { return [['text'=>'⬅️ بازگشت','callback_data'=>$to]]; }

    private function structureMenu(string|int $c,int $m): void {
        $this->menu($c,$m,'🏗 ساختار',[
            [['text'=>'◈ حوزه‌ها','callback_data'=>'areas'],['text'=>'◎ اهداف','callback_data'=>'goals']],
            [['text'=>'▤ پروژه‌ها','callback_data'=>'projects'],['text'=>'◇ Milestones','callback_data'=>'milestones']],
            $this->back(),
        ]);
    }
    private function executionMenu(string|int $c,int $m): void {
        $this->menu($c,$m,'⚙ اجرا',[
            [['text'=>'☀ Daily Plan','callback_data'=>'daily'],['text'=>'□ تقویم','callback_data'=>'calendar']],
            [['text'=>'◷ Execution','callback_data'=>'executionlogs'],['text'=>'⇄ Dependencies','callback_data'=>'dependencies']],
            [['text'=>'◌ یادآورها','callback_data'=>'reminders'],['text'=>'⚠ Failure Reasons','callback_data'=>'failures']],
            $this->back(),
        ]);
    }
    private function analysisMenu(string|int $c,int $m): void {
        $this->menu($c,$m,'📊 تحلیل',[
            [['text'=>'↻ Reviews','callback_data'=>'reviews'],['text'=>'◫ Analytics','callback_data'=>'analytics']],
            [['text'=>'▥ Reports','callback_data'=>'report']],
            $this->back(),
        ]);
    }
    private function knowledgeMenu(string|int $c,int $m): void {
        $this->menu($c,$m,'🧠 دانش و AI',[
            [['text'=>'▤ Notes','callback_data'=>'notes'],['text'=>'◆ Decisions','callback_data'=>'decisions']],
            [['text'=>'✦ AI Planner','callback_data'=>'aiplanner'],['text'=>'✧ AI Interactions','callback_data'=>'aiinteractions']],
            [['text'=>'⌛ Pending Actions','callback_data'=>'pending'],['text'=>'◌ Activity Logs','callback_data'=>'activity']],
            $this->back(),
        ]);
    }
    private function taskMenu(string|int $c,int $m): void {
        $this->menu($c,$m,'✓ کارها',[
            [['text'=>'➕ ایجاد کار','callback_data'=>'new:task']],
            [['text'=>'◉ امروز','callback_data'=>'today'],['text'=>'📆 فردا','callback_data'=>'tomorrow']],
            [['text'=>'▣ Inbox','callback_data'=>'inbox'],['text'=>'⌕ جستجو','callback_data'=>'search']],
            $this->back(),
        ]);
    }
    private function menu(string|int $c,int $m,string $text,array $kb): void { $this->telegram->editMessage($c,$m,$text,$kb); }

    private function entityClass(string $e): string {
        return match($e){
            'area'=>Area::class,'goal'=>Goal::class,'project'=>Project::class,'milestone'=>Milestone::class,
            'task'=>Task::class,'note'=>Note::class,'decision'=>Decision::class,'reminder'=>Reminder::class,
            default=>throw new \InvalidArgumentException('entity'),
        };
    }
    private function entityLabel(string $e): string {
        return ['area'=>'حوزه','goal'=>'هدف','project'=>'پروژه','milestone'=>'Milestone','task'=>'کار','note'=>'Note','decision'=>'Decision','reminder'=>'یادآور'][$e]??$e;
    }
    private function fields(string $e): array {
        return match($e){
            'area'=>[['name','نام'],['type','نوع'],['status','وضعیت'],['description','توضیحات']],
            'goal'=>[['title','عنوان'],['description','توضیحات'],['area_id','حوزه','area'],['status','وضعیت'],['importance','اهمیت'],['weight','وزن'],['progress','پیشرفت'],['start_date','شروع'],['target_date','موعد'],['success_criteria','معیار موفقیت']],
            'project'=>[['title','عنوان'],['description','توضیحات'],['goal_id','هدف','goal'],['status','وضعیت'],['importance','اهمیت'],['weight','وزن'],['progress','پیشرفت'],['estimated_minutes','زمان برآوردی'],['start_date','شروع'],['target_date','موعد']],
            'milestone'=>[['title','عنوان'],['project_id','پروژه','project'],['status','وضعیت'],['weight','وزن'],['progress','پیشرفت'],['target_date','موعد']],
            'task'=>[['title','عنوان'],['description','توضیحات'],['area_id','حوزه','area'],['goal_id','هدف','goal'],['project_id','پروژه','project'],['milestone_id','Milestone','milestone'],['status','وضعیت'],['priority','اولویت'],['importance','اهمیت'],['weight','وزن'],['progress','پیشرفت'],['estimated_minutes','زمان برآوردی'],['deadline','مهلت'],['planned_start','شروع برنامه'],['planned_end','پایان برنامه'],['energy_level','انرژی'],['focus_level','تمرکز'],['failure_reason','علت شکست']],
            'note'=>[['title','عنوان'],['content','متن'],['area_id','حوزه','area'],['goal_id','هدف','goal'],['project_id','پروژه','project'],['task_id','کار','task']],
            'decision'=>[['title','عنوان'],['decision','تصمیم'],['rationale','دلیل'],['area_id','حوزه','area'],['decided_at','زمان']],
            'reminder'=>[['task_id','کار','task'],['type','نوع'],['scheduled_at','زمان'],['message','پیام']],
            default=>[],
        };
    }
    private function items(string $e) {
        $c=$this->entityClass($e);
        $q=$c::query();
        if ($e==='task') $q->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")->latest('id');
        elseif ($e==='reminder') $q->where('status','pending')->orderBy('scheduled_at');
        else $q->latest('id');
        return $q->limit(30)->get();
    }
    private function titleOf(Model $x): string { return (string)($x->title??$x->name??('ID '.$x->id)); }

    private function entityList(string|int $c,int $m,string $e): void {
        $items=$this->items($e);
        $text='📋 '.$this->entityLabel($e).'ها\n\n'.($items->isEmpty()?'موردی وجود ندارد.':$items->map(fn($x)=>'• '.$this->titleOf($x).($x->progress!==null?' — '.$x->progress.'%':''));
        $kb=[[$this->button('➕ ایجاد '.$this->entityLabel($e),'new:'.$e)]];
        foreach($items as $x) $kb[]=[['text'=>'👁 '.$this->titleOf($x),'callback_data'=>'view:'.$e.':'.$x->id]];
        $kb[]=$this->back($this->parentFor($e));
        $this->telegram->editMessage($c,$m,mb_substr($text,0,4096),$kb);
    }
    private function parentFor(string $e): string {
        return in_array($e,['area','goal','project','milestone'],true)?'structure':
            (in_array($e,['reminder'],true)?'execution':
            (in_array($e,['note','decision'],true)?'knowledge':'taskmenu'));
    }
    private function button(string $t,string $d): array { return ['text'=>$t,'callback_data'=>$d]; }

    private function entityView(string|int $c,int $m,string $e,int $id): void {
        $x=$this->findEntity($e,$id);
        if(!$x){$this->menu($c,$m,'مورد پیدا نشد.',$this->back($this->parentFor($e)));return;}
        $text='📌 '.$this->entityLabel($e).'\n\n'.$this->describe($e,$x);
        $kb=[
            [['text'=>'✏️ ویرایش','callback_data'=>'edit:'.$e.':'.$id],['text'=>'🗑 حذف','callback_data'=>'delete:'.$e.':'.$id]],
        ];
        if($e==='task') $kb[]=[['text'=>'✅ انجام شد','callback_data'=>'done:'.$id],['text'=>'⏸ تعویق','callback_data'=>'defer:'.$id]];
        $kb[]=$this->back($this->parentFor($e));
        $this->telegram->editMessage($c,$m,mb_substr($text,0,4096),$kb);
    }
    private function describe(string $e,Model $x): string {
        $f=$this->fields($e); $out=[];
        foreach($f as [$n,$label]) {
            $v=$x->{$n}??null;
            if ($v instanceof Carbon) $v=$v->timezone(self::TZ)->format('Y-m-d H:i');
            if (is_array($v)) $v=json_encode($v,JSON_UNESCAPED_UNICODE);
            if ($v!==null && $v!=='') $out[]=$label.': '.$v;
        }
        return implode("\n",$out)?:'اطلاعاتی ثبت نشده.';
    }

    private function findEntity(string $e,int $id): ?Model { if($id<=0)return null; $c=$this->entityClass($e); return $c::find($id); }

    private function editMenu(string|int $c,int $m,string $e,int $id): void {
        $x=$this->findEntity($e,$id);
        if(!$x){$this->menu($c,$m,'مورد پیدا نشد.',$this->back($this->parentFor($e)));return;}
        $kb=[];
        foreach($this->fields($e) as [$n,$label]) $kb[]=[['text'=>'✏️ '.$label,'callback_data'=>'field:'.$e.':'.$id.':'.$n]];
        $kb[]=$this->back('view:'.$e.':'.$id);
        $this->menu($c,$m,'✏️ ویرایش '.$this->entityLabel($e).'\n\nیک فیلد را انتخاب کن:',$kb);
    }

    private function beginEditField(string|int $c,int $m,string $e,int $id,string $field): void {
        $x=$this->findEntity($e,$id);
        if(!$x || !collect($this->fields($e))->contains(fn($f)=>$f[0]===$field)){ $this->menu($c,$m,'فیلد نامعتبر است.',$this->back('view:'.$e.':'.$id));return; }
        $def=collect($this->fields($e))->first(fn($f)=>$f[0]===$field);
        if(isset($def[2])) {
            $this->putState($c,['mode'=>'pick_edit','entity'=>$e,'id'=>$id,'field'=>$field,'message_id'=>$m]);
            $this->referencePicker($c,$m,$def[2],false,$field);
            return;
        }
        if($field==='status'||$field==='priority'||$field==='type') {
            $this->chooseEnum($c,$m,$e,$id,$field,(string)($x->{$field}??''));
            return;
        }
        $this->putState($c,['mode'=>'edit','entity'=>$e,'id'=>$id,'field'=>$field,'message_id'=>$m]);
        $this->telegram->editMessage($c,$m,'✏️ '.$def[1].' را وارد کن:\n\nمقدار فعلی: '.(string)($x->{$field}??'—'),[[['text'=>'❌ لغو','callback_data'=>'view:'.$e.':'.$id]]]);
    }

    private function beginCreate(string|int $c,int $m,string $e): void {
        if($e==='schedule'){ $this->newSchedule($c,$m); return; }
        if(!in_array($e,array_keys($this->fieldsMap()),true)) {$this->home($c,$m);return;}
        $this->putState($c,['mode'=>'create','entity'=>$e,'step'=>0,'data'=>[],'message_id'=>$m]);
        $this->askCreateField($c,$m,$this->state($c));
    }
    private function fieldsMap(): array { return array_fill_keys(['area','goal','project','milestone','task','note','decision','reminder'],true); }
    private function newSchedule(string|int $c,int $m): void { $this->telegram->editMessage($c,$m,'➕ ایجاد Schedule Block\n\nبرای ایجاد بلوک زمانی، ابتدا Task را انتخاب کن و سپس زمان شروع و پایان را ارسال کن.',[[['text'=>'📋 انتخاب Task','callback_data'=>'tasks']],$this->back('calendar')]); }

    private function askCreateField(string|int $c,int $m,array $s): void {
        $f=$this->fields($s['entity'])[$s['step']]??null;
        if(!$f){$this->showCreateSummary($c,$m,$s);return;}
        [$n,$label]=$f;
        if(isset($f[2])) {$this->putState($c,$s);$this->referencePicker($c,$m,$f[2],true,$f[0]);return;}
        if($n==='status'||$n==='priority'||$n==='type') {
            $this->putState($c,$s);
            $this->enumCreate($c,$m,$s['entity'],$n);
            return;
        }
        $this->putState($c,$s);
        $this->telegram->editMessage($c,$m,'➕ ایجاد '.$this->entityLabel($s['entity']).'\n\n'.$label.' را وارد کن:',[[['text'=>'❌ لغو','callback_data'=>$this->parentFor($s['entity'])]]]);
    }

    private function consumeInput(User $u,string|int $c,string $text,array $s): void {
        if(($s['mode']??'')==='search') { $this->runSearch($c,(int)$s['message_id'],$text);$this->clearState($c);return; }
        $e=$s['entity']??'';$field=$s['field']??null;
        if(($s['mode']??'')==='edit' && $field) {
            $x=$this->findEntity($e,(int)$s['id']);
            if(!$x){$this->clearState($c);return;}
            $x->update([$field=>$this->castValue($field,$text)]);
            $this->clearState($c);$this->entityView($c,(int)$s['message_id'],$e,$x->id);return;
        }
        if(($s['mode']??'')==='create') {
            $fields=$this->fields($e);$f=$fields[$s['step']]??null;
            if(!$f)return;
            $s['data'][$f[0]]=$this->castValue($f[0],$text);
            $s['step']++;
            $this->putState($c,$s);
            $this->askCreateField($c,(int)$s['message_id'],$s);
        }
    }

    private function castValue(string $field,string $v): mixed {
        if($v==='-'||$v==='') return null;
        if(in_array($field,['importance','progress','weight','estimated_minutes','actual_minutes','energy_level','focus_level','sort_order'],true)) return is_numeric($v)?(float)$v:$v;
        if(str_ends_with($field,'_date')||in_array($field,['deadline','planned_start','planned_end','scheduled_at','decided_at'],true)) return Carbon::parse($v,self::TZ);
        return $v;
    }

    private function referencePicker(string|int $c,int $m,string $ref,bool $create=false,string $field=''): void {
        $class=$this->entityClass($ref);$items=$class::query()->latest('id')->limit(20)->get();
        $kb=[];
        foreach($items as $x) $kb[]=[['text'=>'• '.$this->titleOf($x),'callback_data'=>'pick:'.$ref.':'.($create?0:($this->state($c)['id']??0)).':'.$field.':'.$x->id]];
        $kb[]=[['text'=>'بدون مقدار','callback_data'=>'enum:'.$ref.':'.($create?0:($this->state($c)['id']??0)).':__ref:none']];
        $this->telegram->editMessage($c,$m,'انتخاب '.$this->entityLabel($ref).' برای ادامه:',array_merge($kb,[[['text'=>'⬅️ بازگشت','callback_data'=>$create?$this->parentFor($this->state($c)['entity']):'view:'.$this->state($c)['entity'].':'.$this->state($c)['id']]]));
    }
    private function pickReference(string|int $c,int $m,string $ref,int $id,string $field,int $refId): void {
        $s=$this->state($c);
        if(($s['mode']??'')==='create') {
            $s['data'][$s['entity']===$ref?$field:$field]=$refId;
            $s['step']++;$this->putState($c,$s);$this->askCreateField($c,$m,$s);return;
        }
        $x=$this->findEntity($s['entity']??'',(int)($s['id']??$id));
        if($x){$x->update([$field=>$refId]);$this->clearState($c);$this->entityView($c,$m,$s['entity'],$x->id);}
    }

    private function enumCreate(string|int $c,int $m,string $e,string $field): void {
        $vals=$this->enumValues($e,$field);$kb=[];foreach($vals as $v)$kb[]=[['text'=>$v,'callback_data'=>'enum:'.$e.':0:'.$field.':'.$v]];
        $kb[]=$this->back($this->parentFor($e));$this->telegram->editMessage($c,$m,'انتخاب '.$field.':',$kb);
    }
    private function chooseEnum(string|int $c,int $m,string $e,int $id,string $field,string $value): void {
        $vals=$this->enumValues($e,$field);$kb=[];foreach($vals as $v)$kb[]=[['text'=>($v===$value?'✓ ':'').$v,'callback_data'=>'enum:'.$e.':'.$id.':'.$field.':'.$v]];
        $kb[]=$this->back('view:'.$e.':'.$id);$this->telegram->editMessage($c,$m,'انتخاب '.$field.':',$kb);
    }
    private function enumValues(string $e,string $field): array {
        if($field==='priority')return ['p0','p1','p2','p3'];
        if($field==='status')return ['inbox','planned','ready','in_progress','blocked','waiting','completed','cancelled','deferred'];
        if($field==='type')return $e==='reminder'?['telegram','system','deadline']:['personal','business'];
        return [];
    }

    private function handleEnumSelection(string|int $c,int $m,string $e,int $id,string $field,string $value): void {
        $s=$this->state($c);
        if(($s['mode']??'')==='create') {
            $s['data'][$field]=$value;$s['step']++;$this->putState($c,$s);$this->askCreateField($c,$m,$s);return;
        }
        $x=$this->findEntity($e,$id);if($x){$x->update([$field=>$value]);$this->entityView($c,$m,$e,$id);}
    }

    private function showCreateSummary(string|int $c,int $m,array $s): void {
        $d=$s['data'];$text='✅ اطلاعات آماده ثبت است\n\n';
        foreach($this->fields($s['entity']) as [$n,$label]) if(isset($d[$n])&&$d[$n]!==null)$text.=$label.': '.$this->formatValue($d[$n])."\n";
        $this->putState($c,$s);
        $this->telegram->editMessage($c,$m,mb_substr($text,0,4096),[[['text'=>'✅ ثبت','callback_data'=>'confirmcreate']],[['text'=>'❌ لغو','callback_data'=>$this->parentFor($s['entity'])]]]);
    }
    private function confirmCreate(string|int $c,int $m): void {
        $s=$this->state($c);if(($s['mode']??'')!=='create')return;
        $e=$s['entity'];$d=$s['data'];
        if($e==='reminder')$d=['task_id'=>$d['task_id']??null,'type'=>$d['type']??'telegram','scheduled_at'=>$d['scheduled_at']??now(),'status'=>'pending','payload'=>['message'=>$d['message']??'یادآوری','chat_id'=>(string)$c]];
        if($e==='task')$d['status']=$d['status']??'inbox';
        $class=$this->entityClass($e);
        $x=$class::create($d);
        $this->clearState($c);
        $this->entityView($c,$m,$e,$x->id);
    }
    private function formatValue(mixed $v): string { if($v instanceof Carbon)return $v->timezone(self::TZ)->format('Y-m-d H:i'); if(is_array($v))return json_encode($v,JSON_UNESCAPED_UNICODE); return (string)$v; }

    private function deleteConfirm(string|int $c,int $m,string $e,int $id): void {
        $x=$this->findEntity($e,$id);if(!$x)return;
        $this->telegram->editMessage($c,$m,'⚠️ حذف '.$this->entityLabel($e).' «'.$this->titleOf($x).'»؟',[[['text'=>'🗑 بله، حذف شود','callback_data'=>'delok:'.$e.':'.$id]],[['text'=>'❌ خیر','callback_data'=>'view:'.$e.':'.$id]]]);
    }
    private function deleteEntity(string|int $c,int $m,string $e,int $id): void {
        $x=$this->findEntity($e,$id);if($x)$x->delete();
        $this->entityList($c,$m,$e);
    }
    private function confirmDelete(string|int $c,int $m): void { $this->home($c,$m); }

    private function completeTask(string|int $c,int $m,int $id): void { $x=Task::find($id);if($x)$this->planner->complete($x);$this->entityView($c,$m,'task',$id); }
    private function deferTask(string|int $c,int $m,int $id): void { $x=Task::find($id);if($x)$this->planner->defer($x);$this->entityView($c,$m,'task',$id); }
    private function cancelTask(string|int $c,int $m,int $id): void { $x=Task::find($id);if($x)$x->update(['status'=>'cancelled']);$this->entityView($c,$m,'task',$id); }
    private function beginTaskField(string|int $c,int $m,int $id,string $field): void { $this->beginEditField($c,$m,'task',$id,$field); }

    private function today(string|int $c,int $m): void { $this->taskList($c,$m,Carbon::now(self::TZ)->toDateString(),'◉ کارهای امروز'); }
    private function taskList(string|int $c,int $m,string $date,string $title): void {
        $items=Task::whereNotIn('status',['completed','cancelled'])->where(fn($q)=>$q->whereDate('planned_start',$date)->orWhereDate('deadline',$date))->orderBy('planned_start')->limit(30)->get();
        $text=$title."\n\n".($items->isEmpty()?'کاری پیدا نشد.':$items->map(fn($x)=>'• '.$x->title.' — '.$x->progress.'%')->implode("\n"));
        $kb=[];foreach($items as $x)$kb[]=[['text'=>'📋 '.$x->title,'callback_data'=>'view:task:'.$x->id]];$kb[]=$this->back('tasks');$this->telegram->editMessage($c,$m,mb_substr($text,0,4096),$kb);
    }
    private function inbox(string|int $c,int $m): void {
        $items=Task::where('status','inbox')->latest('id')->limit(30)->get();
        $this->listCustom($c,$m,'▣ Inbox',$items,'task','taskmenu');
    }
    private function listCustom(string|int $c,int $m,string $title,$items,string $e,string $back): void {
        $kb=[];foreach($items as $x)$kb[]=[['text'=>$this->titleOf($x),'callback_data'=>'view:'.$e.':'.$x->id]];$kb[]=$this->back($back);
        $this->telegram->editMessage($c,$m,$title."\n\n".($items->isEmpty()?'موردی نیست.':$items->map(fn($x)=>'• '.$this->titleOf($x))->implode("\n")),$kb);
    }

    private function beginSearch(string|int $c,int $m): void {
        $this->putState($c,['mode'=>'search','message_id'=>$m]);
        $this->telegram->editMessage($c,$m,'⌕ عبارت جستجو را بنویس:',$this->back('home')?[$this->back('home')]:[]);
    }
    private function runSearch(string|int $c,int $m,string $q): void {
        $tasks=Task::where('title','ilike','%'.$q.'%')->orWhere('description','ilike','%'.$q.'%')->limit(30)->get();
        $this->listCustom($c,$m,'⌕ نتایج جستجو',$tasks,'task','home');
    }

    private function reportsMenu(string|int $c,int $m): void { $this->menu($c,$m,'▥ Reports',[[['text'=>'☀ روزانه','callback_data'=>'rday'],['text'=>'📆 هفتگی','callback_data'=>'rweek']],[['text'=>'🗓 ماهانه','callback_data'=>'rmonth']],$this->back()]); }
    private function report(string|int $c,int $m,string $period): void {
        $to=Carbon::now(self::TZ);$from=match($period){'day'=>$to->copy()->startOfDay(),'week'=>$to->copy()->startOfWeek(),'month'=>$to->copy()->startOfMonth(),default=>$to->copy()->startOfDay()};
        $x=app(AnalyticsService::class)->summary($from,$to);
        $text='▥ Report '.strtoupper($period)."\n\n".'ایجاد: '.($x['tasks_created']??0)."\nتکمیل: ".($x['tasks_completed']??0)."\nنرخ تکمیل: ".($x['completion_rate']??0)."\nزمان واقعی: ".($x['actual_minutes']??0)." دقیقه\nزمان اجرا: ".($x['execution_minutes']??0)." دقیقه\nعقب‌افتاده: ".($x['overdue_open_tasks']??0);
        $this->telegram->editMessage($c,$m,$text,[[['text'=>'روزانه','callback_data'=>'rday'],['text'=>'هفتگی','callback_data'=>'rweek'],['text'=>'ماهانه','callback_data'=>'rmonth']],$this->back('analysis')]);
    }
    private function analytics(string|int $c,int $m): void {
        $to=Carbon::now(self::TZ);$x=app(AnalyticsService::class)->summary($to->copy()->subDays(30),$to);
        $text='◫ Analytics (30 روز)\n\n'.collect($x)->map(fn($v,$k)=>is_scalar($v)?$k.': '.$v:null)->filter()->implode("\n");
        $this->telegram->editMessage($c,$m,mb_substr($text,0,4096),[$this->back('analysis')]);
    }
    private function daily(string|int $c,int $m): void {
        $x=DailyPlan::latest('plan_date')->first();$text='☀ Daily Plan\n\n'.($x?'تاریخ: '.$x->plan_date->format('Y-m-d')."\nدر دسترس: {$x->available_minutes}\nبرنامه: {$x->planned_minutes}\nتکمیل: {$x->completed_minutes}\nتمرکز: {$x->focus_level}\nانرژی: {$x->energy_level}\n".$x->notes:'برنامه‌ای ثبت نشده.');
        $this->telegram->editMessage($c,$m,$text,[$this->back('execution')]);
    }
    private function calendar(string|int $c,int $m): void {
        $from=Carbon::now(self::TZ)->startOfDay();$to=$from->copy()->addDays(7);$xs=ScheduleBlock::whereBetween('starts_at',[$from,$to])->with('task')->orderBy('starts_at')->limit(50)->get();
        $text='□ تقویم ۷ روز آینده\n\n'.($xs->isEmpty()?'بلوک زمانی نداریم.':$xs->map(fn($x)=>$x->starts_at->timezone(self::TZ)->format('m/d H:i').' — '.($x->task?->title??'Focus'))->implode("\n"));
        $this->telegram->editMessage($c,$m,$text,[[$this->button('➕ بلوک زمانی','new:schedule')],$this->back('execution')]);
    }
    private function executionLogs(string|int $c,int $m): void {
        $xs=ExecutionLog::with('task')->latest('started_at')->limit(30)->get();$text='◷ Execution\n\n'.($xs->isEmpty()?'اجرایی ثبت نشده.':$xs->map(fn($x)=>$x->started_at->timezone(self::TZ)->format('m/d H:i').' — '.($x->task?->title??'Task').' — '.($x->duration_minutes).' دقیقه')->implode("\n"));
        $this->telegram->editMessage($c,$m,$text,[[['text'=>'➕ ثبت اجرا','callback_data'=>'newexecution']],$this->back('execution')]);
    }
    private function newExecution(string|int $c,int $m): void { $this->telegram->editMessage($c,$m,'برای ثبت Execution از نسخه وب استفاده کن؛ این بخش برای نمایش سریع لاگ‌ها فعال است.',[$this->back('executionlogs')]); }
    private function dependencies(string|int $c,int $m): void {
        $xs=TaskDependency::with(['task','dependsOn'])->latest('id')->limit(30)->get();$text='⇄ Dependencies\n\n'.($xs->isEmpty()?'موردی نیست.':$xs->map(fn($x)=>($x->task?->title??'Task').' ← '.($x->dependsOn?->title??'Task').' ['.$x->type.']')->implode("\n"));
        $this->telegram->editMessage($c,$m,$text,[$this->back('execution')]);
    }
    private function newDependency(string|int $c,int $m): void { $this->telegram->editMessage($c,$m,'برای ایجاد Dependency از پنل وب استفاده کن؛ نمایش و حذف از بات در این نسخه آماده است.',[$this->back('dependencies')]); }
    private function failures(string|int $c,int $m): void {
        $xs=FailureReason::orderBy('severity')->get();$text='⚠ Failure Reasons\n\n'.($xs->isEmpty()?'موردی نیست.':$xs->map(fn($x)=>$x->code.' — '.$x->name.' | severity '.$x->severity)->implode("\n"));
        $this->telegram->editMessage($c,$m,$text,[$this->back('execution')]);
    }
    private function reviews(string|int $c,int $m): void {
        $xs=Review::latest('period_end')->limit(10)->get();$text='↻ Reviews\n\n'.($xs->isEmpty()?'Review ثبت نشده.':$xs->map(fn($x)=>$x->type.' — '.$x->period_start.' تا '.$x->period_end."\n".$x->summary)->implode("\n\n"));
        $this->telegram->editMessage($c,$m,$text,[$this->back('analysis')]);
    }
    private function aiMenu(string|int $c,int $m): void { $this->menu($c,$m,'✦ AI',[[['text'=>'✦ AI Planner','callback_data'=>'aiplanner'],['text'=>'✧ AI Interactions','callback_data'=>'aiinteractions']],$this->back('knowledge')]); }
    private function beginAI(string|int $c,int $m): void { $this->putState($c,['mode'=>'ai','message_id'=>$m]);$this->telegram->editMessage($c,$m,'✦ درخواستت برای AI Planner را بنویس:',$this->back('knowledge')?[$this->back('knowledge')]:[]); }
    private function pending(string|int $c,int $m): void { $this->simpleSystem($c,$m,\App\Models\PendingAction::class,'⌛ Pending Actions'); }
    private function activity(string|int $c,int $m): void { $this->simpleSystem($c,$m,\App\Models\ActivityLog::class,'◌ Activity Logs'); }
    private function simpleSystem(string|int $c,int $m,string $class,string $title): void {
        $xs=$class::latest('id')->limit(30)->get();$text=$title."\n\n".($xs->isEmpty()?'موردی نیست.':$xs->map(fn($x)=>json_encode($x->toArray(),JSON_UNESCAPED_UNICODE))->implode("\n"));
        $this->telegram->editMessage($c,$m,mb_substr($text,0,4096),[$this->back('knowledge')]);
    }
}
