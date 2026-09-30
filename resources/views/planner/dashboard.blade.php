@php
    $brand = \App\Support\AppSettings::all();
    $u = auth()->user();
    $loc = app()->getLocale();
    $billingNotice = session('plan_notice');
@endphp
<!doctype html>
<html lang="{{ $loc }}" dir="{{ \App\Support\Locales::dir() }}">
<head>
@include('partials.fonts')
@include('partials.pwa')
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $brand['app_name'] }}</title>
<link rel="stylesheet" href="{{ asset('css/haman.css') }}?v={{ @filemtime(public_path('css/haman.css')) }}">
<style>
/* App shell (layout only — components come from haman.css) */
.shell{display:grid;grid-template-columns:248px minmax(0,1fr);min-height:100vh}
.side{background:var(--surface);border-inline-end:1px solid var(--line);position:sticky;top:0;height:100vh;display:flex;flex-direction:column;padding:16px 12px 12px}
.brand{display:flex;align-items:center;gap:10px;padding:4px 8px 14px;color:var(--ink-900);font-weight:700;font-size:16px;text-decoration:none}
.brand img{max-height:30px;max-width:120px;object-fit:contain}
.brand .mark{width:28px;height:28px;border-radius:8px;background:var(--ink-900);color:#fff;display:grid;place-items:center;font-size:14px;font-weight:700;flex:none}
.side-search{display:flex;align-items:center;gap:8px;width:100%;border:1px solid var(--line);background:var(--surface-2);color:var(--text-3);border-radius:var(--r);padding:7px 10px;margin-bottom:6px;font-size:var(--fs-sm);text-align:start}
.side-search:hover{border-color:var(--line-strong);color:var(--text-2)}
.nav{flex:1;overflow-y:auto;overflow-x:hidden;margin:0 -4px;padding:0 4px}
.nav .group{font-size:var(--fs-xs);font-weight:var(--w-semibold);color:var(--text-3);margin:16px 10px 4px}
.nav button,.side-foot a,.side-foot button{display:flex;align-items:center;gap:10px;width:100%;border:0;background:transparent;color:var(--text-2);text-align:start;border-radius:var(--r);padding:7px 10px;font-size:var(--fs-base);font-weight:var(--w-medium);position:relative;text-decoration:none}
.nav button .i,.side-foot .i{color:var(--text-3)}
.nav button:hover,.side-foot a:hover,.side-foot button:hover{background:var(--surface-3);color:var(--text);text-decoration:none}
.nav button.active{background:var(--accent-soft);color:var(--accent-strong);font-weight:var(--w-semibold)}
.nav button.active .i{color:var(--accent)}
.nav button.active::before{content:"";position:absolute;inset-inline-start:-4px;top:8px;bottom:8px;width:3px;border-radius:3px;background:var(--accent)}
.nav details summary{list-style:none;cursor:pointer;font-size:var(--fs-xs);font-weight:var(--w-semibold);color:var(--text-3);margin:16px 10px 4px;display:flex;align-items:center;gap:4px}
.nav details summary::-webkit-details-marker{display:none}
.nav details summary .i{width:13px;height:13px;transition:transform var(--t-fast)}
.nav details[open] summary .i{transform:rotate(90deg)}
[dir=rtl] .nav details summary .i{transform:scaleX(-1)}[dir=rtl] .nav details[open] summary .i{transform:rotate(90deg)}
.side-foot{border-top:1px solid var(--line);padding-top:8px;margin-top:8px;display:grid;gap:1px}
.side-foot .who{display:flex;align-items:center;gap:10px;width:100%;padding:8px;border:0;background:none;border-radius:var(--r);font-size:var(--fs-xs);color:var(--text-3);min-width:0}
.side-foot .who:hover{background:var(--surface-3)}
.side-foot .who b{display:block;color:var(--text);font-size:var(--fs-sm);font-weight:var(--w-semibold);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.side-foot .who .mail{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.menu-pop.up{top:auto;bottom:calc(100% + 4px);inset-inline:0}
.side-foot form{margin:0}
.avatar{width:28px;height:28px;border-radius:50%;background:var(--surface-3);color:var(--text-2);display:grid;place-items:center;font-weight:700;font-size:12px;flex:none}
.side-foot .lang-switch{font-size:var(--fs-xs);color:var(--text-3);padding:4px 10px}.lang-switch .sep{margin-inline:6px}.lang-switch strong{color:var(--text)}
.main{min-width:0;padding:28px 32px 48px;width:100%}
/* Desktop: the page never scrolls — the sidebar and the content area scroll independently. */
@media(min-width:1025px){html,body{height:100%;overflow:hidden}.shell{height:100vh;min-height:0}.main{height:100vh;overflow-y:auto;overscroll-behavior:contain}}
.top{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.top h1{font-size:var(--fs-xl)}
.top .sub{color:var(--text-3);font-size:var(--fs-sm);margin-top:2px}
.mobilebar,.tabbar,.scrim{display:none}
@media(max-width:1024px){.shell{grid-template-columns:minmax(0,1fr)}
 .side{position:fixed;inset-block:0;inset-inline-start:0;width:280px;z-index:90;transform:translateX(-105%);transition:transform var(--t) var(--ease);box-shadow:var(--shadow-2)}
 [dir=rtl] .side{transform:translateX(105%)}
 body.nav-open .side{transform:none}
 .scrim{position:fixed;inset:0;background:rgba(15,27,45,.35);z-index:80}body.nav-open .scrim{display:block}
 .mobilebar{display:flex;align-items:center;gap:8px;position:sticky;top:0;z-index:40;background:var(--surface);border-bottom:1px solid var(--line);padding:8px 12px}
 .mobilebar .brand{padding:0;flex:1;font-size:15px}
 .main{padding:18px 16px calc(88px + env(safe-area-inset-bottom))}
 .tabbar{display:grid;grid-template-columns:repeat(5,1fr);position:fixed;inset-inline:0;bottom:0;z-index:40;background:var(--surface);border-top:1px solid var(--line);padding:4px 4px calc(4px + env(safe-area-inset-bottom))}
 .tabbar button{border:0;background:none;display:grid;justify-items:center;gap:2px;padding:6px 2px;font-size:10.5px;color:var(--text-3);border-radius:var(--r);min-height:48px}
 .tabbar button.active{color:var(--accent-strong)}
 .tabbar button .i{width:20px;height:20px}
 .top .actions .btn.hide-sm,.top .actions .btn.primary{display:none}
 .top{margin-bottom:14px}
}
/* Home */
.home-hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}
.home-hero h2{font-size:var(--fs-xl);font-weight:var(--w-bold)}
.home-hero p{color:var(--text-2);margin:4px 0 0}
.home-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(0,1fr);gap:16px;align-items:start}
.now-card{border-color:var(--ink-900);border-width:1px}
.now-card .now-title{font-size:var(--fs-lg);font-weight:var(--w-bold);margin:2px 0 4px}
.tl{display:grid;gap:2px}
.tl-item{display:grid;grid-template-columns:62px minmax(0,1fr) auto;gap:12px;align-items:center;padding:9px 10px;border-radius:var(--r-md)}
.tl-item:hover{background:var(--surface-2)}
.tl-time{font-size:var(--fs-sm);color:var(--text-3);font-variant-numeric:tabular-nums;direction:ltr;text-align:start}
.tl-bar{border-inline-start:3px solid var(--accent);padding-inline-start:10px;min-width:0}
.tl-bar.k-focus{border-color:var(--ink-700)}.tl-bar.k-break{border-color:var(--line-strong)}.tl-bar.k-buffer{border-color:var(--warning)}.tl-bar.k-meeting,.tl-bar.busy{border-color:var(--text-3);border-inline-start-style:dashed}
.tl-item.done .tl-bar{border-color:var(--success)}.tl-item.done .rowtitle{color:var(--text-3);text-decoration:line-through}
.tl-item.now{background:var(--accent-soft)}
.tl-item.conflict .tl-bar{border-color:var(--danger)}
.cap-line{display:flex;justify-content:space-between;gap:12px;font-size:var(--fs-sm);color:var(--text-3);margin-top:10px}
.week-top{display:flex;align-items:center;gap:16px}
.kv{display:grid;grid-template-columns:1fr auto;gap:6px 12px;font-size:var(--fs-sm);margin-top:12px}
.kv dt{color:var(--text-3)}.kv dd{margin:0;font-weight:var(--w-semibold);font-variant-numeric:tabular-nums}
@media(max-width:1100px){.home-grid{grid-template-columns:minmax(0,1fr)}}
</style></head>
<body>
@include('partials.icons')
<a class="skip-link" href="#content">{{ __('app.home.skip') }}</a>
<div class="mobilebar">
<button class="btn ghost icon" id="navtoggle" aria-label="{{ __('app.nav.menu') }}" aria-expanded="false" aria-controls="side"><svg class="i"><use href="#i-menu"/></svg></button>
<span class="brand"><span class="mark" aria-hidden="true">{{ mb_substr($brand['app_name'], 0, 1) }}</span>{{ $brand['app_name'] }}</span>
<button class="btn ghost icon" data-go="search" aria-label="{{ __('app.nav.search') }}"><svg class="i"><use href="#i-search"/></svg></button>
<button class="btn primary icon" onclick="openCreate()" aria-label="{{ __('common.add') }}"><svg class="i"><use href="#i-plus"/></svg></button>
</div>
<div class="scrim" id="scrim"></div>
<div class="shell"><aside class="side" id="side" aria-label="{{ __('app.nav.menu') }}">
<a class="brand" href="{{ route('planner.app') }}">@if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@else<span class="mark" aria-hidden="true">{{ mb_substr($brand['app_name'], 0, 1) }}</span>@endif<span>{{ $brand['app_name'] }}</span></a>
<button class="side-search" data-go="search"><svg class="i sm"><use href="#i-search"/></svg><span>{{ __('app.nav.search') }}…</span></button>
@php($nb = fn ($view, $icon, $key) => '<button data-view="'.$view.'"><svg class="i"><use href="#i-'.$icon.'"/></svg><span class="txt">'.e(__('app.nav.'.$key)).'</span></button>')
<nav class="nav">
<div class="group">{{ __('app.nav.group_today') }}</div>
{!! $nb('today', 'today', 'today') !!}{!! $nb('daily', 'daily', 'daily') !!}{!! $nb('calendar', 'calendar', 'calendar') !!}{!! $nb('inbox', 'inbox', 'inbox') !!}
{!! $nb('ai-planner', 'haman', 'ai_planner') !!}
<div class="group">{{ __('app.nav.group_planning') }}</div>
{!! $nb('areas', 'areas', 'areas') !!}{!! $nb('goals', 'goal', 'goals') !!}{!! $nb('projects', 'project', 'projects') !!}{!! $nb('milestones', 'flag', 'milestones') !!}{!! $nb('recurring', 'repeat', 'recurring') !!}
<div class="group">{{ __('app.nav.group_execution') }}</div>
{!! $nb('tasks', 'tasks', 'tasks') !!}{!! $nb('execution', 'timer', 'execution') !!}{!! $nb('dependencies', 'link', 'dependencies') !!}{!! $nb('reminders', 'bell', 'reminders') !!}{!! $nb('failures', 'alert', 'failures') !!}
<div class="group">{{ __('app.nav.group_analysis') }}</div>
{!! $nb('reviews', 'review', 'reviews') !!}{!! $nb('analytics', 'chart', 'analytics') !!}{!! $nb('reports', 'report', 'reports') !!}
<details id="navmore"><summary><svg class="i"><use href="#i-chev"/></svg>{{ __('app.nav.group_more') }}</summary>
{!! $nb('notes', 'note', 'notes') !!}{!! $nb('decisions', 'decision', 'decisions') !!}{!! $nb('activity', 'activity', 'activity') !!}{!! $nb('ai', 'history', 'ai_interactions') !!}{!! $nb('pending', 'hourglass', 'pending') !!}{!! $nb('search', 'search', 'search') !!}
</details>
</nav>
<div class="side-foot">
<div class="menu userbox"><button class="who" aria-haspopup="menu" aria-expanded="false" onclick="toggleMenu(this,event)"><span class="avatar" aria-hidden="true">{{ mb_substr($u->name, 0, 1) }}</span><span style="min-width:0;flex:1;text-align:start"><b>{{ $u->name }}</b><span class="ltr mail">{{ $u->email }}</span></span><svg class="i sm"><use href="#i-more"/></svg></button>
<div class="menu-pop up" role="menu">
<a role="menuitem" href="{{ route('account.settings') }}"><svg class="i sm"><use href="#i-settings"/></svg>{{ __('app.nav.settings') }}</a>
<a role="menuitem" href="{{ route('billing.index') }}"><svg class="i sm"><use href="#i-card"/></svg>{{ __('app.nav.billing') }}</a>
@if($brand['support_enabled'] || $u->is_admin)<a role="menuitem" href="{{ route('support.index') }}"><svg class="i sm"><use href="#i-support"/></svg>{{ __('app.nav.support') }}</a>@endif
@if($u->is_admin)<a role="menuitem" href="{{ route('admin.home') }}"><svg class="i sm"><use href="#i-shield"/></svg>{{ __('app.nav.admin') }}</a>@endif
<button role="menuitem" id="pwa-install" class="hide"><svg class="i sm"><use href="#i-download"/></svg>{{ __('pwa.install') }}</button>
<form method="post" action="{{ route('logout') }}">@csrf<button role="menuitem"><svg class="i sm flip"><use href="#i-logout"/></svg>{{ __('common.logout') }}</button></form>
</div></div>
@include('partials.lang-switch')
</div>
</aside>
<main class="main" id="main">
@if(filled($brand['announcement']))<div class="notice">{{ $brand['announcement'] }}</div>@endif
@if($errors->has('plan'))<div class="notice">{{ $errors->first('plan') }} <a href="{{ route('billing.index') }}">{{ __('billing.see_plans') }}</a></div>@endif
<div class="top"><div><h1 id="title">{{ __('app.nav.today') }}</h1><div class="sub" id="subtitle"></div></div><div class="actions"><button class="btn ghost icon hide-sm" onclick="loadView(view)" aria-label="{{ __('common.refresh') }}" title="{{ __('common.refresh') }}"><svg class="i"><use href="#i-refresh"/></svg></button><button class="btn primary" onclick="openCreate()"><svg class="i"><use href="#i-plus"/></svg>{{ __('common.add') }}</button></div></div>
<div id="content" tabindex="-1" aria-live="polite"></div></main></div>
<nav class="tabbar" aria-label="{{ __('app.nav.menu') }}">
<button data-view="today"><svg class="i"><use href="#i-today"/></svg>{{ __('app.nav.today') }}</button>
<button data-view="tasks"><svg class="i"><use href="#i-tasks"/></svg>{{ __('app.nav.tasks') }}</button>
<button data-view="calendar"><svg class="i"><use href="#i-calendar"/></svg>{{ __('app.nav.calendar') }}</button>
<button data-view="ai-planner"><svg class="i"><use href="#i-haman"/></svg>{{ __('app.nav.haman_short') }}</button>
<button id="tabmore"><svg class="i"><use href="#i-menu"/></svg>{{ __('app.nav.more') }}</button>
</nav>
<div class="modalback" id="modal" role="dialog" aria-modal="true" aria-labelledby="mtitle"><div class="modal"><div class="head"><h2 id="mtitle"></h2><button class="btn ghost icon" onclick="closeModal()" aria-label="{{ __('common.close') }}"><svg class="i"><use href="#i-x"/></svg></button></div><div id="mbody"></div></div></div><div id="toast" class="toast" role="status" aria-live="polite"></div>
<script>
const I18N={t:@json(__('app.js')),nav:@json(__('app.nav')),fields:@json(__('planner.fields')),entities:@json(__('planner.entities')),status:@json(__('planner.task_status')),prio:@json(__('planner.priority')),generic:@json(__('planner.generic_status')),dep:@json(__('planner.dependency_type')),failure:@json(__('planner.failure_reason')),metrics:@json(__('planner.metrics')),reviewType:@json(__('planner.review_type')),recur:{frequency:@json(__('recurrence.frequency')),weekdays:@json(__('recurrence.weekdays')),calendar:@json(__('recurrence.calendar'))},workDays:@json($u->preference('work_days')),activity:@json(__('activity')),home:@json(__('app.home')),empties:@json(__('app.empty')),row:@json(__('app.row')),views:@json(__('app.view_sub'))};
const LOCALE=@json($loc),TZ=@json($u->preferredTimezone()),INTL=LOCALE==='fa'?'fa-IR':'en-US',BILLING_URL=@json(route('billing.index')),USER_NAME=@json($u->name);
const csrf=document.querySelector('meta[name=csrf-token]').content,$=id=>document.getElementById(id);let view='today',cache={areas:[],goals:[],projects:[],milestones:[],tasks:[]};
const esc=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
const ic=(n,c)=>'<svg class="i'+(c?' '+c:'')+'" aria-hidden="true"><use href="#i-'+n+'"/></svg>';
function t(k,r){let s=I18N.t[k]??k;if(r)for(const[a,b]of Object.entries(r))s=s.split(':'+a).join(b);return s}
const num=v=>v==null||v===''?'—':(typeof v==='number'||/^-?\d+(\.\d+)?$/.test(String(v))?Number(v).toLocaleString(INTL,{maximumFractionDigits:2}):String(v));
const fmt=v=>v?new Date(v).toLocaleDateString(INTL,{timeZone:TZ}):'—',fmtDT=v=>v?new Date(v).toLocaleString(INTL,{timeZone:TZ,dateStyle:'short',timeStyle:'short'}):'—';
/* Wall-clock parts of an instant in the user's timezone. */
function tzParts(d){let o={};new Intl.DateTimeFormat('en-US',{timeZone:TZ,hourCycle:'h23',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'}).formatToParts(d).forEach(p=>o[p.type]=p.value);return o}
/* ISO instant -> value for <input type=datetime-local> in the user's timezone. */
function toInput(v){if(!v)return'';let o=tzParts(new Date(v));return o.year+'-'+o.month+'-'+o.day+'T'+o.hour+':'+o.minute}
/* <input type=datetime-local> value (user's timezone) -> ISO instant with offset. */
function fromInput(s){if(!s)return null;let[d,h]=s.split('T'),[y,mo,da]=d.split('-').map(Number),[hh,mi]=(h||'00:00').split(':').map(Number),guess=Date.UTC(y,mo-1,da,hh,mi);const off=ms=>{let o=tzParts(new Date(ms));return Date.UTC(+o.year,+o.month-1,+o.day,+o.hour,+o.minute,+o.second)-ms};let ts=guess-off(guess);ts=guess-off(ts);return new Date(ts).toISOString()}
function toast(m,html){let el=$('toast');if(html)el.innerHTML=m;else el.textContent=m;el.classList.add('show');setTimeout(()=>el.classList.remove('show'),html?6000:2600)}
async function api(path,opt={}){let method=(opt.method||'GET').toUpperCase(),h={'Accept':'application/json','X-CSRF-TOKEN':csrf,...(opt.headers||{})};if(method!=='GET'&&method!=='HEAD')h['Content-Type']='application/json';let r=await fetch('/api'+path,{credentials:'same-origin',...opt,headers:h});if(r.status===401){location='/login';throw Error(t('session_expired'))}if(!r.ok){let x={};try{x=await r.json()}catch{}let e=Error(x.message||Object.values(x.errors||{}).flat().join(' ')||t('error'));e.status=r.status;e.upgrade=x.upgrade_url;throw e}return r.status===204?null:r.json()}
function fail(e){if(e.status===402)toast(esc(e.message)+' <a href="'+esc(e.upgrade||BILLING_URL)+'">'+esc(t('see_plans'))+'</a>',true);else toast(e.message)}
async function cacheAll(){let [a,g,p,m,tk]=await Promise.all([api('/areas'),api('/goals'),api('/projects'),api('/milestones'),api('/tasks?per_page=100')]);cache.areas=a.data||a;cache.goals=g.data||g;cache.projects=p.data||p;cache.milestones=m.data||m;cache.tasks=tk.data||tk}
const label=v=>{if(v==null||v==='')return'—';let s=String(v);return I18N.status[s]||I18N.prio[s]||I18N.generic[s]||I18N.failure[s]||I18N.t['val_'+s]||s};
function pill(v){if(v==null||v==='')return'';let s=String(v).toLowerCase(),c=['completed','on_track','on track','done','paid','success','applied'].includes(s)?'ok':['blocked','behind','cancelled','failed','error','overdue','p0'].includes(s)?'danger':['waiting','at_risk','at risk','deferred','pending','p1'].includes(s)?'warn':['in_progress','active','running'].includes(s)?'info':'';return '<span class="pill '+c+'">'+esc(label(v))+'</span>'}
/* Deadline text with meaning: overdue (red), due within 24h (amber). */
function dueMeta(d,done){if(!d)return'';let ms=new Date(d)-Date.now(),cls=done?'':ms<0?'late':ms<864e5?'soon':'';return '<span class="'+cls+'">'+ic('clock','sm')+esc(ms<0&&!done?t('overdue_since',{date:fmt(d)}):t('due_on',{date:fmt(d)}))+'</span>'}
const dur=m=>{m=+m||0;let h=Math.floor(m/60),r=m%60;return h&&r?t('dur_hm',{h:num(h),m:num(r)}):h?t('dur_h',{h:num(h)}):t('dur_m',{m:num(r)})};
function rowMenu(items){return '<div class="menu"><button class="btn ghost small icon more" aria-haspopup="menu" aria-expanded="false" aria-label="'+esc(I18N.row.more)+'" onclick="toggleMenu(this,event)">'+ic('more')+'</button><div class="menu-pop" role="menu">'+items.filter(Boolean).join('')+'</div></div>'}
const mi=(icon,text,on,cls)=>'<button role="menuitem" class="'+(cls||'')+'" onclick="'+on+'">'+ic(icon,'sm')+esc(text)+'</button>';
function toggleMenu(b,e){e.stopPropagation();let m=b.parentElement,open=!m.classList.contains('open');document.querySelectorAll('.menu.open').forEach(x=>{x.classList.remove('open');x.querySelector('button').setAttribute('aria-expanded','false')});if(open){m.classList.add('open');b.setAttribute('aria-expanded','true');let f=m.querySelector('[role=menuitem]');f&&f.focus()}}
document.addEventListener('click',()=>document.querySelectorAll('.menu.open').forEach(x=>x.classList.remove('open')));
async function toggleDone(id,done,btn){let row=btn.closest('.task');row.classList.toggle('done',!done);if(!done)row.classList.add('just-done');try{await api('/tasks/'+id,{method:'PUT',body:JSON.stringify({status:done?'planned':'completed'})});btn.setAttribute('aria-pressed',String(!done));btn.setAttribute('onclick','toggleDone('+id+','+(!done)+',this)');toast(done?t('task_reopened'):t('task_done'))}catch(e){row.classList.toggle('done',done);fail(e)}}
function itemRow(x,kind){
 let title=x.title||x.name||x.intent||t('untitled'),done=x.status==='completed',menu=[];
 menu.push(mi('edit',t('edit'),"editItem("+x.id+",'"+kind+"')"));
 if(kind==='task'||kind==='project')menu.push(mi('clip',t('att_title'),"attachmentsModal('"+kind+"',"+x.id+")"));
 if(x.recurring_task_id&&!['completed','cancelled'].includes(x.status))menu.push(mi('skip',t('skip_occurrence'),'skipOccurrence('+x.id+')'));
 menu.push(mi('trash',t('delete'),'deleteItem('+x.id+",'"+kind+"')",'danger'));
 if(kind==='task'){
  let parent=x.project?'<span class="lvl project">'+esc(x.project.title)+'</span>':x.goal?'<span class="lvl goal">'+esc(x.goal.title)+'</span>':'';
  let meta=[parent,x.milestone?'<span class="lvl milestone">'+esc(x.milestone.title)+'</span>':'',dueMeta(x.deadline,done),x.planned_start?'<span>'+ic('calendar','sm')+esc(fmtDT(x.planned_start))+'</span>':'',x.estimated_minutes?'<span>'+ic('timer','sm')+esc(x.actual_minutes?t('est_vs_actual',{est:dur(x.estimated_minutes),act:dur(x.actual_minutes)}):dur(x.estimated_minutes))+'</span>':'',x.recurring_task_id?'<span>'+ic('repeat','sm')+esc(I18N.row.recurring)+'</span>':'',x.failure_reason?'<span class=late>'+ic('alert','sm')+esc(label(x.failure_reason))+'</span>':''].filter(Boolean).join('');
  let st=!done&&x.status&&!['inbox','planned','ready'].includes(x.status)?pill(x.status):'';
  return '<div class="row task'+(done?' done':'')+'"><button class="check" aria-pressed="'+done+'" aria-label="'+esc(done?I18N.row.reopen:I18N.row.complete)+'" onclick="toggleDone('+x.id+','+done+',this)"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></button><div class=rowmain><div class=rowtitle>'+(x.priority&&['p0','p1'].includes(x.priority)?'<span class="prio '+x.priority+'" title="'+esc(label(x.priority))+'">'+esc(String(label(x.priority)).split('—').pop().trim())+'</span> ':'')+esc(title)+'</div>'+(meta?'<div class=meta>'+meta+'</div>':'')+'</div><div class=rowactions>'+st+rowMenu(menu)+'</div></div>';
 }
 let lvl={goal:'goal',project:'project',milestone:'milestone'}[kind],sub=[x.goal&&kind==='project'?'<span class="lvl goal">'+esc(x.goal.title)+'</span>':'',x.deadline?dueMeta(x.deadline,done):x.target_date?'<span>'+ic('flag','sm')+esc(t('target_on',{date:fmt(x.target_date)}))+'</span>':x.scheduled_at?'<span>'+ic('bell','sm')+esc(fmtDT(x.scheduled_at))+'</span>':x.created_at?'<span>'+esc(fmtDT(x.created_at))+'</span>':''].filter(Boolean).join('');
 return '<div class=row><div class=rowmain><div class=rowtitle>'+(lvl?'<span class="lvl '+lvl+'" aria-hidden=true></span> ':'')+esc(title)+'</div>'+(sub?'<div class=meta>'+sub+'</div>':'')+(x.progress!=null&&kind!=='task'?'<div class=progress aria-label="'+esc(I18N.fields.progress)+'"><i class="'+(x.progress>=100?'ok':'')+'" style="width:'+Math.min(100,Math.max(0,x.progress))+'%"></i></div>':'')+'</div><div class=rowactions>'+(x.progress!=null&&kind!=='task'?'<span class="muted small num">'+esc(num(Math.round(x.progress)))+'%</span>':'')+pill(x.status||x.health||x.priority)+rowMenu(menu)+'</div></div>';
}
const F=(n,type,req)=>[n,I18N.fields[n]||n,type,req?1:0];
const schema={
area:{ep:'areas',f:[F('name','text',1),F('type','text'),F('status','text'),F('sort_order','number'),F('description','textarea')]},
goal:{ep:'goals',f:[F('title','text',1),F('area_id','area'),F('status','text'),F('importance','number'),F('weight','number'),F('progress','number'),F('start_date','date'),F('target_date','date'),F('success_criteria','textarea'),F('description','textarea')]},
project:{ep:'projects',f:[F('title','text',1),F('goal_id','goal'),F('status','text'),F('importance','number'),F('weight','number'),F('progress','number'),F('estimated_minutes','number'),F('start_date','date'),F('target_date','date'),F('description','textarea')]},
milestone:{ep:'milestones',f:[F('title','text',1),F('project_id','project',1),F('status','text'),F('weight','number'),F('progress','number'),F('target_date','date')]},
task:{ep:'tasks',f:[F('title','text',1),F('description','textarea'),F('area_id','area'),F('goal_id','goal'),F('project_id','project'),F('milestone_id','milestone'),F('status','status'),F('priority','priority'),F('importance','number'),F('weight','number'),F('progress','number'),F('estimated_minutes','number'),F('deadline','datetime-local'),F('planned_start','datetime-local'),F('planned_end','datetime-local'),F('energy_level','number'),F('focus_level','number'),F('failure_reason','failure')]},
note:{ep:'notes',f:[F('title','text',1),F('content','textarea',1),F('area_id','area'),F('goal_id','goal'),F('project_id','project'),F('task_id','task')]},
decision:{ep:'decisions',f:[F('title','text',1),F('decision','textarea',1),F('rationale','textarea'),F('area_id','area'),F('decided_at','datetime-local',1)]},
schedule:{ep:'schedule-blocks',f:[F('task_id','task'),F('starts_at','datetime-local',1),F('ends_at','datetime-local',1),F('status','text'),F('source','text')]},
reminder:{ep:'reminders',f:[F('task_id','task'),F('type','text',1),F('scheduled_at','datetime-local',1),F('chat_id','text'),F('message','textarea')]}
};
const entityName=k=>I18N.entities[k]||k;
function options(type){let a={area:cache.areas,goal:cache.goals,project:cache.projects,milestone:cache.milestones,task:cache.tasks}[type]||[];return '<select name="__F"><option value="">—</option>'+a.map(x=>'<option value="'+x.id+'">'+esc(x.title||x.name)+'</option>').join('')+'</select>'}
const selectOf=(n,map)=>'<select name="'+n+'"><option value="">—</option>'+Object.entries(map).map(([k,v])=>'<option value="'+esc(k)+'">'+esc(v)+'</option>').join('')+'</select>';
function field(f){let[n,l,ty,req]=f;if(['area','goal','project','milestone','task'].includes(ty))return '<div class="field"><label>'+esc(l)+'</label>'+options(ty).replace('__F',n)+'</div>';if(ty==='status')return '<div class="field"><label>'+esc(l)+'</label>'+selectOf(n,I18N.status)+'</div>';if(ty==='priority')return '<div class="field"><label>'+esc(l)+'</label>'+selectOf(n,I18N.prio)+'</div>';if(ty==='failure')return '<div class="field"><label>'+esc(l)+'</label>'+selectOf(n,I18N.failure)+'</div>';return '<div class="field '+(ty==='textarea'?'full':'')+'"><label>'+esc(l)+'</label>'+(ty==='textarea'?'<textarea name="'+n+'" '+(req?'required':'')+'></textarea>':'<input name="'+n+'" type="'+ty+'" '+(req?'required':'')+'>')+'</div>'}
/* Collect a form, empty -> null, datetime-local -> ISO instant in the user's timezone. */
function collect(form){let d={};for(const el of form.elements){if(!el.name)continue;let v=el.value;d[el.name]=v===''?null:(el.type==='datetime-local'?fromInput(v):v)}return d}
const ADVANCED={task:['area_id','goal_id','milestone_id','status','importance','weight','progress','planned_end','energy_level','focus_level','failure_reason'],goal:['importance','weight','progress','start_date','success_criteria'],project:['importance','weight','progress','estimated_minutes','start_date'],milestone:['weight','progress'],schedule:['status','source']};
function formHtml(s){let adv=ADVANCED[Object.keys(schema).find(k=>schema[k]===s)]||[],main=s.f.filter(f=>!adv.includes(f[0])),more=s.f.filter(f=>adv.includes(f[0]));return '<form id=f><div class=formgrid>'+main.map(field).join('')+'</div>'+(more.length?'<details class=more-fields><summary>'+esc(t('more_options'))+'</summary><div class=formgrid>'+more.map(field).join('')+'</div></details>':'')+'<div class=modalactions><button type=button class=btn onclick=closeModal()>'+esc(t('cancel'))+'</button><button class="btn primary">'+esc(t('save'))+'</button></div></form>'}
async function openCreate(kind){if(!kind&&view==='recurring'&&window.recurringForm)return recurringForm();if(!kind&&view==='calendar'&&window.calendarAddBlock)return calendarAddBlock();await cacheAll();if(!kind)kind={tasks:'task',inbox:'task',today:'task',goals:'goal',projects:'project',areas:'area',milestones:'milestone',notes:'note',decisions:'decision',calendar:'schedule',reminders:'reminder'}[view]||'task';let s=schema[kind];if(!s)return;modal(t('new_item',{item:entityName(kind)}),formHtml(s));$('f').onsubmit=async e=>{e.preventDefault();try{await api('/'+s.ep,{method:'POST',body:JSON.stringify(collect(e.target))});closeModal();toast(t('saved'));loadView(view)}catch(x){fail(x)}}}
async function editItem(id,kind){let s=schema[kind];if(!s)return;await cacheAll();let x=await api('/'+s.ep+'/'+id);modal(t('edit_item',{item:entityName(kind)}),formHtml(s));s.f.forEach(([n])=>{let e=document.querySelector('#f [name="'+n+'"]');if(e&&x[n]!=null)e.value=(e.type==='datetime-local'?toInput(x[n]):e.type==='date'?String(x[n]).slice(0,10):x[n])});$('f').onsubmit=async e=>{e.preventDefault();try{await api('/'+s.ep+'/'+id,{method:'PUT',body:JSON.stringify(collect(e.target))});closeModal();toast(t('updated'));loadView(view)}catch(z){fail(z)}}}
async function skipOccurrence(id){if(!confirm(t('skip_confirm')))return;try{await api('/tasks/'+id+'/skip',{method:'POST'});toast(t('saved'));loadView(view)}catch(e){fail(e)}}
async function deleteItem(id,kind){let s=schema[kind];if(!s||!confirm(t('confirm_delete')))return;try{await api('/'+s.ep+'/'+id,{method:'DELETE'});toast(t('deleted'));loadView(view)}catch(e){fail(e)}}
let lastFocus=null;function modal(ti,b){lastFocus=document.activeElement;$('mtitle').textContent=ti;$('mbody').innerHTML=b;$('modal').classList.add('show');setTimeout(()=>{let f=$('mbody').querySelector('input:not([type=hidden]),select,textarea,button');f&&f.focus()},30)}function closeModal(){$('modal').classList.remove('show');$('mbody').innerHTML='';lastFocus&&lastFocus.focus&&lastFocus.focus()}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){if($('modal').classList.contains('show'))closeModal();document.body.classList.remove('nav-open')}});
$('modal').onclick=e=>{if(e.target.id==='modal')closeModal()};
/* Empty states explain the area and the next step. */
function empty(kind){let e=I18N.empties[kind||view];if(!e)return '<div class=empty><div class=empty-text>'+esc(t('empty'))+'</div></div>';let one={tasks:'task',inbox:'task',goals:'goal',projects:'project',areas:'area',milestones:'milestone',notes:'note',decisions:'decision',reminders:'reminder'}[kind||view];return '<div class=empty><div class=empty-mark>'+ic(e.icon||'grid')+'</div><div class=empty-title>'+esc(e.title)+'</div><p class=empty-text>'+esc(e.text)+'</p>'+(e.cta&&one?'<button class="btn primary" onclick="openCreate(\''+one+'\')">'+ic('plus')+esc(e.cta)+'</button>':'')+'</div>'}
const skeleton=n=>'<div class=skeleton>'+'<i></i>'.repeat(n||4)+'</div>';
async function loadList(kind,ep=kind){let r=await api('/'+ep+(kind==='tasks'?'?per_page=100':'')),a=r.data||r;if(!Array.isArray(a))a=[];let one=kind.slice(0,-1);if(kind==='tasks')a.sort((x,y)=>(x.status==='completed')-(y.status==='completed'));$('content').innerHTML=a.length?'<div class=toolbar><input id=q class="search input" type=search aria-label="'+esc(t('filter_placeholder'))+'" placeholder="'+esc(t('filter_placeholder'))+'" oninput=filterList()><span class="muted small">'+esc(t('count_items',{n:num(a.length)}))+'</span></div><div class=list id=rows>'+a.map(x=>itemRow(x,one)).join('')+'</div>':'<div class=card>'+empty(kind)+'</div>'}
function filterList(){let q=$('q').value.toLowerCase();document.querySelectorAll('#rows .row').forEach(x=>x.style.display=x.textContent.toLowerCase().includes(q)?'':'none')}
function metricCards(m){return Object.entries(m).filter(([k,v])=>typeof v!=='object').map(([k,v])=>'<div class=card><div class=muted>'+esc(I18N.metrics[k]||k.replaceAll('_',' '))+'</div><div class=metric>'+esc(num(v))+'</div></div>').join('')}
async function today(){let d=await api('/system/dashboard?days=1'),m=d.metrics,tk=d.recent_tasks||[];$('content').innerHTML='<div class=cards>'+[[I18N.metrics.tasks_created,num(m.tasks_created)],[I18N.metrics.tasks_completed,num(m.tasks_completed)],[I18N.metrics.completion_rate,num(m.completion_rate)],[I18N.metrics.execution_minutes,num(m.execution_minutes)]].map(x=>'<div class=card><div class=muted>'+esc(x[0])+'</div><div class=metric>'+esc(x[1])+'</div></div>').join('')+'</div><div class=grid2><div class=card><div class=head><h2>'+esc(t('recent_tasks'))+'</h2><button class="btn small" onclick="openCreate(\'task\')">＋ '+esc(entityName('task'))+'</button></div><div class=list>'+(tk.map(x=>itemRow(x,'task')).join('')||empty('recent'))+'</div></div><div class=card><div class=head><h2>'+esc(I18N.nav.pending)+'</h2></div><div class=list>'+((d.pending_actions||[]).map(x=>'<div class=row><div><b>'+esc(x.intent)+'</b><div class=rowsub>'+fmtDT(x.created_at)+'</div></div>'+pill(x.status)+'</div>').join('')||empty())+'</div></div></div>'}
async function inbox(){let r=await api('/tasks?status=inbox&per_page=100'),a=(r.data||r||[]).filter(x=>x.status==='inbox');$('content').innerHTML='<div class=card><div class=head><h2>'+esc(I18N.nav.inbox)+'</h2><button class="btn primary" onclick="openCreate(\'task\')">＋ '+esc(entityName('task'))+'</button></div><div class=list>'+(a.map(x=>itemRow(x,'task')).join('')||empty())+'</div></div>'}
function mapRows(obj,fn){return Object.entries(obj||{}).map(([k,v])=>fn(k,v)).join('')||'<p class="muted small" style="margin:0">'+esc(t('no_data_yet'))+'</p>'}
/* Analytics tells a story: 4 headline numbers, then the supporting detail — not a wall of equal cards. */
async function analytics(){let d=await api('/system/dashboard?days=30'),m=d.metrics,H=['tasks_completed','completion_rate','execution_minutes','overdue_open_tasks'];
let head=H.map(k=>{let v=m[k],cls=k==='overdue_open_tasks'&&v>0?' style="color:var(--danger)"':'';return '<div class=card><div class="muted small">'+esc(I18N.metrics[k]||k)+'</div><div class=metric'+cls+'>'+esc(num(v))+'</div></div>'}).join('');
let rest=Object.entries(m).filter(([k,v])=>typeof v!=='object'&&!H.includes(k));
$('content').innerHTML='<p class="muted small" style="margin:-6px 0 12px">'+esc(t('last_n_days',{n:num(d.history_days||30)}))+'</p>'+(d.history_limited?'<div class="notice">'+esc(t('history_limited',{n:num(d.history_days)}))+' <a href="'+esc(d.upgrade_url||BILLING_URL)+'">'+esc(t('upgrade'))+'</a></div>':'')+'<div class=cards>'+head+'</div>'
+'<div class=grid2><div class=card><div class=head><h2>'+esc(t('details'))+'</h2></div><dl class=kvgrid>'+rest.map(([k,v])=>'<div><dt>'+esc(I18N.metrics[k]||k.replaceAll('_',' '))+'</dt><dd>'+esc(num(v))+'</dd></div>').join('')+'</dl></div>'
+'<div style="display:grid;gap:16px;align-content:start"><div class=card><div class=head><h2>'+esc(I18N.metrics.goal_health)+'</h2></div><div class=list>'+mapRows(m.goal_health,(k,v)=>'<div class=row><span>'+esc(label(k))+'</span><b class=num>'+esc(num(v))+'</b></div>')+'</div></div><div class=card><div class=head><h2>'+esc(I18N.metrics.failure_reasons)+'</h2></div><div class=list>'+mapRows(m.failure_reasons,(k,v)=>'<div class=row><span>'+esc(label(k))+'</span><b class=num>'+esc(num(v))+'</b></div>')+'</div></div></div></div>'}
async function reports(p='week'){let d=await api('/system/report?period='+p),m=d.metrics,tk=d.tasks||[];$('content').innerHTML='<div class=card><div class=head><h2>'+esc(t('report_'+p))+'</h2><div class=actions><button class="btn small" onclick="reports(\'day\')">'+esc(t('period_day'))+'</button><button class="btn small" onclick="reports(\'week\')">'+esc(t('period_week'))+'</button><button class="btn small" onclick="reports(\'month\')">'+esc(t('period_month'))+'</button></div></div><div class=statgrid>'+Object.entries(m).filter(([k,v])=>typeof v!=='object').map(([k,v])=>'<div><div class=muted>'+esc(I18N.metrics[k]||k.replaceAll('_',' '))+'</div><strong>'+esc(num(v))+'</strong></div>').join('')+'</div><div class=section><div class=tablewrap><table class=table><tr><th>'+esc(entityName('task'))+'</th><th>'+esc(I18N.fields.status)+'</th><th>'+esc(I18N.fields.priority)+'</th><th>'+esc(I18N.fields.progress)+'</th><th>'+esc(t('estimate_vs_actual'))+'</th></tr>'+tk.map(x=>'<tr><td>'+esc(x.title)+'</td><td>'+pill(x.status)+'</td><td>'+pill(x.priority)+'</td><td>'+esc(num(x.progress))+'%</td><td>'+esc(num(x.estimated_minutes))+' / '+esc(num(x.actual_minutes))+'</td></tr>').join('')+'</table></div></div></div>'}
async function searchView(){$('content').innerHTML='<div class=card><div class=head><h2>'+esc(t('search_title'))+'</h2></div><div class=toolbar><input id=globalq class=search placeholder="'+esc(t('search_placeholder'))+'" onkeydown="if(event.key===\'Enter\')runSearch()"><select id=globalstatus><option value="">'+esc(t('all_statuses'))+'</option>'+Object.entries(I18N.status).map(([k,v])=>'<option value="'+k+'">'+esc(v)+'</option>').join('')+'</select><button class="btn primary" onclick="runSearch()">'+esc(t('search'))+'</button></div><div id=searchresults class=list></div></div>'}
async function runSearch(){let q=($('globalq').value||'').trim();if(q.length<2){toast(t('search_min'));return}let st=$('globalstatus').value;try{let r=await api('/search?q='+encodeURIComponent(q)+(st?'&status='+encodeURIComponent(st):''));let a=r.tasks||[];$('searchresults').innerHTML=a.map(x=>itemRow(x,'task')).join('')||'<div class=empty>'+esc(t('no_results'))+'</div>'}catch(e){fail(e)}}
async function systemList(endpoint,title,render){let r=await api('/system/'+endpoint),a=r.data||r;if(!Array.isArray(a))a=[];$('content').innerHTML='<div class=card><div class=head><h2>'+esc(title)+'</h2></div><div class=list>'+(a.map(render).join('')||empty())+'</div></div>'}
const brief=o=>Object.entries(o||{}).filter(([k,v])=>v!==null&&typeof v!=='object'&&String(v)!=='').slice(0,4).map(([k,v])=>esc(k)+': '+esc(String(v).slice(0,60))).join(' · ');
const renderAI=x=>'<div class=row><div class=rowmain><div class=rowtitle>'+esc(x.intent||'—')+' · '+esc(x.provider||'')+(x.model?' / '+esc(x.model):'')+'</div><div class=rowsub>'+fmtDT(x.created_at)+(x.input_payload&&x.input_payload.focus?' · '+esc(String(x.input_payload.focus).slice(0,120)):'')+(x.output_payload&&typeof x.output_payload.summary==='string'?'<br>'+esc(x.output_payload.summary.slice(0,200)):'')+'</div></div>'+pill(x.status)+'</div>';
const renderPending=x=>'<div class=row><div class=rowmain><div class=rowtitle>#'+x.id+' '+esc(x.intent)+'</div><div class=rowsub>'+fmtDT(x.created_at)+(x.expires_at?' · '+esc(t('expires'))+' '+fmtDT(x.expires_at):'')+'<br>'+brief((x.payload&&x.payload.arguments)||x.payload)+'</div></div>'+pill(x.status)+'</div>';
const renderActivity=x=>'<div class=row><div class=rowmain><div class=rowtitle>'+esc(label(x.action))+' · '+esc(String(x.entity_type||'').split('\\').pop())+(x.entity_id!=null?' #'+esc(x.entity_id):'')+'</div><div class=rowsub>'+fmtDT(x.created_at)+'</div></div></div>';
async function daily(){let r=await api('/system/daily-plans'),a=r.data||[];$('content').innerHTML='<div class=card><div class=head><h2>'+esc(I18N.nav.daily)+'</h2><button class="btn primary" onclick="dailyForm()">＋ '+esc(t('plan_today'))+'</button></div><div class=list>'+(a.map(x=>{let d=String(x.plan_date||x.date||'').slice(0,10);return '<div class=row><div><b>'+esc(fmt(d+'T12:00:00Z'))+'</b><div class=rowsub>'+esc(t('daily_line',{available:num(x.available_minutes||0),planned:num(x.planned_minutes||0),completed:num(x.completed_minutes||0)}))+'</div></div><button class="btn small" onclick="dailyForm(\''+d+'\')">'+esc(t('edit'))+'</button></div>'}).join('')||empty())+'</div></div>'}
async function dailyForm(date){let x=date?await api('/daily-plans/'+date):{};let fld=(n,ty,v)=>'<div class="field'+(ty==='textarea'?' full':'')+'"><label>'+esc(I18N.fields[n]||n)+'</label>'+(ty==='textarea'?'<textarea name='+n+'>'+esc(v??'')+'</textarea>':'<input name='+n+' type='+ty+' value="'+esc(v??'')+'">')+'</div>';modal(I18N.nav.daily,'<form id=f><div class=formgrid>'+(date?'':fld('plan_date','date',tzParts(new Date()).year+'-'+tzParts(new Date()).month+'-'+tzParts(new Date()).day))+fld('available_minutes','number',x.available_minutes)+fld('planned_minutes','number',x.planned_minutes)+fld('completed_minutes','number',x.completed_minutes)+fld('focus_level','number',x.focus_level)+fld('energy_level','number',x.energy_level)+fld('notes','textarea',x.notes)+'</div><div class=modalactions><button class="btn primary">'+esc(t('save'))+'</button></div></form>');$('f').onsubmit=async e=>{e.preventDefault();let d=collect(e.target);try{if(date)await api('/daily-plans/'+date,{method:'PUT',body:JSON.stringify(d)});else await api('/daily-plans',{method:'POST',body:JSON.stringify(d)});closeModal();toast(t('saved'));daily()}catch(z){fail(z)}}}
async function editSchedule(id){let r=await api('/schedule-blocks/'+id);modal(t('edit_item',{item:entityName('schedule')}),'<form id=f><div class=formgrid><div class=field><label>'+esc(I18N.fields.starts_at)+'</label><input name=starts_at type=datetime-local value="'+toInput(r.starts_at)+'"></div><div class=field><label>'+esc(I18N.fields.ends_at)+'</label><input name=ends_at type=datetime-local value="'+toInput(r.ends_at)+'"></div><div class=field><label>'+esc(I18N.fields.status)+'</label><input name=status value="'+esc(r.status||'planned')+'"></div></div><div class=modalactions><button class="btn primary">'+esc(t('save'))+'</button></div></form>');$('f').onsubmit=async e=>{e.preventDefault();try{await api('/schedule-blocks/'+id,{method:'PUT',body:JSON.stringify(collect(e.target))});closeModal();calendar()}catch(x){fail(x)}}}
async function deleteSchedule(id){if(!confirm(t('confirm_delete')))return;try{await api('/schedule-blocks/'+id,{method:'DELETE'});toast(t('deleted'));calendar()}catch(e){fail(e)}}
async function calendar(){await cacheAll();let p=tzParts(new Date()),base=new Date(Date.UTC(+p.year,+p.month-1,+p.day,12)),dow=base.getUTCDay(),shift=LOCALE==='fa'?(dow+1)%7:(dow+6)%7,ds=[];for(let i=0;i<7;i++){let d=new Date(base);d.setUTCDate(base.getUTCDate()-shift+i);ds.push(d)}let bs=await Promise.all(ds.map(d=>api('/schedule-blocks?date='+d.toISOString().slice(0,10)).then(x=>x.data||x||[]).catch(()=>[])));$('content').innerHTML='<div class=card><div class=head><h2>'+esc(t('week_calendar'))+'</h2><button class="btn primary" onclick="openCreate(\'schedule\')">＋ '+esc(entityName('schedule'))+'</button></div><div class=calendar>'+ds.map((d,i)=>'<div class=day><b>'+esc(d.toLocaleDateString(INTL,{weekday:'short',day:'numeric',month:'short',timeZone:'UTC'}))+'</b>'+(Array.isArray(bs[i])?bs[i]:[]).map(b=>'<div class=block>'+fmtDT(b.starts_at)+'<br>'+esc(b.task?.title||t('focus'))+'<div class=rowactions><button class="btn small" onclick="editSchedule('+b.id+')">'+esc(t('edit'))+'</button><button class="btn small danger" onclick="deleteSchedule('+b.id+')" aria-label="'+esc(t('delete'))+'">×</button></div></div>').join('')+'</div>').join('')+'</div></div>'}
function renderAIResult(r){let res=r.result||r,sec=(title,v)=>{if(v==null||v===''||(Array.isArray(v)&&!v.length))return'';let items=Array.isArray(v)?v:[v];return '<h3>'+esc(title)+'</h3><ul>'+items.map(i=>'<li>'+esc(typeof i==='object'?(i.title||i.action||i.recommendation||i.risk||i.description||brief(i)):i)+(typeof i==='object'&&(i.reason||i.priority)?' <span class=muted>'+esc([i.priority,i.reason].filter(Boolean).join(' · '))+'</span>':'')+'</li>').join('')+'</ul>'};return '<div class=ai-out>'+sec(t('ai_summary'),res.summary)+sec(t('ai_risks'),res.risks)+sec(t('ai_recommendations'),res.recommendations)+'<p class=muted>'+esc(t('ai_disclaimer'))+'</p></div>'}
async function aiPlanner(){$('content').innerHTML='<div class=grid2><div class=card><div class=head><h2>'+esc(I18N.nav.ai_planner)+'</h2><span class=muted>'+esc(t('ai_hint'))+'</span></div><textarea id=aitext style="width:100%;min-height:180px;border:1px solid #d6dce5;border-radius:10px;padding:12px" placeholder="'+esc(t('ai_placeholder'))+'"></textarea><div class=modalactions><button class="btn primary" id=aibtn onclick="askAI()">'+esc(t('ai_button'))+'</button></div></div><div class=card><div class=head><h2>'+esc(t('ai_result'))+'</h2></div><div id=aires class=empty>'+esc(t('ai_empty'))+'</div></div></div>'}
async function askAI(){let text=$('aitext').value.trim();if(!text)return;$('aibtn').disabled=true;$('aires').className='';$('aires').textContent=t('ai_thinking');try{let r=await api('/planner/ai-recommendations',{method:'POST',body:JSON.stringify({focus:text})});$('aires').innerHTML=renderAIResult(r)}catch(e){$('aires').textContent=e.message;fail(e)}finally{$('aibtn').disabled=false}}
async function execution(){await cacheAll();let r=await api('/system/execution'),rows=r.data||[];$('content').innerHTML='<div class=card><div class=head><h2>'+esc(I18N.nav.execution)+'</h2><button class="btn primary" onclick="executionForm()">＋ '+esc(t('log_work'))+'</button></div><div class=list>'+(rows.map(x=>'<div class=row><div class=rowmain><div class=rowtitle>'+esc(x.task?.title||'—')+'</div><div class=rowsub>'+fmtDT(x.started_at)+' · '+esc(t('minutes',{count:num(x.duration_minutes||0)}))+' · '+esc(I18N.fields.focus_level)+' '+esc(num(x.focus_level))+' · '+esc(I18N.fields.energy_level)+' '+esc(num(x.energy_level))+'</div><div class=rowsub>'+esc(x.result||x.notes||x.blocker||'')+'</div></div><div class=rowactions><button class="btn small" onclick="executionForm('+x.task_id+','+x.id+')">'+esc(t('edit'))+'</button><button class="btn small danger" onclick="deleteExecution('+x.task_id+','+x.id+')">'+esc(t('delete'))+'</button></div></div>').join('')||empty())+'</div></div>'}
async function executionForm(taskId,id){await cacheAll();let x={};if(id){let r=await api('/tasks/'+taskId+'/execution-logs');x=(r.data||[]).find(v=>v.id==id)||{}}let fld=(n,ty,v,full)=>'<div class="field'+(full?' full':'')+'"><label>'+esc(I18N.fields[n]||n)+'</label>'+(ty==='textarea'?'<textarea name='+n+'>'+esc(v??'')+'</textarea>':'<input name='+n+' type='+ty+' value="'+esc(v??'')+'"'+(ty==='number'?' min=0':'')+'>')+'</div>';modal(id?t('edit_item',{item:entityName('execution')}):t('log_work'),'<form id=f><div class=formgrid>'+(id?'':'<div class=field><label>'+esc(entityName('task'))+'</label>'+options('task').replace('__F','task_id')+'</div>')+fld('started_at','datetime-local',toInput(x.started_at))+fld('ended_at','datetime-local',toInput(x.ended_at))+fld('duration_minutes','number',x.duration_minutes)+fld('focus_level','number',x.focus_level)+fld('energy_level','number',x.energy_level)+fld('result','textarea',x.result,1)+fld('notes','textarea',x.notes,1)+'</div><div class=modalactions><button class="btn primary">'+esc(t('save'))+'</button></div></form>');$('f').onsubmit=async e=>{e.preventDefault();let d=collect(e.target),tid=id?taskId:d.task_id;delete d.task_id;if(!tid){toast(t('choose_task'));return}try{await api('/tasks/'+tid+'/execution-logs'+(id?'/'+id:''),{method:id?'PUT':'POST',body:JSON.stringify(d)});closeModal();toast(t('saved'));execution()}catch(z){fail(z)}}}
async function deleteExecution(taskId,id){if(!confirm(t('confirm_delete')))return;try{await api('/tasks/'+taskId+'/execution-logs/'+id,{method:'DELETE'});toast(t('deleted'));execution()}catch(e){fail(e)}}
async function failures(){let r=await api('/system/failures'),a=r.data||[];$('content').innerHTML='<div class=card><div class=head><h2>'+esc(I18N.nav.failures)+'</h2><button class="btn primary" onclick="logFailure()">＋ '+esc(t('log_failure'))+'</button></div><p class=muted>'+esc(t('failures_readonly'))+'</p><div class=list>'+(a.map(x=>'<div class=row><div><b>'+esc(I18N.failure[x.code]||x.name||x.code)+'</b><div class=rowsub>'+esc(t('preventable'))+': '+esc(label(x.preventable))+' · '+esc(t('severity'))+': '+esc(num(x.severity))+'</div></div></div>').join('')||empty())+'</div></div>'}
async function logFailure(){await cacheAll();modal(t('log_failure'),'<form id=f><div class=formgrid><div class=field><label>'+esc(entityName('task'))+'</label>'+options('task').replace('__F','task_id')+'</div><div class=field><label>'+esc(I18N.fields.reason)+'</label>'+selectOf('failure_reason',I18N.failure)+'</div><div class="field full"><label>'+esc(I18N.fields.description)+'</label><textarea name=description></textarea></div></div><div class=modalactions><button class="btn primary">'+esc(t('save'))+'</button></div></form>');$('f').onsubmit=async e=>{e.preventDefault();let d=collect(e.target),id=d.task_id;if(!id||!d.failure_reason){toast(t('choose_task'));return}try{await api('/tasks/'+id,{method:'PUT',body:JSON.stringify({failure_reason:d.failure_reason,description:d.description,status:'blocked'})});closeModal();toast(t('failure_logged'));failures()}catch(x){fail(x)}}}
async function dependencies(){let r=await api('/system/dependencies'),rows=r.data||[];$('content').innerHTML='<div class=card><div class=head><h2>'+esc(I18N.nav.dependencies)+'</h2><button class="btn primary" onclick="addDependency()">＋ '+esc(entityName('dependency'))+'</button></div><div class=list>'+(rows.map(x=>'<div class=row><div><b>'+esc(x.task?.title||'—')+'</b><div class=rowsub>'+esc(I18N.dep[x.type]||x.type)+' ← '+esc((x.depends_on||x.dependsOn||{}).title||('#'+x.depends_on_task_id))+'</div></div><button class="btn small danger" onclick="deleteDependency('+x.task_id+','+x.id+')">'+esc(t('delete'))+'</button></div>').join('')||empty())+'</div></div>'}
async function deleteDependency(taskId,id){if(!confirm(t('confirm_delete')))return;try{await api('/tasks/'+taskId+'/dependencies/'+id,{method:'DELETE'});toast(t('deleted'));dependencies()}catch(e){fail(e)}}
async function addDependency(){await cacheAll();modal(entityName('dependency'),'<form id=f><div class=formgrid><div class=field><label>'+esc(entityName('task'))+'</label>'+options('task').replace('__F','task_id')+'</div><div class=field><label>'+esc(I18N.fields.depends_on_task_id)+'</label>'+options('task').replace('__F','depends_on_task_id')+'</div><div class=field><label>'+esc(I18N.fields.type)+'</label>'+selectOf('type',I18N.dep)+'</div></div><div class=modalactions><button class="btn primary">'+esc(t('save'))+'</button></div></form>');$('f').onsubmit=async e=>{e.preventDefault();let d=collect(e.target);try{await api('/tasks/'+d.task_id+'/dependencies',{method:'POST',body:JSON.stringify({depends_on_task_id:d.depends_on_task_id,type:d.type||'requires'})});closeModal();toast(t('saved'));dependencies()}catch(x){fail(x)}}}
async function reviews(){let r=await api('/reviews'),a=r.data||[];$('content').innerHTML='<div class=card><div class=head><h2>'+esc(I18N.nav.reviews)+'</h2><button class="btn primary" onclick="generateReview()">＋ '+esc(t('generate_review'))+'</button></div><div class=list>'+(a.map(x=>'<div class=row><div><b>'+esc(I18N.reviewType[x.type]||x.type)+'</b><div class=rowsub>'+esc(t('range',{from:fmt(x.period_start),to:fmt(x.period_end)}))+' · '+esc(x.summary||'')+'</div></div></div>').join('')||empty())+'</div></div>'}
async function generateReview(){try{await api('/reviews/generate',{method:'POST',body:JSON.stringify({type:'weekly'})});toast(t('review_generated'));loadView('reviews')}catch(e){fail(e)}}
async function loadView(v){view=v;$('title').textContent=v==='today'?I18N.home.title:(I18N.nav[{'ai-planner':'ai_planner',ai:'ai_interactions'}[v]||v]||v);$('subtitle').textContent=v==='today'?new Date().toLocaleDateString(INTL,{timeZone:TZ,weekday:'long',day:'numeric',month:'long'}):(I18N.views[v]||'');setActive(v);document.body.classList.remove('nav-open');$('content').innerHTML=skeleton();try{await (async()=>{
if(v==='today')return today();if(v==='inbox')return inbox();if(v==='search')return searchView();if(v==='ai-planner')return aiPlanner();if(v==='analytics')return analytics();if(v==='reports')return reports('week');if(v==='daily')return daily();if(v==='calendar')return window.calendarView?calendarView():calendar();if(v==='recurring')return recurringView();if(v==='dependencies')return dependencies();
if(v==='execution')return execution();if(v==='failures')return failures();if(v==='reviews')return reviews();
if(v==='ai')return systemList('ai-interactions',I18N.nav.ai_interactions,renderAI);if(v==='pending')return systemList('pending-actions',I18N.nav.pending,renderPending);if(v==='activity')return window.activityTimeline?activityTimeline():systemList('activity',I18N.nav.activity,renderActivity);
if(['tasks','goals','projects','areas','milestones','reminders','notes','decisions'].includes(v))return loadList(v);
})();if(view===v)polish(v)}catch(e){$('content').innerHTML='<div class="alert danger" role=alert>'+esc(e.message)+'</div>'}}
/* Consistent finishing for every view: no heading that repeats the page title, icon buttons instead of text glyphs. */
function polish(v){let c=$('content'),h=c.querySelector('.card .head h2');if(h&&h.textContent.trim().replace(/^[✦◎↻\s]+/,'')===$('title').textContent.trim())h.remove();
c.querySelectorAll('button,.btn').forEach(b=>{let f=b.firstChild;if(!f||f.nodeType!==3)return;let m=f.nodeValue.match(/^\s*([＋✦⤓])\s*/);if(!m)return;f.nodeValue=f.nodeValue.slice(m[0].length);b.insertAdjacentHTML('afterbegin',ic({'＋':'plus','✦':'haman','⤓':'download'}[m[1]],'sm'))});
c.querySelectorAll('h2').forEach(x=>{let f=x.firstChild;if(f&&f.nodeType===3&&/^[✦◎]\s/.test(f.nodeValue))f.nodeValue=f.nodeValue.slice(2)})}
function setActive(v){document.querySelectorAll('[data-view]').forEach(x=>{let on=x.dataset.view===v;x.classList.toggle('active',on);if(on)x.setAttribute('aria-current','page');else x.removeAttribute('aria-current')});if(['notes','decisions','activity','ai','pending','search'].includes(v))$('navmore').open=true}
document.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>{loadView(b.dataset.view);history.pushState(null,'','#'+b.dataset.view)});
document.querySelectorAll('[data-go]').forEach(b=>b.onclick=()=>loadView(b.dataset.go));
const navOpen=o=>{document.body.classList.toggle('nav-open',o);$('navtoggle').setAttribute('aria-expanded',String(o));if(o)$('side').querySelector('button,a').focus()};
$('navtoggle').onclick=()=>navOpen(true);$('tabmore').onclick=()=>navOpen(true);$('scrim').onclick=()=>navOpen(false);
document.addEventListener('DOMContentLoaded',()=>{let h=location.hash.slice(1);loadView(h&&document.querySelector('[data-view="'+h+'"]')?h:view)});
addEventListener('hashchange',()=>{let h=location.hash.slice(1);if(h&&h!==view&&document.querySelector('[data-view="'+h+'"]'))loadView(h)});
let pwaPrompt=null;addEventListener('beforeinstallprompt',e=>{e.preventDefault();pwaPrompt=e;$('pwa-install').classList.remove('hide')});$('pwa-install').onclick=async()=>{if(!pwaPrompt)return;pwaPrompt.prompt();await pwaPrompt.userChoice;pwaPrompt=null;$('pwa-install').classList.add('hide')};
</script>
<script src="{{ asset('js/planner-calendar.js') }}?v={{ @filemtime(public_path('js/planner-calendar.js')) }}"></script>
<script src="{{ asset('js/planner-ai.js') }}?v={{ @filemtime(public_path('js/planner-ai.js')) }}"></script></body></html>
