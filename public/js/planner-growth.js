/* Haman Planner — Focus Mode (Pomodoro) and Habits.
 * Depends on the helpers defined in the planner page: api, esc, t, num, fmt, ic, toast, fail,
 * modal, closeModal, rowMenu, loadView, view, cache, I18N, INTL, TZ, $.
 * Server is the source of truth for focus time: the timer is drawn from server timestamps,
 * so a reload or a second tab never loses or double-counts time. Breaks run only here. */
(function () {
  'use strict';
  window.VIEWS = window.VIEWS || {};
  const F = (k, r) => { let s = (I18N.focus || {})[k] || k; if (r) for (const x in r) s = s.split(':' + x).join(r[x]); return s; };
  const HB = (k, r) => { let s = (I18N.habits || {})[k] || k; if (r) for (const x in r) s = s.split(':' + x).join(r[x]); return s; };
  const pad = n => String(n).padStart(2, '0');
  const digits = x => (String(INTL).startsWith('fa') ? x.replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]) : x);
  const clock = sec => { sec = Math.max(0, Math.ceil(sec)); const m = Math.floor(sec / 60), s = sec % 60; return digits(pad(m) + ':' + pad(s)); };
  const mins = m => (typeof window.dur === 'function' ? window.dur(m) : num(m) + ' ' + F('minutes'));
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => (Math.random() * 16 | 0).toString(16)));

  // =====================================================================
  // Focus
  // =====================================================================
  const FS = window.FOCUS = { session: null, settings: null, stats: null, offset: 0, tick: null, phase: 'idle', breakEnd: 0, breakLen: 0, preset: null, task: '', baseTitle: document.title };

  function nowServer() { return Date.now() + FS.offset; }
  function elapsed(s) {
    if (!s) return 0;
    if (s.status === 'running') return (nowServer() - new Date(s.started_at).getTime()) / 1000 - (s.paused_seconds || 0);
    return s.elapsed_seconds || 0;
  }
  function remaining(s) { return Math.max(0, s.planned_minutes * 60 - elapsed(s)); }

  async function loadState() {
    const r = await api('/focus');
    FS.session = r.current; FS.settings = r.settings; FS.stats = r.stats;
    FS.offset = new Date(r.server_time).getTime() - Date.now();
    if (FS.session) FS.phase = 'focus';
    ensureTicker();
    return r;
  }

  function ensureTicker() {
    clearInterval(FS.tick);
    if ((FS.session && FS.session.status === 'running') || FS.phase === 'break') FS.tick = setInterval(onTick, 500);
    onTick();
  }

  function chime() {
    if (!FS.settings || !FS.settings.sound) return;
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      [660, 880, 990].forEach((f, i) => {
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.frequency.value = f; o.type = 'sine'; o.connect(g); g.connect(ctx.destination);
        const t0 = ctx.currentTime + i * 0.22; g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.25, t0 + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.5);
        o.start(t0); o.stop(t0 + 0.55);
      });
    } catch (e) { /* audio not allowed */ }
  }
  function notify(text) {
    if (!FS.settings || !FS.settings.notify || !('Notification' in window) || Notification.permission !== 'granted') return;
    try { new Notification(text, { tag: 'haman-focus', silent: true }); } catch (e) { /* ignore */ }
  }

  let finishing = false;
  async function onTick() {
    const s = FS.session;
    if (FS.phase === 'focus' && s && s.status === 'running' && remaining(s) <= 0 && !finishing) {
      finishing = true;
      try { await finish(true); } finally { finishing = false; }
      return;
    }
    if (FS.phase === 'break' && FS.breakEnd && Date.now() >= FS.breakEnd) {
      FS.phase = 'idle'; FS.breakEnd = 0; clearInterval(FS.tick);
      chime(); notify(F('notify_break')); toast(F('break_over'));
    }
    paintPill();
    paintTimer();
    const zc = document.getElementById('fzenctl'); if (zc) zc.innerHTML = zenControls();
  }

  // small running indicator in the top bar, visible on every page
  function paintPill() {
    let pill = document.getElementById('focuspill');
    const s = FS.session, active = (s && FS.phase === 'focus') || FS.phase === 'break';
    if (!active) { if (pill) pill.remove(); document.title = FS.baseTitle; return; }
    if (!pill) {
      const host = document.querySelector('.top .actions');
      if (!host) return;
      pill = document.createElement('button'); pill.id = 'focuspill'; pill.className = 'focus-pill'; pill.onclick = () => loadView('focus');
      host.prepend(pill);
    }
    const left = FS.phase === 'break' ? (FS.breakEnd - Date.now()) / 1000 : remaining(s);
    const paused = s && s.status === 'paused' && FS.phase === 'focus';
    pill.className = 'focus-pill' + (FS.phase === 'break' ? ' brk' : '') + (paused ? ' paused' : '');
    pill.innerHTML = ic(paused ? 'pause' : 'focus', 'sm') + '<span>' + esc(clock(left)) + '</span>';
    pill.setAttribute('aria-label', (FS.phase === 'break' ? F('break') : F('focusing')) + ' ' + clock(left));
    document.title = clock(left) + ' · ' + (FS.phase === 'break' ? F('break') : F('title'));
  }

  function ring(pct, label, sub, cls) {
    const R = 104, C = 2 * Math.PI * R, off = C * (1 - Math.max(0, Math.min(1, pct)));
    return '<div class="fring ' + (cls || '') + '"><svg viewBox="0 0 240 240" aria-hidden="true"><circle cx="120" cy="120" r="' + R + '" class="track"/>'
      + '<circle cx="120" cy="120" r="' + R + '" class="bar" style="stroke-dasharray:' + C.toFixed(1) + ';stroke-dashoffset:' + off.toFixed(1) + '"/></svg>'
      + '<div class="fring-in"><div class="fclock" id="fclock">' + esc(label) + '</div><div class="fsub">' + esc(sub || '') + '</div></div></div>';
  }

  function paintTimer() {
    const boxes = document.querySelectorAll('[data-ftimer]');
    if (!boxes.length) return;
    const s = FS.session;
    let html = '';
    if (FS.phase === 'break') {
      const left = (FS.breakEnd - Date.now()) / 1000, pct = 1 - left / FS.breakLen;
      html = ring(pct, clock(left), FS.breakLen >= (FS.settings.long_break_minutes * 60) ? F('long_break') : F('break'), 'brk');
    } else if (s) {
      const left = remaining(s), pct = 1 - left / (s.planned_minutes * 60);
      html = ring(pct, clock(left), s.status === 'paused' ? F('paused') : (s.task ? s.task.title : F('free_focus')), s.status === 'paused' ? 'paused' : '');
    } else return;
    boxes.forEach(b => { b.innerHTML = html; });
  }

  function controls() {
    const s = FS.session;
    if (FS.phase === 'break') return '<button class="btn primary" onclick="focusSkipBreak()">' + ic('skip') + esc(F('skip_break')) + '</button>';
    if (!s) return '';
    return (s.status === 'running'
      ? '<button class="btn" onclick="focusAct(\'pause\')">' + ic('pause') + esc(F('pause')) + '</button>'
      : '<button class="btn" onclick="focusAct(\'resume\')">' + ic('play') + esc(F('resume')) + '</button>')
      + '<button class="btn primary" onclick="focusFinish()">' + ic('check') + esc(F('finish')) + '</button>'
      + '<button class="btn ghost" onclick="focusCancel()">' + esc(F('cancel')) + '</button>'
      + '<button class="btn ghost icon" onclick="focusZen()" title="' + esc(F('fullscreen')) + '" aria-label="' + esc(F('fullscreen')) + '">' + ic('expand') + '</button>';
  }

  function presets() {
    const st = FS.settings, cur = FS.preset || st.focus_minutes + '/' + st.break_minutes;
    const list = [['25/5', 25, 5], ['50/10', 50, 10]];
    if (!['25/5', '50/10'].includes(st.focus_minutes + '/' + st.break_minutes)) list.push([st.focus_minutes + '/' + st.break_minutes, st.focus_minutes, st.break_minutes]);
    return '<div class="seg" role="radiogroup" aria-label="' + esc(F('preset')) + '">' + list.map(([k, f, b]) =>
      '<button role="radio" aria-checked="' + (cur === k) + '" class="' + (cur === k ? 'on' : '') + '" onclick="focusPreset(\'' + k + '\')">' + esc(num(f) + ' / ' + num(b)) + '</button>').join('')
      + '<button role="radio" aria-checked="' + (cur === 'custom') + '" class="' + (cur === 'custom' ? 'on' : '') + '" onclick="focusPreset(\'custom\')">' + esc(F('custom')) + '</button></div>'
      + (cur === 'custom' ? '<div class="frow"><label>' + esc(F('focus_minutes')) + ' <input id="fcm" class="input" type="number" min="5" max="180" value="' + st.focus_minutes + '"></label><label>' + esc(F('break_minutes')) + ' <input id="fcb" class="input" type="number" min="1" max="60" value="' + st.break_minutes + '"></label></div>' : '');
  }

  function plan() {
    const st = FS.settings, p = FS.preset || st.focus_minutes + '/' + st.break_minutes;
    if (p === 'custom') {
      const f = +(document.getElementById('fcm') || {}).value || st.focus_minutes, b = +(document.getElementById('fcb') || {}).value || st.break_minutes;
      return [Math.max(5, Math.min(180, f)), Math.max(1, Math.min(60, b))];
    }
    const [f, b] = p.split('/').map(Number);
    return [f, b];
  }

  function taskOptions(selected) {
    const open = (cache.tasks || []).filter(x => !['completed', 'cancelled'].includes(x.status));
    return '<select id="ftask" class="input" aria-label="' + esc(F('pick_task')) + '"><option value="">' + esc(F('free_focus')) + '</option>'
      + open.map(x => '<option value="' + x.id + '"' + (String(selected) === String(x.id) ? ' selected' : '') + '>' + esc(x.title) + '</option>').join('') + '</select>';
  }

  function statsCard() {
    const s = FS.stats || { days: [] }, max = Math.max(1, ...s.days.map(d => d.minutes));
    const wd = d => new Date(d + 'T12:00:00Z').toLocaleDateString(INTL, { weekday: 'short', timeZone: 'UTC' });
    return '<section class="card"><div class="head"><h2>' + esc(F('week')) + '</h2></div>'
      + '<div class="statgrid"><div><span class="muted small">' + esc(F('today')) + '</span><b>' + esc(mins(s.today_minutes || 0)) + '</b><span class="muted small">' + esc(num(s.today_sessions || 0) + ' ' + F('sessions')) + '</span></div>'
      + '<div><span class="muted small">' + esc(F('deep_work')) + '</span><b>' + esc(mins(s.total_minutes || 0)) + '</b><span class="muted small">' + esc(num(s.sessions || 0) + ' ' + F('sessions')) + '</span></div>'
      + '<div><span class="muted small">' + esc(F('average')) + '</span><b>' + esc(s.average_minutes ? mins(s.average_minutes) : '—') + '</b></div></div>'
      + '<div class="fbars" role="img" aria-label="' + esc(F('week')) + '">' + s.days.map(d => '<div class="fbar" title="' + esc(fmt(d.date + 'T12:00:00Z') + ' · ' + mins(d.minutes)) + '"><i style="height:' + Math.round(d.minutes / max * 100) + '%"></i><span>' + esc(wd(d.date)) + '</span></div>').join('') + '</div></section>';
  }

  async function historyCard() {
    const r = await api('/focus/sessions?per_page=10').catch(() => ({ data: [] }));
    const rows = (r.data || []).map(x => '<div class="row"><div class="rowmain"><div class="rowtitle">' + esc(x.task ? x.task.title : F('free_focus')) + '</div><div class="meta"><span>' + ic('clock', 'sm') + esc(fmt(x.started_at) + ' ' + new Date(x.started_at).toLocaleTimeString(INTL, { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false })) + '</span><span>' + ic('focus', 'sm') + esc(mins(Math.round((x.actual_seconds || 0) / 60))) + '</span></div></div><span class="pill ' + (x.status === 'completed' ? 'ok' : '') + '">' + esc(F('status_' + x.status)) + '</span></div>').join('');
    return '<section class="card"><div class="head"><h2>' + esc(F('history')) + '</h2></div>' + (rows ? '<div class="list">' + rows + '</div>' : '<p class="muted" style="margin:0">' + esc(F('no_history')) + '</p>') + '</section>';
  }

  function settingsCard() {
    const s = FS.settings;
    const f = (k, v, min, max) => '<div class="field"><label for="fs_' + k + '">' + esc(F(k)) + '</label><input id="fs_' + k + '" name="' + k + '" type="number" class="input" min="' + min + '" max="' + max + '" value="' + v + '"></div>';
    return '<details class="card"><summary><b>' + esc(F('settings')) + '</b></summary><form id="fsform" class="grid" style="margin-top:12px">'
      + f('focus_minutes', s.focus_minutes, 5, 180) + f('break_minutes', s.break_minutes, 1, 60) + f('long_break_minutes', s.long_break_minutes, 1, 90) + f('long_break_every', s.long_break_every, 2, 12)
      + '<label class="check"><input type="checkbox" name="sound"' + (s.sound ? ' checked' : '') + '> ' + esc(F('sound')) + '</label>'
      + '<label class="check"><input type="checkbox" name="notify"' + (s.notify ? ' checked' : '') + '> ' + esc(F('notify')) + '</label>'
      + '<div class="modalactions"><button class="btn primary">' + esc(t('save')) + '</button></div></form></details>';
  }

  window.VIEWS.focus = async function (opts) {
    opts = opts || {};
    if (!cache.tasks || !cache.tasks.length) await (window.cacheAll ? cacheAll() : Promise.resolve());
    await loadState();
    if (view !== 'focus') return;
    const s = FS.session, done = (FS.stats && FS.stats.today_sessions) || 0, every = FS.settings.long_break_every;
    const dots = '<div class="fdots" aria-label="' + esc(F('session_n', { n: num(done % every + (s ? 1 : 0) || every) })) + '">' + Array.from({ length: every }, (_, i) => '<i class="' + (i < done % every ? 'on' : '') + '"></i>').join('') + '</div>';
    let left;
    if (s || FS.phase === 'break') {
      left = '<section class="card focus-card"><div data-ftimer></div>' + dots + '<div class="actions center">' + controls() + '</div></section>';
    } else {
      left = '<section class="card focus-card"><div class="field"><label for="ftask">' + esc(F('pick_task')) + '</label>' + taskOptions(opts.task || FS.task) + '</div>'
        + '<div class="field"><label>' + esc(F('preset')) + '</label>' + presets() + '</div>'
        + ring(0, clock(plan()[0] * 60), F('start'), 'idle') + dots
        + '<div class="actions center"><button class="btn primary lg" onclick="focusStart()">' + ic('play') + esc(F('start')) + '</button></div>'
        + (done ? '' : '<p class="muted small center" style="margin:10px 0 0">' + esc(F('empty')) + '</p>') + '</section>';
    }
    $('content').innerHTML = '<div class="focus-grid"><div>' + left + '</div><div style="display:grid;gap:16px;align-content:start">' + statsCard() + '<div id="fhist"></div>' + settingsCard() + '</div></div>';
    paintTimer();
    historyCard().then(h => { const el = document.getElementById('fhist'); if (el) el.outerHTML = h; });
    const form = document.getElementById('fsform');
    if (form) form.onsubmit = async e => {
      e.preventDefault();
      const d = Object.fromEntries(new FormData(form)); d.sound = !!form.sound.checked; d.notify = !!form.notify.checked;
      if (d.notify && 'Notification' in window && Notification.permission === 'default') { try { await Notification.requestPermission(); } catch (x) { /* ignore */ } }
      try { FS.settings = await api('/focus/settings', { method: 'POST', body: JSON.stringify(d) }); FS.preset = null; toast(t('saved')); window.VIEWS.focus(); } catch (x) { fail(x, form); }
    };
    const sel = document.getElementById('ftask'); if (sel) sel.onchange = () => { FS.task = sel.value; };
    if (opts.autostart) focusStart();
  };

  window.focusPreset = function (k) { const sel = document.getElementById('ftask'); if (sel) FS.task = sel.value; FS.preset = k; window.VIEWS.focus(); };

  window.focusStart = async function () {
    const sel = document.getElementById('ftask'), [f] = plan();
    const body = { planned_minutes: f, client_uuid: uuid() };
    if (sel && sel.value) body.task_id = +sel.value;
    try {
      FS.session = await api('/focus/start', { method: 'POST', body: JSON.stringify(body) });
      FS.phase = 'focus'; FS.lastBreak = plan()[1];
      if (FS.settings.notify && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission().catch(() => {});
      ensureTicker(); window.VIEWS.focus();
    } catch (e) { fail(e); }
  };

  window.focusAct = async function (a) {
    try { FS.session = await api('/focus/' + FS.session.id + '/' + a, { method: 'POST' }); ensureTicker(); window.VIEWS.focus(); } catch (e) { fail(e); }
  };

  async function finish(auto) {
    const s = FS.session; if (!s) return;
    try {
      const r = await api('/focus/' + s.id + '/finish', { method: 'POST', body: '{}' });
      FS.session = null;
      const m = Math.round((r.actual_seconds || 0) / 60);
      toast(F('done_toast', { min: num(m) }) + (r.task && r.execution_log_id ? F('done_task', { task: r.task.title }) : ''));
      if (auto) { chime(); notify(F('notify_done')); }
      // Break: long one every N completed sessions today.
      const st = await api('/focus/stats?days=1').catch(() => null);
      const n = st ? st.today_sessions : 1, long = n > 0 && n % FS.settings.long_break_every === 0;
      const b = long ? FS.settings.long_break_minutes : (FS.lastBreak || FS.settings.break_minutes);
      FS.phase = 'break'; FS.breakLen = b * 60; FS.breakEnd = Date.now() + b * 60000;
      ensureTicker();
      if (window.cacheAll) cacheAll();
      if (view === 'focus') window.VIEWS.focus();
    } catch (e) { fail(e); }
  }
  window.focusFinish = () => finish(false);
  window.focusCancel = async function () {
    if (!confirm(F('cancel_confirm'))) return;
    try { await api('/focus/' + FS.session.id + '/cancel', { method: 'POST' }); FS.session = null; FS.phase = 'idle'; ensureTicker(); window.VIEWS.focus(); } catch (e) { fail(e); }
  };
  window.focusSkipBreak = function () { FS.phase = 'idle'; FS.breakEnd = 0; ensureTicker(); window.VIEWS.focus(); };

  // distraction-free overlay
  function zenControls() { return controls().replace(/<button class="btn ghost icon"[\s\S]*?<\/button>$/, ''); }
  window.focusZen = function () {
    let z = document.getElementById('fzen');
    if (z) { z.remove(); document.body.classList.remove('zen'); try { document.fullscreenElement && document.exitFullscreen(); } catch (e) { /* ignore */ } return; }
    z = document.createElement('div'); z.id = 'fzen'; z.className = 'fzen'; z.setAttribute('role', 'dialog'); z.setAttribute('aria-label', F('fullscreen'));
    z.innerHTML = '<div data-ftimer></div><div class="actions center" id="fzenctl">' + zenControls() + '</div><button class="btn ghost" onclick="focusZen()">' + esc(F('exit_fullscreen')) + '</button>';
    document.body.appendChild(z); document.body.classList.add('zen');
    paintTimer();
    try { z.requestFullscreen && z.requestFullscreen().catch(() => {}); } catch (e) { /* optional */ }
  };
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.getElementById('fzen')) window.focusZen(); });

  window.startFocusOn = function (taskId) { FS.task = String(taskId); loadView('focus'); };

  // resume the indicator on page load if a session is running
  document.addEventListener('DOMContentLoaded', () => { setTimeout(() => loadState().catch(() => {}), 400); });

  // =====================================================================
  // Habits
  // =====================================================================
  const HS = window.HABITS = { tab: 'active', data: null };
  const STATE_LABEL = s => HB(s === 'before_start' || s === 'future' ? 'not_due' : s);

  function streakText(h) {
    if (!h.current_streak) return '';
    return '<span class="streak" title="' + esc(HB('streak')) + '">' + ic('flame', 'sm') + esc(HB(h.streak_unit === 'weeks' ? 'weeks' : 'days', { n: num(h.current_streak) })) + '</span>';
  }

  function scheduleText(h) {
    if (h.frequency === 'weekdays') {
      const names = (h.weekdays || []).map(d => new Date(Date.UTC(2024, 0, d)).toLocaleDateString(INTL, { weekday: 'short', timeZone: 'UTC' })); // 2024-01-01 is a Monday (ISO 1)
      return names.join('، ');
    }
    if (h.frequency === 'weekly') return HB('week_n', { n: num(h.week_count || 0), t: num(h.times_per_week) });
    if (h.frequency === 'interval') return HB('freq_interval') + ' (' + num(h.interval_days) + ')';
    return HB('freq_daily');
  }

  function checkControl(h) {
    const e = h.today_entry, label = h.kind === 'reduce' ? HB('kept') : HB('check');
    if (h.status === 'paused') return '<span class="pill">' + esc(HB('paused')) + '</span>';
    if (!h.due_today && h.frequency !== 'weekly') return '<span class="muted small">' + esc(HB('not_due')) + '</span>';
    if (h.measure === 'numeric') {
      const v = e && e.value != null ? e.value : 0, target = h.target_value;
      const pct = target ? Math.min(100, Math.round(v / target * 100)) : (v > 0 ? 100 : 0);
      return '<div class="hnum' + (h.done_today ? ' ok' : '') + '"><button class="btn small icon" aria-label="−1" onclick="habitAdd(' + h.id + ',-1)">−</button>'
        + '<button class="hval" onclick="habitValue(' + h.id + ')" title="' + esc(HB('add_value')) + '"><b>' + esc(num(v)) + '</b>' + (target ? '<span>/' + esc(num(target)) + '</span>' : '') + ' <small>' + esc(h.unit || '') + '</small></button>'
        + '<button class="btn small icon" aria-label="+1" onclick="habitAdd(' + h.id + ',1)">+</button>'
        + (h.kind === 'build' ? '<i class="hbar"><b style="width:' + pct + '%"></b></i>' : '') + '</div>';
    }
    return '<button class="hcheck' + (h.done_today ? ' on' : '') + '" aria-pressed="' + !!h.done_today + '" aria-label="' + esc(label + ' — ' + h.title) + '" title="' + esc(label) + '" onclick="habitToggle(' + h.id + ',this)"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></button>';
  }

  function strip(h) {
    return '<div class="hstrip" aria-hidden="true">' + (h.recent || []).map(d => '<i class="s-' + d.state + '" title="' + esc(fmt(d.date + 'T12:00:00Z') + ' · ' + STATE_LABEL(d.state)) + '"></i>').join('') + '</div>';
  }

  function habitRow(h) {
    const color = h.color || 'var(--habit)';
    return '<div class="hrow' + (h.done_today ? ' done' : '') + '" id="habit-' + h.id + '" style="--hc:' + esc(color) + '">'
      + '<button class="hicon" onclick="habitDetail(' + h.id + ')" aria-label="' + esc(h.title) + '">' + esc(h.icon || '•') + '</button>'
      + '<div class="rowmain" onclick="habitDetail(' + h.id + ')" style="cursor:pointer"><div class="rowtitle">' + esc(h.title) + (h.kind === 'reduce' ? ' <span class="pill">' + esc(HB('kind_reduce')) + '</span>' : '') + '</div>'
      + '<div class="meta"><span>' + esc(scheduleText(h)) + '</span>' + streakText(h) + (h.month_rate != null ? '<span>' + esc(HB('month_rate')) + ' ' + esc(num(h.month_rate)) + '%</span>' : '') + '</div>' + strip(h) + '</div>'
      + '<div class="hctl">' + checkControl(h) + '</div>'
      + rowMenu([
        mi('edit', t('edit'), 'habitForm(' + h.id + ')'),
        h.status === 'paused' ? mi('play', HB('resume'), "habitAct(" + h.id + ",'resume')") : mi('pause', HB('pause'), "habitAct(" + h.id + ",'pause')"),
        mi('inbox', HB('archive'), "habitAct(" + h.id + ",'archive')"),
        mi('trash', t('delete'), 'habitDelete(' + h.id + ')', 'danger'),
      ]) + '</div>';
  }

  function heatmap(cells, opts) {
    opts = opts || {};
    const first = cells[0] ? new Date(cells[0].date + 'T12:00:00Z') : null;
    const cls = c => c.state ? 's-' + c.state : (c.rate == null ? 's-none' : c.rate >= 100 ? 'l4' : c.rate >= 67 ? 'l3' : c.rate >= 34 ? 'l2' : c.rate > 0 ? 'l1' : 'l0');
    const tip = c => fmt(c.date + 'T12:00:00Z') + ' · ' + (c.state ? STATE_LABEL(c.state) : c.rate == null ? '—' : num(c.done) + '/' + num(c.due));
    return '<div class="heat" style="--weeks:' + Math.ceil(cells.length / 7) + '" role="img" aria-label="' + esc(opts.label || HB('heatmap')) + '">'
      + cells.map(c => '<i class="' + cls(c) + '" title="' + esc(tip(c)) + '"></i>').join('') + '</div>'
      + (first ? '' : '');
  }

  window.VIEWS.habits = async function () {
    const archived = HS.tab === 'archived';
    const r = archived ? await api('/habits?archived=1') : await api('/habits');
    if (view !== 'habits') return;
    HS.data = r;
    const tabs = '<div class="seg" role="tablist"><button role="tab" aria-selected="' + !archived + '" class="' + (!archived ? 'on' : '') + '" onclick="HABITS.tab=\'active\';VIEWS.habits()">' + esc(HB('active_list')) + '</button><button role="tab" aria-selected="' + archived + '" class="' + (archived ? 'on' : '') + '" onclick="HABITS.tab=\'archived\';VIEWS.habits()">' + esc(HB('archive_list')) + '</button></div>';
    const add = '<button class="btn primary" onclick="habitForm()">' + ic('plus') + esc(HB('new')) + '</button>';
    if (archived) {
      const rows = (r.habits || []).map(h => '<div class="row"><div class="rowmain"><div class="rowtitle">' + esc((h.icon ? h.icon + ' ' : '') + h.title) + '</div></div><button class="btn small" onclick="habitAct(' + h.id + ',\'unarchive\')">' + esc(HB('unarchive')) + '</button><button class="btn small ghost danger" onclick="habitDelete(' + h.id + ')">' + esc(t('delete')) + '</button></div>').join('');
      $('content').innerHTML = '<div class="toolbar">' + tabs + '</div><section class="card">' + (rows ? '<div class="list">' + rows + '</div>' : '<p class="muted" style="margin:0">' + esc(I18N.life ? I18N.life.empty_archived : '') + '</p>') + '</section>';
      return;
    }
    const hs = r.habits || [];
    if (!hs.length && !r.paused) {
      $('content').innerHTML = '<div class="toolbar">' + tabs + '</div><section class="card"><div class="empty"><div class="empty-mark">' + ic('habit') + '</div><div class="empty-title">' + esc(HB('title')) + '</div><p class="empty-text">' + esc(HB('empty')) + '</p>' + add + '</div></section>';
      return;
    }
    const due = hs.filter(h => h.due_today || h.frequency === 'weekly'), rest = hs.filter(h => !(h.due_today || h.frequency === 'weekly'));
    const pct = r.today.due ? Math.round(r.today.done / r.today.due * 100) : null;
    const top = '<section class="card habit-top"><div class="ring" style="--p:' + (pct || 0) + '" data-label="' + esc(pct == null ? '—' : num(pct) + '%') + '"></div><div><b>' + esc(r.today.due ? (r.today.done >= r.today.due ? HB('all_done') : HB('progress_today', { done: num(r.today.done), due: num(r.today.due) })) : HB('empty_today')) + '</b>'
      + '<div class="muted small">' + esc(HB('week_rate')) + ': ' + esc(r.week_rate == null ? '—' : num(r.week_rate) + '%') + '</div></div><div style="margin-inline-start:auto">' + add + '</div></section>';
    const list = '<section class="card"><div class="head"><h2>' + esc(HB('today')) + '</h2></div><div class="hlist">' + (due.map(habitRow).join('') || '<p class="muted">' + esc(HB('empty_today')) + '</p>') + '</div>'
      + (rest.length ? '<div class="hlist dim">' + rest.map(habitRow).join('') + '</div>' : '') + '</section>';
    const heat = '<section class="card"><div class="head"><h2>' + esc(HB('heatmap')) + '</h2></div>' + heatmap(r.heatmap || []) + '<p class="muted small" style="margin:8px 0 0">' + esc(HB('encourage')) + '</p></section>';
    $('content').innerHTML = '<div class="toolbar">' + tabs + '</div>' + top + '<div class="home-grid"><div style="display:grid;gap:16px">' + list + '</div><div style="display:grid;gap:16px;align-content:start">' + heat + '</div></div>';
  };

  async function refreshHabit(h) {
    if (view === 'habits') return window.VIEWS.habits();
    if (view === 'today') { const w = document.getElementById('habitwidget'); if (w) w.outerHTML = await habitWidget(); }
  }

  window.habitToggle = async function (id, btn) {
    if (btn) { btn.classList.toggle('on'); btn.setAttribute('aria-pressed', btn.classList.contains('on')); }
    try { const r = await api('/habits/' + id + '/check', { method: 'POST', body: '{}' }); if (r.habit.done_today && r.habit.current_streak > 1) toast(HB('streak') + ': ' + HB(r.habit.streak_unit === 'weeks' ? 'weeks' : 'days', { n: num(r.habit.current_streak) })); refreshHabit(r.habit); }
    catch (e) { if (btn) btn.classList.toggle('on'); fail(e); }
  };
  window.habitAdd = async function (id, n) {
    try { const r = await api('/habits/' + id + '/check', { method: 'POST', body: JSON.stringify({ add: n }) }); refreshHabit(r.habit); } catch (e) { fail(e); }
  };
  window.habitValue = function (id) {
    const h = findHabit(id); if (!h) return;
    const e = h.today_entry || {};
    modal(h.title, '<form id="hvf"><div class="grid"><div class="field"><label for="hv">' + esc(HB('add_value')) + (h.unit ? ' (' + esc(h.unit) + ')' : '') + '</label><input id="hv" name="value" type="number" step="any" min="0" class="input" value="' + (e.value != null ? e.value : '') + '"></div>'
      + '<div class="field full"><label for="hn">' + esc(HB('note')) + '</label><textarea id="hn" name="note">' + esc(e.note || '') + '</textarea></div></div><div class="modalactions"><button class="btn primary">' + esc(t('save')) + '</button></div></form>');
    $('hvf').onsubmit = async ev => { ev.preventDefault(); const d = Object.fromEntries(new FormData(ev.target)); try { const r = await api('/habits/' + id + '/check', { method: 'POST', body: JSON.stringify({ value: d.value === '' ? null : +d.value, note: d.note || null }) }); closeModal(); refreshHabit(r.habit); } catch (x) { fail(x, ev.target); } };
  };
  window.habitAct = async function (id, a) {
    try { await api('/habits/' + id + '/' + a, { method: 'POST' }); closeModal(); toast(t('saved')); refreshHabit(); } catch (e) { fail(e); }
  };
  window.habitDelete = async function (id) {
    if (!confirm(HB('delete_confirm'))) return;
    try { await api('/habits/' + id, { method: 'DELETE' }); closeModal(); toast(t('deleted')); refreshHabit(); } catch (e) { fail(e); }
  };
  function findHabit(id) { return ((HS.data && HS.data.habits) || (HS.widget && HS.widget.habits) || []).find(h => h.id === id); }

  window.habitDetail = async function (id) {
    let r; try { r = await api('/habits/' + id + '?weeks=12'); } catch (e) { return fail(e); }
    const h = r.habit, hist = r.history;
    const miss = hist.missed_weekdays.length
      ? '<ul class="small" style="margin:4px 0 0">' + hist.missed_weekdays.map(m => '<li>' + esc(new Date(Date.UTC(2024, 0, m.weekday)).toLocaleDateString(INTL, { weekday: 'long', timeZone: 'UTC' })) + ' — ' + esc(num(m.missed) + '/' + num(m.due)) + '</li>').join('') + '</ul><p class="muted small" style="margin:4px 0 0">' + esc(HB('missed_hint')) + '</p>'
      : '<p class="muted small" style="margin:4px 0 0">' + esc(HB('no_missed')) + '</p>';
    const notes = (hist.entries || []).slice(-5).reverse().map(e => '<li><span class="muted">' + esc(fmt(e.entry_date + 'T12:00:00Z')) + '</span> — ' + esc(e.note) + '</li>').join('');
    modal((h.icon ? h.icon + ' ' : '') + h.title,
      '<div class="statgrid"><div><span class="muted small">' + esc(HB('streak')) + '</span><b>' + esc(HB(h.streak_unit === 'weeks' ? 'weeks' : 'days', { n: num(h.current_streak) })) + '</b></div>'
      + '<div><span class="muted small">' + esc(HB('longest')) + '</span><b>' + esc(HB(h.streak_unit === 'weeks' ? 'weeks' : 'days', { n: num(h.longest_streak) })) + '</b></div>'
      + '<div><span class="muted small">' + esc(HB('month_rate')) + '</span><b>' + esc(h.month_rate == null ? '—' : num(h.month_rate) + '%') + '</b></div></div>'
      + '<h3 class="small muted" style="margin:14px 0 6px">' + esc(HB('history')) + '</h3>' + heatmap(hist.days, { label: HB('history') })
      + '<div class="heat-legend small muted"><span><i class="s-done"></i>' + esc(HB('done')) + '</span><span><i class="s-missed"></i>' + esc(HB('missed')) + '</span><span><i class="s-paused"></i>' + esc(HB('paused')) + '</span><span><i class="s-not_due"></i>' + esc(HB('not_due')) + '</span></div>'
      + (h.frequency !== 'weekly' ? '<h3 class="small muted" style="margin:14px 0 0">' + esc(HB('missed_days')) + '</h3>' + miss : '')
      + (notes ? '<h3 class="small muted" style="margin:14px 0 4px">' + esc(HB('note')) + '</h3><ul class="small" style="margin:0">' + notes + '</ul>' : '')
      + '<div class="modalactions"><button class="btn" onclick="habitForm(' + h.id + ')">' + ic('edit') + esc(t('edit')) + '</button>'
      + (h.status === 'paused' ? '<button class="btn" onclick="habitAct(' + h.id + ',\'resume\')">' + esc(HB('resume')) + '</button>' : '<button class="btn" onclick="habitAct(' + h.id + ',\'pause\')">' + esc(HB('pause')) + '</button>')
      + '<button class="btn" onclick="habitAct(' + h.id + ',\'archive\')">' + esc(HB('archive')) + '</button></div>');
  };

  const EMOJI = ['📖', '🏃', '💧', '🧘', '🥗', '😴', '✍️', '🎯', '🚭', '📵', '🦷', '💊', '🎸', '🧠', '🌱', '☀️'];
  const COLORS = ['#10b981', '#14b8a6', '#0ea5e9', '#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#ef4444'];

  window.habitForm = async function (id) {
    let h = { kind: 'build', measure: 'binary', frequency: 'daily', icon: '', color: COLORS[0], weekdays: [] };
    if (id) { try { h = (await api('/habits/' + id + '?weeks=1')).habit; } catch (e) { return fail(e); } }
    if (!cache.goals || !cache.goals.length) await (window.cacheAll ? cacheAll() : Promise.resolve());
    const opt = (n, v, lab) => '<option value="' + v + '"' + (String(h[n]) === String(v) ? ' selected' : '') + '>' + esc(lab) + '</option>';
    const order = String(INTL).startsWith('fa') ? [6, 7, 1, 2, 3, 4, 5] : [1, 2, 3, 4, 5, 6, 7];
    const today = new Date().toLocaleDateString('en-CA', { timeZone: TZ });
    modal(id ? HB('edit') : HB('new'), '<form id="hf"><div class="grid">'
      + '<div class="field full"><label for="hf_title">' + esc(I18N.fields.title || 'Title') + ' <span class="req">*</span></label><input id="hf_title" name="title" class="input" required maxlength="120" value="' + esc(h.title || '') + '" placeholder="' + esc(HB('empty').split('—').pop().trim()) + '"></div>'
      + '<div class="field full"><label>' + esc(HB('icon')) + '</label><div class="emoji-pick">' + EMOJI.map(e => '<button type="button" class="' + (h.icon === e ? 'on' : '') + '" onclick="this.parentNode.querySelectorAll(\'.on\').forEach(x=>x.classList.remove(\'on\'));this.classList.add(\'on\');document.getElementById(\'hf_icon\').value=\'' + e + '\'">' + e + '</button>').join('') + '<input id="hf_icon" name="icon" class="input" style="width:64px" maxlength="8" value="' + esc(h.icon || '') + '"></div></div>'
      + '<div class="field full"><label>' + esc(HB('color')) + '</label><div class="swatches">' + COLORS.map(c => '<label class="sw" style="--c:' + c + '"><input type="radio" name="color" value="' + c + '"' + (h.color === c ? ' checked' : '') + '><i></i></label>').join('') + '</div></div>'
      + '<div class="field"><label for="hf_kind">' + esc(HB('kind')) + '</label><select id="hf_kind" name="kind" class="input">' + opt('kind', 'build', HB('kind_build')) + opt('kind', 'reduce', HB('kind_reduce')) + '</select></div>'
      + '<div class="field"><label for="hf_measure">' + esc(HB('measure')) + '</label><select id="hf_measure" name="measure" class="input">' + opt('measure', 'binary', HB('measure_binary')) + opt('measure', 'numeric', HB('measure_numeric')) + '</select></div>'
      + '<div class="field hf-num"><label for="hf_target" id="hf_target_l">' + esc(h.kind === 'reduce' ? HB('limit') : HB('target')) + '</label><input id="hf_target" name="target_value" type="number" step="any" min="0" class="input" value="' + (h.target_value ?? '') + '"></div>'
      + '<div class="field hf-num"><label for="hf_unit">' + esc(HB('unit')) + '</label><input id="hf_unit" name="unit" class="input" maxlength="30" placeholder="' + esc(HB('unit_ph')) + '" value="' + esc(h.unit || '') + '"></div>'
      + '<div class="field"><label for="hf_freq">' + esc(HB('frequency')) + '</label><select id="hf_freq" name="frequency" class="input">' + opt('frequency', 'daily', HB('freq_daily')) + opt('frequency', 'weekdays', HB('freq_weekdays')) + opt('frequency', 'weekly', HB('freq_weekly')) + opt('frequency', 'interval', HB('freq_interval')) + '</select></div>'
      + '<div class="field hf-wd full"><label>' + esc(HB('freq_weekdays')) + '</label><div class="wdays">' + order.map(d => '<label class="chip"><input type="checkbox" name="weekdays" value="' + d + '"' + ((h.weekdays || []).map(Number).includes(d) ? ' checked' : '') + '><span>' + esc(new Date(Date.UTC(2024, 0, d)).toLocaleDateString(INTL, { weekday: 'short', timeZone: 'UTC' })) + '</span></label>').join('') + '</div></div>'
      + '<div class="field hf-tw"><label for="hf_tw">' + esc(HB('times_per_week')) + '</label><input id="hf_tw" name="times_per_week" type="number" min="1" max="7" class="input" value="' + (h.times_per_week || 3) + '"></div>'
      + '<div class="field hf-iv"><label for="hf_iv">' + esc(HB('interval_days')) + '</label><input id="hf_iv" name="interval_days" type="number" min="2" max="365" class="input" value="' + (h.interval_days || 2) + '"></div>'
      + '<div class="field"><label for="hf_start">' + esc(HB('start_date')) + '</label><input id="hf_start" name="start_date" type="date" class="input" value="' + esc(h.start_date ? String(h.start_date).slice(0, 10) : today) + '"></div>'
      + '<div class="field"><label for="hf_goal">' + esc(HB('goal')) + '</label><select id="hf_goal" name="goal_id" class="input"><option value="">—</option>' + (cache.goals || []).map(g => '<option value="' + g.id + '"' + (String(h.goal_id) === String(g.id) ? ' selected' : '') + '>' + esc(g.title) + '</option>').join('') + '</select></div>'
      + '<div class="field full"><label for="hf_desc">' + esc(HB('description')) + '</label><textarea id="hf_desc" name="description">' + esc(h.description || '') + '</textarea></div>'
      + '<p class="muted small full hf-reduce" style="margin:0">' + esc(HB('reduce_hint')) + '</p>'
      + '</div><div class="modalactions"><button type="button" class="btn" onclick="closeModal()">' + esc(t('cancel')) + '</button><button class="btn primary">' + esc(t('save')) + '</button></div></form>');
    const f = $('hf'), sync = () => {
      const fr = f.frequency.value, m = f.measure.value, k = f.kind.value;
      f.querySelectorAll('.hf-num').forEach(x => x.hidden = m !== 'numeric');
      f.querySelector('.hf-wd').hidden = fr !== 'weekdays'; f.querySelector('.hf-tw').hidden = fr !== 'weekly'; f.querySelector('.hf-iv').hidden = fr !== 'interval';
      f.querySelector('.hf-reduce').hidden = k !== 'reduce' || m !== 'binary';
      $('hf_target_l').textContent = k === 'reduce' ? HB('limit') : HB('target');
    };
    f.onchange = sync; sync();
    f.onsubmit = async e => {
      e.preventDefault();
      const fd = new FormData(f), d = {};
      for (const [k, v] of fd.entries()) { if (k === 'weekdays') continue; if (v !== '') d[k] = v; }
      d.weekdays = fd.getAll('weekdays').map(Number);
      if (d.frequency !== 'weekdays') delete d.weekdays;
      if (d.frequency !== 'weekly') delete d.times_per_week;
      if (d.frequency !== 'interval') delete d.interval_days;
      if (d.measure !== 'numeric') { d.target_value = null; d.unit = null; }
      if (!d.goal_id) d.goal_id = null;
      try { await api(id ? '/habits/' + id : '/habits', { method: id ? 'PUT' : 'POST', body: JSON.stringify(d) }); closeModal(); toast(t('saved')); refreshHabit(); if (view !== 'habits' && view !== 'today') loadView('habits'); } catch (x) { fail(x, f); }
    };
  };

  // =====================================================================
  // "Today" widgets
  // =====================================================================
  async function habitWidget() {
    const r = await api('/habits').catch(() => null);
    HS.widget = r;
    if (!r) return '';
    const due = (r.habits || []).filter(h => h.due_today || h.frequency === 'weekly');
    const head = '<div class="head"><h2>' + ic('habit', 'sm') + ' ' + esc(HB('widget_title')) + '</h2><button class="btn ghost small" onclick="loadView(\'habits\')">' + esc(HB('open')) + ic('chev', 'sm flip') + '</button></div>';
    if (!(r.habits || []).length) return '<section class="card" id="habitwidget">' + head + '<p class="muted small" style="margin:0 0 10px">' + esc(HB('empty')) + '</p><button class="btn small" onclick="habitForm()">' + ic('plus') + esc(HB('new')) + '</button></section>';
    const pct = r.today.due ? Math.round(r.today.done / r.today.due * 100) : 0;
    return '<section class="card" id="habitwidget">' + head
      + (r.today.due ? '<div class="cap-line"><span>' + esc(r.today.done >= r.today.due ? HB('all_done') : HB('progress_today', { done: num(r.today.done), due: num(r.today.due) })) + '</span><span class="num">' + esc(r.week_rate == null ? '' : HB('week_rate') + ' ' + num(r.week_rate) + '%') + '</span></div><div class="progress habit"><i style="width:' + pct + '%"></i></div>' : '')
      + '<div class="hlist compact">' + (due.map(habitRow).join('') || '<p class="muted small" style="margin:0">' + esc(HB('empty_today')) + '</p>') + '</div></section>';
  }

  async function focusWidget() {
    await loadState().catch(() => null);
    const s = FS.session, st = FS.stats || {};
    const head = '<div class="head"><h2>' + ic('focus', 'sm') + ' ' + esc(F('widget_title')) + '</h2><button class="btn ghost small" onclick="loadView(\'focus\')">' + esc(F('open')) + ic('chev', 'sm flip') + '</button></div>';
    if (s) return '<section class="card focus-widget">' + head + '<div class="fw-now"><b>' + esc(clock(remaining(s))) + '</b><span>' + esc(s.task ? s.task.title : F('free_focus')) + '</span></div></section>';
    return '<section class="card focus-widget">' + head + '<div class="fw-stat"><b>' + esc(st.today_minutes ? mins(st.today_minutes) : '—') + '</b><span class="muted small">' + esc(st.today_minutes ? num(st.today_sessions) + ' ' + F('sessions') : F('widget_none')) + '</span></div>'
      + '<button class="btn primary small" onclick="loadView(\'focus\')">' + ic('play') + esc(F('start')) + '</button></section>';
  }

  // Add the widgets to the "Today" page after it renders.
  const baseToday = window.today;
  if (typeof baseToday === 'function') {
    window.today = async function () {
      const widgets = Promise.all([habitWidget(), focusWidget()]);
      await baseToday.apply(this, arguments);
      const [hw, fw] = await widgets;
      if (view !== 'today') return;
      const cols = document.querySelectorAll('#content .home-grid > div');
      if (cols.length === 2) { cols[1].insertAdjacentHTML('afterbegin', fw + hw); }
      else { $('content').insertAdjacentHTML('beforeend', '<div class="home-grid"><div style="display:grid;gap:16px">' + hw + '</div><div>' + fw + '</div></div>'); }
    };
  }
})();
