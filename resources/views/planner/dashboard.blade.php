<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Haman Planner</title>
    <style>
        :root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#18212f;background:#f5f7fa}*{box-sizing:border-box}body{margin:0}.app{display:grid;grid-template-columns:240px 1fr;min-height:100vh}.side{background:#101827;color:#fff;padding:24px 18px}.brand{font-weight:800;font-size:20px;margin-bottom:28px}.nav button{display:block;width:100%;text-align:left;background:transparent;border:0;color:#c8d2e1;padding:11px 12px;border-radius:10px;cursor:pointer}.nav button:hover,.nav button.active{background:#1d2a3e;color:#fff}.main{padding:28px;max-width:1500px;width:100%;margin:auto}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:22px}.top h1{margin:0;font-size:28px}.actions{display:flex;gap:8px}.btn{border:1px solid #d6dce5;background:#fff;border-radius:9px;padding:9px 13px;cursor:pointer}.btn.primary{background:#18212f;color:#fff;border-color:#18212f}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card{background:#fff;border:1px solid #e2e7ee;border-radius:14px;padding:18px;box-shadow:0 3px 14px rgba(16,24,39,.04)}.metric{font-size:28px;font-weight:800;margin-top:8px}.muted{color:#718096;font-size:13px}.section{margin-top:18px}.section h2{font-size:17px;margin:0 0 10px}.list{display:grid;gap:8px}.task{display:flex;justify-content:space-between;gap:12px;padding:12px;border:1px solid #e5e9ef;border-radius:10px}.pill{font-size:12px;padding:4px 8px;border-radius:999px;background:#eef2f7}.bar{height:8px;background:#edf0f4;border-radius:99px;overflow:hidden}.bar>span{display:block;height:100%;background:#18212f}.two{display:grid;grid-template-columns:1.4fr 1fr;gap:14px}.search{width:280px;padding:10px 12px;border:1px solid #d6dce5;border-radius:9px}.hidden{display:none}@media(max-width:900px){.app{grid-template-columns:1fr}.side{position:sticky;top:0;z-index:2}.grid{grid-template-columns:repeat(2,1fr)}.two{grid-template-columns:1fr}}@media(max-width:560px){.main{padding:16px}.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}.search{width:100%}}
    </style>
</head>
<body>
<div class="app">
<aside class="side"><div class="brand">Haman Planner</div><div class="nav">
<button class="active" data-view="today">Today</button><button data-view="tasks">Tasks</button><button data-view="goals">Goals</button><button data-view="projects">Projects</button><button data-view="analytics">Analytics</button><button data-view="reviews">Reviews</button>
</div></aside>
<main class="main">
<div class="top"><div><div class="muted">Personal & Business Operating System</div><h1 id="title">Today</h1></div><div class="actions"><input id="token" class="search" type="password" placeholder="API token"><button class="btn primary" onclick="load()">Refresh</button></div></div>
<div id="content"></div>
</main></div>
<script>
const apiBase='/api';
const tokenEl=document.getElementById('token');
tokenEl.value=localStorage.getItem('haman_api_token')||'';tokenEl.addEventListener('change',()=>localStorage.setItem('haman_api_token',tokenEl.value));
async function api(path){const r=await fetch(apiBase+path,{headers:{Authorization:'Bearer '+tokenEl.value,Accept:'application/json'}});if(!r.ok)throw new Error('API '+r.status);return r.json()}
function metric(label,value,sub=''){return '<div class="card"><div class="muted">'+label+'</div><div class="metric">'+value+'</div><div class="muted">'+sub+'</div></div>'}
function taskRow(t){return '<div class="task"><div><strong>'+esc(t.title)+'</strong><div class="muted">'+(t.deadline||'No deadline')+'</div></div><span class="pill">'+esc(t.priority||t.status||'task')+'</span></div>'}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
async function today(){const [p,a]=await Promise.all([api('/planner/today'),api('/planner/analytics')]);const tasks=p.tasks||p.data||[];const m=a.metrics||a;document.getElementById('content').innerHTML='<div class="grid">'+metric('Planned minutes',m.planned_minutes??0)+metric('Completed minutes',m.actual_minutes??m.completed_minutes??0)+metric('Completion rate',((m.completion_rate??0)*100).toFixed(0)+'%')+metric('Overdue',m.overdue_open_tasks??0)+'</div><div class="section two"><div class="card"><h2>Today\'s plan</h2><div class="list">'+tasks.slice(0,20).map(taskRow).join('')+'</div></div><div class="card"><h2>Execution</h2><div class="muted">Planning accuracy and schedule variance are computed from stored execution data.</div><div style="margin-top:18px"><div class="muted">Schedule variance sample</div><div class="metric">'+(m.schedule_variance_sample??0)+'</div></div></div></div>'}
async function list(kind){const r=await api('/'+kind);const rows=r.data||r;document.getElementById('content').innerHTML='<div class="card"><div class="list">'+rows.map(x=>'<div class="task"><div><strong>'+esc(x.title)+'</strong><div class="muted">Progress: '+(x.progress??0)+'%</div></div><span class="pill">'+esc(x.status||'active')+'</span></div>').join('')+'</div></div>'}
async function analytics(){const a=await api('/planner/analytics');const m=a.metrics||a;document.getElementById('content').innerHTML='<div class="grid">'+Object.entries(m).slice(0,12).map(([k,v])=>metric(k.replaceAll('_',' '),typeof v==='number'?v.toLocaleString():String(v??''))).join('')+'</div>'}
async function reviews(){const r=await api('/reviews');const rows=r.data||r;document.getElementById('content').innerHTML='<div class="card"><h2>Reviews</h2><div class="list">'+rows.map(x=>'<div class="task"><div><strong>'+esc(x.type||'review')+'</strong><div class="muted">'+esc(x.period||x.created_at||'')+'</div></div><span class="pill">'+esc(x.status||'generated')+'</span></div>').join('')+'</div></div>'}
async function load(view='today'){document.getElementById('title').textContent=view[0].toUpperCase()+view.slice(1);try{if(view==='today')await today();else if(view==='tasks')await list('tasks');else if(view==='goals')await list('goals');else if(view==='projects')await list('projects');else if(view==='analytics')await analytics();else await reviews()}catch(e){document.getElementById('content').innerHTML='<div class="card"><strong>Connection required</strong><p class="muted">Enter the configured API token, then refresh.</p></div>'}}
document.querySelectorAll('.nav button').forEach(b=>b.onclick=()=>{document.querySelectorAll('.nav button').forEach(x=>x.classList.remove('active'));b.classList.add('active');load(b.dataset.view)});load();
</script>
</body></html>
