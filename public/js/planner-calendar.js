/* Haman Planner — time-blocking calendar and recurring tasks.
 * Loaded after the dashboard's inline script and reuses its helpers
 * (api, esc, t, I18N, TZ, LOCALE, INTL, tzParts, fromInput, toast, fail, modal, closeModal, cacheAll, options, empty, num).
 * All times are shown in the user's timezone (TZ); the server stores instants.
 */
(function () {
  'use strict';
  const SNAP = 15;           // minutes
  const HOUR_PX = 48;        // height of one hour row
  const CAL = { anchor: null, mode: null, data: null, drag: null };

  const pad = n => String(n).padStart(2, '0');
  const digits = s => LOCALE === 'fa' ? String(s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);
  const localDate = d => { const p = tzParts(d); return p.year + '-' + p.month + '-' + p.day; };
  const localMinutes = d => { const p = tzParts(d); return (+p.hour) * 60 + (+p.minute); };
  const isoAt = (dateStr, minutes) => fromInput(dateStr + 'T' + pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60));
  const addDays = (dateStr, n) => { const d = new Date(dateStr + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const dur = m => { m = Math.max(0, Math.round(m)); const h = Math.floor(m / 60), r = m % 60; return h && r ? t('dur_hm', { h: num(h), m: num(r) }) : h ? t('dur_h', { h: num(h) }) : t('dur_m', { m: num(r) }); };
  const isMobile = () => window.matchMedia('(max-width: 720px)').matches;

  function weekStart(dateStr) {
    const dow = new Date(dateStr + 'T12:00:00Z').getUTCDay();              // 0 = Sunday
    const shift = LOCALE === 'fa' ? (dow + 1) % 7 : (dow + 6) % 7;        // fa weeks start Saturday
    return addDays(dateStr, -shift);
  }

  // ------------------------------------------------------------------ data

  async function load() {
    const days = CAL.mode === 'day' ? 1 : 7;
    const from = CAL.mode === 'day' ? CAL.anchor : weekStart(CAL.anchor);
    CAL.from = from;
    CAL.days = Array.from({ length: days }, (_, i) => addDays(from, i));
    CAL.data = await api('/schedule?from=' + from + '&to=' + CAL.days[days - 1]);
    render();
  }

  window.calendarView = async function () {
    CAL.anchor = CAL.anchor || localDate(new Date());
    CAL.mode = CAL.mode || (isMobile() ? 'day' : 'week');
    await load();
  };

  // ------------------------------------------------------------------ rendering

  function hourRange() {
    let min = 8 * 60, max = 20 * 60;
    for (const d of CAL.data.days) {
      if (d.work_start) { min = Math.min(min, localMinutes(new Date(d.work_start)) - 60); max = Math.max(max, localMinutes(new Date(d.work_end)) + 60); }
    }
    for (const it of CAL.data.items.concat(CAL.data.busy)) {
      const s = localMinutes(new Date(it.starts_at)), e = localMinutes(new Date(it.ends_at));
      if (localDate(new Date(it.starts_at)) === localDate(new Date(it.ends_at))) { min = Math.min(min, s); max = Math.max(max, e); }
    }
    return [Math.max(0, Math.floor(min / 60) * 60), Math.min(24 * 60, Math.ceil(max / 60) * 60)];
  }

  function render() {
    const [h0, h1] = hourRange();
    CAL.h0 = h0; CAL.h1 = h1;
    const conflicted = new Set(CAL.data.conflicts.flat());
    const title = CAL.days.length === 1
      ? new Date(CAL.days[0] + 'T12:00:00Z').toLocaleDateString(INTL, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })
      : new Date(CAL.days[0] + 'T12:00:00Z').toLocaleDateString(INTL, { day: 'numeric', month: 'short', timeZone: 'UTC' }) + ' – ' + new Date(CAL.days[CAL.days.length - 1] + 'T12:00:00Z').toLocaleDateString(INTL, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

    let html = '<div class="card calwrap"><div class="head"><div class="actions">'
      + '<button class="btn small" data-cal="prev" aria-label="' + esc(t('cal_prev')) + '">' + (document.dir === 'rtl' ? '→' : '←') + '</button>'
      + '<button class="btn small" data-cal="today">' + esc(t('cal_today')) + '</button>'
      + '<button class="btn small" data-cal="next" aria-label="' + esc(t('cal_next')) + '">' + (document.dir === 'rtl' ? '←' : '→') + '</button>'
      + '<b style="margin-inline-start:8px">' + esc(title) + '</b></div><div class="actions">'
      + '<button class="btn small' + (CAL.mode === 'day' ? ' primary' : '') + '" data-cal="day">' + esc(t('cal_day_view')) + '</button>'
      + '<button class="btn small' + (CAL.mode === 'week' ? ' primary' : '') + '" data-cal="week">' + esc(t('cal_week_view')) + '</button>'
      + '<button class="btn small" data-cal="block">＋ ' + esc(t('cal_add_block')) + '</button>'
      + (typeof window.openPlanner === 'function' ? '<button class="btn small primary" data-cal="plan">✦ ' + esc(t('ai_plan_' + (CAL.mode === 'day' ? 'day' : 'week'))) + '</button>' : '')
      + '</div></div>';

    // capacity strip
    html += '<div class="calgrid" style="--cols:' + CAL.days.length + '"><div></div>' + CAL.days.map((d, i) => {
      const c = CAL.data.days[i] || {};
      const pct = c.usable_minutes ? Math.min(100, Math.round(c.scheduled_minutes / c.usable_minutes * 100)) : (c.scheduled_minutes ? 100 : 0);
      const cls = c.warning ? (c.warning.level === 'over' ? 'over' : 'warn') : '';
      return '<div class="calhead' + (d === localDate(new Date()) ? ' today' : '') + '"><b>' + esc(new Date(d + 'T12:00:00Z').toLocaleDateString(INTL, { weekday: 'short', day: 'numeric', timeZone: 'UTC' })) + '</b>'
        + (c.working ? '<div class="cap ' + cls + '" title="' + esc(t('cal_capacity', { scheduled: dur(c.scheduled_minutes), usable: dur(c.usable_minutes), available: dur(c.available_minutes) })) + '"><i style="width:' + pct + '%"></i></div>'
          + '<div class="muted capt">' + esc(dur(c.scheduled_minutes)) + ' / ' + esc(dur(c.usable_minutes)) + '</div>' : '<div class="muted capt">' + esc(t('cal_nonworking')) + '</div>')
        + (c.warning ? '<div class="calwarn ' + cls + '">' + esc(c.warning.text) + '</div>' : '') + '</div>';
    }).join('') + '</div>';

    // time grid
    const rows = (h1 - h0) / 60;
    html += '<div class="calgrid calbody" style="--cols:' + CAL.days.length + ';height:' + (rows * HOUR_PX) + 'px"><div class="calhours">'
      + Array.from({ length: rows }, (_, i) => '<div style="height:' + HOUR_PX + 'px">' + esc(digits(pad((h0 / 60) + i) + ':00')) + '</div>').join('') + '</div>'
      + CAL.days.map((d, i) => {
        const c = CAL.data.days[i] || {};
        let col = '<div class="calcol" data-date="' + d + '">';
        if (c.work_start) {
          const s = localMinutes(new Date(c.work_start)), e = localMinutes(new Date(c.work_end));
          col += '<div class="calwork" style="top:' + ((s - h0) / 60 * HOUR_PX) + 'px;height:' + ((e - s) / 60 * HOUR_PX) + 'px"></div>';
        }
        for (const ev of CAL.data.busy) col += itemHtml(ev, d, true, conflicted);
        for (const it of CAL.data.items) col += itemHtml(it, d, false, conflicted);
        return col + '</div>';
      }).join('') + '</div>';

    // unscheduled tasks
    html += '<div class="section"><div class="head"><h2>' + esc(t('cal_unscheduled')) + '</h2><span class="muted">' + esc(t('cal_drag_hint')) + '</span></div><div class="unsched">'
      + (CAL.data.unscheduled.map(x => '<div class="uitem" draggable="true" data-task="' + x.id + '" data-min="' + (x.estimated_minutes || CAL.data.settings.default_task_minutes) + '">'
        + (x.recurring_task_id ? '🔁 ' : '') + esc(x.title) + ' <span class="pill">' + esc((I18N.prio[x.priority] || x.priority || '').split(' ')[0]) + '</span>'
        + '<button class="btn small" data-quick="' + x.id + '">' + esc(t('cal_place_next')) + '</button></div>').join('') || empty()) + '</div></div></div>';

    $('content').innerHTML = html;
    bind();
  }

  function itemHtml(it, dateStr, busy, conflicted) {
    const s = new Date(it.starts_at), e = new Date(it.ends_at);
    const sd = localDate(s), ed = localDate(e);
    if (dateStr < sd || dateStr > ed) return '';
    let top = sd === dateStr ? localMinutes(s) : 0;
    let bottom = ed === dateStr ? localMinutes(e) : 24 * 60;
    if (busy && it.all_day) { top = CAL.h0; bottom = CAL.h0 + 30; }
    top = Math.max(top, CAL.h0); bottom = Math.min(bottom, CAL.h1);
    if (bottom <= top) return '';
    const key = (it.type || 'event') + ':' + it.id;
    const cls = busy ? 'busy' : ('k-' + (it.kind || 'task') + (it.status === 'completed' ? ' done' : '') + (conflicted.has(key) ? ' conflict' : ''));
    const label = busy ? (it.title || t('cal_busy')) : ((it.recurring ? '🔁 ' : '') + (it.is_fixed ? '📌 ' : '') + (it.title || t('cal_kind_' + (it.kind || 'focus'))));
    const time = new Date(it.starts_at).toLocaleTimeString(INTL, { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
    return '<div class="calitem ' + cls + '" style="top:' + ((top - CAL.h0) / 60 * HOUR_PX) + 'px;height:' + Math.max(18, (bottom - top) / 60 * HOUR_PX - 2) + 'px"'
      + (busy ? '' : ' data-type="' + it.type + '" data-id="' + it.id + '" data-task="' + (it.task_id || '') + '" tabindex="0"')
      + ' title="' + esc(label + ' · ' + time) + '"><span class="ct">' + esc(time) + '</span> ' + esc(label)
      + (busy ? '' : '<span class="rz" aria-hidden="true"></span>') + '</div>';
  }

  // ------------------------------------------------------------------ interaction

  function bind() {
    document.querySelectorAll('[data-cal]').forEach(b => b.onclick = () => {
      const a = b.dataset.cal;
      if (a === 'prev' || a === 'next') { CAL.anchor = addDays(CAL.anchor, (a === 'prev' ? -1 : 1) * (CAL.mode === 'day' ? 1 : 7)); return load(); }
      if (a === 'today') { CAL.anchor = localDate(new Date()); return load(); }
      if (a === 'day' || a === 'week') { CAL.mode = a; return load(); }
      if (a === 'block') return blockForm();
      if (a === 'plan') return window.openPlanner(CAL.mode === 'day' ? 'day' : 'week');
    });
    document.querySelectorAll('.uitem').forEach(el => el.ondragstart = ev => ev.dataTransfer.setData('text/plain', JSON.stringify({ task: +el.dataset.task, min: +el.dataset.min })));
    document.querySelectorAll('[data-quick]').forEach(b => b.onclick = ev => { ev.stopPropagation(); quickPlace(+b.dataset.quick); });
    document.querySelectorAll('.calcol').forEach(col => {
      col.ondragover = ev => ev.preventDefault();
      col.ondrop = ev => {
        ev.preventDefault();
        let d; try { d = JSON.parse(ev.dataTransfer.getData('text/plain')); } catch { return; }
        const start = minutesAt(col, ev.clientY);
        place({ task_id: d.task, starts_at: isoAt(col.dataset.date, start), ends_at: isoAt(col.dataset.date, Math.min(24 * 60, start + d.min)) });
      };
    });
    document.querySelectorAll('.calitem[data-id]').forEach(el => {
      el.onpointerdown = ev => startDrag(ev, el);
      el.onkeydown = ev => { if (ev.key === 'Enter') itemMenu(el); };
    });
  }

  function minutesAt(col, clientY) {
    const r = col.getBoundingClientRect();
    const m = CAL.h0 + (clientY - r.top) / HOUR_PX * 60;
    return Math.max(CAL.h0, Math.min(CAL.h1 - SNAP, Math.round(m / SNAP) * SNAP));
  }

  function startDrag(ev, el) {
    if (ev.button !== 0) return;
    const resize = ev.target.classList.contains('rz');
    const col = el.parentElement;
    const top0 = parseFloat(el.style.top), h0 = parseFloat(el.style.height);
    CAL.drag = { el, resize, col, y: ev.clientY, x: ev.clientX, top0, h0, moved: false };
    el.setPointerCapture(ev.pointerId);
    el.onpointermove = e => {
      const dy = e.clientY - CAL.drag.y;
      if (Math.abs(dy) > 3 || Math.abs(e.clientX - CAL.drag.x) > 3) CAL.drag.moved = true;
      const snap = SNAP / 60 * HOUR_PX;
      if (resize) el.style.height = Math.max(snap, Math.round((h0 + dy) / snap) * snap) + 'px';
      else {
        el.style.top = Math.max(0, Math.round((top0 + dy) / snap) * snap) + 'px';
        const over = document.elementsFromPoint(e.clientX, e.clientY).find(n => n.classList && n.classList.contains('calcol'));
        if (over && over !== el.parentElement) over.appendChild(el);
      }
    };
    el.onpointerup = () => {
      el.onpointermove = el.onpointerup = null;
      const d = CAL.drag; CAL.drag = null;
      if (!d.moved) return itemMenu(el);
      const date = el.parentElement.dataset.date;
      const s = CAL.h0 + parseFloat(el.style.top) / HOUR_PX * 60, len = (parseFloat(el.style.height) + 2) / HOUR_PX * 60;
      const start = Math.round(s / SNAP) * SNAP, end = Math.round((s + len) / SNAP) * SNAP;
      move(el.dataset.type, +el.dataset.id, isoAt(date, start), isoAt(date, Math.min(24 * 60, end)));
    };
  }

  async function withConfirm(fn, body) {
    try { return await fn(body); }
    catch (e) {
      if (e.status !== 409) { fail(e); return null; }
      const list = (e.conflicts || []).map(c => '• ' + (c.title || t('cal_busy'))).join('\n');
      if (!confirm(e.message + (list ? '\n' + list : '') + '\n\n' + t('cal_conflict_confirm'))) return null;
      try { return await fn(Object.assign({}, body, { force: true })); } catch (x) { fail(x); return null; }
    }
  }

  async function send(path, method, body) {
    const r = await fetch('/api' + path, { method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
    const x = r.status === 204 ? null : await r.json().catch(() => ({}));
    if (!r.ok) { const e = Error((x && x.message) || t('error')); e.status = r.status; e.conflicts = x && x.conflicts; e.upgrade = x && x.upgrade_url; throw e; }
    return x;
  }

  async function place(body) {
    const r = await withConfirm(b => send('/schedule/place', 'POST', b), body);
    if (r) toast(t('cal_saved'));
    load();
  }

  async function move(type, id, starts_at, ends_at) {
    const r = await withConfirm(b => send('/schedule/' + type + '/' + id, 'PATCH', b), { starts_at, ends_at });
    if (r) toast(t('cal_saved'));
    load();
  }

  /** Next free slot today (or the first working day) that fits the task. */
  function quickPlace(taskId) {
    const task = CAL.data.unscheduled.find(x => x.id === taskId); if (!task) return;
    const len = task.estimated_minutes || CAL.data.settings.default_task_minutes;
    const now = localDate(new Date()), nowMin = Math.ceil(localMinutes(new Date()) / SNAP) * SNAP;
    for (let i = 0; i < CAL.days.length; i++) {
      const d = CAL.days[i], c = CAL.data.days[i];
      if (!c || !c.work_start || d < now) continue;
      let s = Math.max(localMinutes(new Date(c.work_start)), d === now ? nowMin : 0);
      const endW = localMinutes(new Date(c.work_end));
      const busy = CAL.data.items.concat(CAL.data.busy).filter(x => localDate(new Date(x.starts_at)) === d && (x.kind === undefined || x.kind === 'task' || x.kind === 'focus'))
        .map(x => [localMinutes(new Date(x.starts_at)), localMinutes(new Date(x.ends_at))]).sort((a, b) => a[0] - b[0]);
      for (const [bs, be] of busy) { if (s + len <= bs) break; if (be > s) s = Math.ceil(be / SNAP) * SNAP; }
      if (s + len <= endW) return place({ task_id: taskId, starts_at: isoAt(d, s), ends_at: isoAt(d, s + len) });
    }
    toast(t('cal_no_slot'));
  }

  function itemMenu(el) {
    const type = el.dataset.type, id = +el.dataset.id, taskId = +el.dataset.task || null;
    const it = CAL.data.items.find(x => x.type === type && x.id === id); if (!it) return;
    modal(it.title || t('cal_kind_' + it.kind),
      '<p class="muted">' + esc(fmtDT(it.starts_at)) + ' → ' + esc(new Date(it.ends_at).toLocaleTimeString(INTL, { timeZone: TZ, hour: '2-digit', minute: '2-digit' })) + '</p>'
      + (it.recurring ? '<p class="muted">🔁 ' + esc(t('occurrence_label')) + '</p>' : '')
      + '<div class="modalactions">'
      + (taskId ? '<button class="btn" id=cm-open>' + esc(t('cal_open_task')) + '</button>' : '')
      + (taskId ? '<button class="btn" id=cm-done>✓ ' + esc(t('cal_mark_done')) + '</button>' : '')
      + (it.recurring ? '<button class="btn" id=cm-skip>' + esc(t('skip_occurrence')) + '</button>' : '')
      + (taskId ? '<button class="btn danger" id=cm-unsched>' + esc(t('cal_unschedule')) + '</button>' : '<button class="btn danger" id=cm-del>' + esc(t('delete')) + '</button>')
      + '</div>');
    const on = (sel, fn) => { const b = $(sel); if (b) b.onclick = async () => { try { await fn(); closeModal(); load(); } catch (e) { fail(e); } }; };
    const openBtn = $('cm-open'); if (openBtn) openBtn.onclick = () => { closeModal(); editItem(taskId, 'task'); };
    on('cm-done', () => api('/tasks/' + taskId, { method: 'PUT', body: JSON.stringify({ status: 'completed' }) }));
    on('cm-skip', () => api('/tasks/' + taskId + '/skip', { method: 'POST' }));
    on('cm-unsched', () => api('/tasks/' + taskId + '/unschedule', { method: 'POST' }));
    on('cm-del', () => api('/schedule-blocks/' + id, { method: 'DELETE' }));
  }

  function blockForm() {
    const d = CAL.days.includes(localDate(new Date())) ? localDate(new Date()) : CAL.days[0];
    modal(t('cal_add_block'), '<form id=f><div class=formgrid>'
      + '<div class=field><label>' + esc(t('cal_kind')) + '</label><select name=kind>' + ['focus', 'break', 'buffer'].map(k => '<option value="' + k + '">' + esc(t('cal_kind_' + k)) + '</option>').join('') + '</select></div>'
      + '<div class=field><label>' + esc(I18N.fields.title || 'Title') + '</label><input name=title maxlength=255></div>'
      + '<div class=field><label>' + esc(I18N.fields.starts_at) + '</label><input name=starts_at type=datetime-local required value="' + d + 'T12:00"></div>'
      + '<div class=field><label>' + esc(I18N.fields.ends_at) + '</label><input name=ends_at type=datetime-local required value="' + d + 'T13:00"></div>'
      + '<label class=field><input type=checkbox name=is_fixed value=1> ' + esc(t('cal_fixed')) + '</label>'
      + '</div><div class=modalactions><button class="btn primary">' + esc(t('save')) + '</button></div></form>');
    $('f').onsubmit = async e => {
      e.preventDefault();
      const f = e.target;
      closeModal();
      await place({ kind: f.kind.value, title: f.title.value || null, starts_at: fromInput(f.starts_at.value), ends_at: fromInput(f.ends_at.value), is_fixed: f.is_fixed.checked });
    };
  }

  window.calendarAddBlock = () => blockForm();

  // ------------------------------------------------------------------ recurring tasks

  const WEEK = LOCALE === 'fa' ? [6, 7, 1, 2, 3, 4, 5] : [1, 2, 3, 4, 5, 6, 7];

  window.recurringView = async function () {
    const r = await api('/recurring-tasks'), a = r.data || [];
    $('content').innerHTML = '<div class=card><div class=head><h2>' + esc(I18N.nav.recurring || t('recurring')) + '</h2><button class="btn primary" onclick="recurringForm()">＋ ' + esc(t('recurring_new')) + '</button></div>'
      + '<p class=muted>' + esc(t('recurring_help')) + '</p><div class=list>'
      + (a.map(x => '<div class=row><div class=rowmain><div class=rowtitle>🔁 ' + esc(x.title) + '</div><div class=rowsub>' + esc(x.rule_label)
        + (x.next_date ? ' · ' + esc(t('recurring_next', { date: fmt(x.next_date + 'T12:00:00Z') })) : '') + '</div></div><div class=rowactions>'
        + '<span class="pill ' + (x.status === 'active' ? 'ok' : '') + '">' + esc(t('recurring_status_' + x.status)) + '</span>'
        + (x.status === 'active' ? '<button class="btn small" onclick="recurringForm(' + x.id + ')">' + esc(t('recurring_edit_future')) + '</button><button class="btn small danger" onclick="recurringStop(' + x.id + ')">' + esc(t('recurring_stop')) + '</button>' : '')
        + '</div></div>').join('') || empty()) + '</div></div>';
  };

  window.recurringStop = async function (id) {
    if (!confirm(t('recurring_stop_confirm'))) return;
    try { await api('/recurring-tasks/' + id + '/stop', { method: 'POST' }); toast(t('saved')); recurringView(); } catch (e) { fail(e); }
  };

  window.recurringForm = async function (id) {
    await cacheAll();
    const x = id ? await api('/recurring-tasks/' + id) : { frequency: 'weekly', interval: 1, by_weekday: [], calendar: LOCALE === 'fa' ? 'jalali' : 'gregorian', priority: 'p2' };
    const fld = (n, lbl, input) => '<div class=field><label>' + esc(lbl) + '</label>' + input + '</div>';
    const sel = (n, map, v) => '<select name=' + n + '>' + Object.entries(map).map(([k, l]) => '<option value="' + k + '"' + (String(v) === k ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select>';
    modal(id ? t('recurring_edit_future') : t('recurring_new'), '<form id=f><div class=formgrid>'
      + '<div class="field full"><label>' + esc(I18N.fields.title) + '</label><input name=title required maxlength=255 value="' + esc(x.title || '') + '"></div>'
      + fld('frequency', t('recurring_frequency'), sel('frequency', I18N.recur.frequency, x.frequency))
      + fld('interval', t('recurring_interval'), '<input name=interval type=number min=1 max=365 value="' + (x.interval || 1) + '">')
      + '<div class="field full" data-show="weekly"><label>' + esc(t('recurring_weekdays')) + ' <button type=button class="btn small" id=wd-work>' + esc(t('recurring_workdays')) + '</button></label><div class=wd>'
      + WEEK.map(d => '<label><input type=checkbox name=by_weekday value=' + d + ((x.by_weekday || []).includes(d) ? ' checked' : '') + '> ' + esc(I18N.recur.weekdays[d - 1]) + '</label>').join('') + '</div></div>'
      + '<div class=field data-show="monthly yearly"><label>' + esc(t('recurring_calendar')) + '</label>' + sel('calendar', I18N.recur.calendar, x.calendar || 'gregorian') + '</div>'
      + '<div class=field data-show="monthly yearly"><label>' + esc(t('recurring_month_day')) + '</label><input name=by_month_day type=number min=1 max=31 value="' + (x.by_month_day || '') + '"></div>'
      + '<div class=field data-show="yearly"><label>' + esc(t('recurring_month')) + '</label><input name=by_month type=number min=1 max=12 value="' + (x.by_month || '') + '"></div>'
      + fld('starts_on', t('recurring_starts_on'), '<input name=starts_on type=date value="' + esc((x.starts_on || '').slice(0, 10)) + '">')
      + fld('ends_on', t('recurring_ends_on'), '<input name=ends_on type=date value="' + esc((x.ends_on || '').slice(0, 10)) + '">')
      + fld('max_occurrences', t('recurring_max'), '<input name=max_occurrences type=number min=1 max=5000 value="' + (x.max_occurrences || '') + '">')
      + fld('time_of_day', t('recurring_time'), '<input name=time_of_day type=time dir=ltr value="' + esc(x.time_of_day || '') + '">')
      + fld('estimated_minutes', I18N.fields.estimated_minutes, '<input name=estimated_minutes type=number min=0 max=1440 value="' + (x.estimated_minutes ?? '') + '">')
      + fld('priority', I18N.fields.priority, sel('priority', I18N.prio, x.priority || 'p2'))
      + fld('goal_id', entityName('goal'), options('goal').replace('__F', 'goal_id'))
      + fld('project_id', entityName('project'), options('project').replace('__F', 'project_id'))
      + '<div class="field full muted" id=rpreview></div>'
      + '</div>' + (id ? '<p class=muted>' + esc(t('recurring_future_note')) + '</p>' : '') + '<div class=modalactions><button type=button class=btn onclick=closeModal()>' + esc(t('cancel')) + '</button><button class="btn primary">' + esc(t('save')) + '</button></div></form>');
    const f = $('f');
    if (x.goal_id) f.goal_id.value = x.goal_id;
    if (x.project_id) f.project_id.value = x.project_id;
    const gather = () => {
      const d = {};
      for (const n of ['title', 'frequency', 'interval', 'calendar', 'by_month_day', 'by_month', 'starts_on', 'ends_on', 'max_occurrences', 'time_of_day', 'estimated_minutes', 'priority', 'goal_id', 'project_id']) {
        const v = f[n] ? f[n].value : ''; d[n] = v === '' ? null : v;
      }
      d.by_weekday = Array.from(f.querySelectorAll('[name=by_weekday]:checked')).map(c => +c.value);
      if (d.frequency !== 'weekly') d.by_weekday = null;
      if (!['monthly', 'yearly'].includes(d.frequency)) { d.by_month_day = null; d.calendar = 'gregorian'; }
      if (d.frequency !== 'yearly') d.by_month = null;
      return d;
    };
    const sync = () => {
      const fr = f.frequency.value;
      f.querySelectorAll('[data-show]').forEach(el => el.style.display = el.dataset.show.split(' ').includes(fr) ? '' : 'none');
      clearTimeout(sync.t);
      sync.t = setTimeout(async () => {
        try { const p = await api('/recurring-tasks/preview', { method: 'POST', body: JSON.stringify(Object.assign(gather(), { title: gather().title || '—' })) }); $('rpreview').textContent = t('recurring_preview') + ' ' + p.dates.map(dd => fmt(dd + 'T12:00:00Z')).join(LOCALE === 'fa' ? '، ' : ', '); }
        catch { $('rpreview').textContent = ''; }
      }, 300);
    };
    f.oninput = sync; sync();
    $('wd-work').onclick = () => { const w = I18N.workDays || []; f.querySelectorAll('[name=by_weekday]').forEach(c => c.checked = w.includes(+c.value)); sync(); };
    f.onsubmit = async e => {
      e.preventDefault();
      try {
        await api('/recurring-tasks' + (id ? '/' + id : ''), { method: id ? 'PUT' : 'POST', body: JSON.stringify(gather()) });
        closeModal(); toast(t('saved')); recurringView();
      } catch (z) { fail(z); }
    };
  };

  // styles (kept here so the calendar is self-contained)
  const css = document.createElement('style');
  css.textContent = '.calwrap{overflow:hidden}.calgrid{display:grid;grid-template-columns:52px repeat(var(--cols),minmax(0,1fr));gap:0 6px}'
    + '.calhead{padding:6px 4px;border-bottom:1px solid var(--line);font-size:12px}.calhead.today b{color:var(--blue)}'
    + '.cap{height:6px;border-radius:9px;background:#edf0f4;overflow:hidden;margin-top:6px}.cap i{display:block;height:100%;background:var(--ok)}.cap.warn i{background:var(--warn)}.cap.over i{background:var(--danger)}'
    + '.capt{font-size:10px;margin-top:3px}.calwarn{font-size:10px;line-height:1.5;margin-top:4px;color:var(--warn)}.calwarn.over{color:var(--danger)}'
    + '.calbody{position:relative;margin-top:6px;overflow:hidden}.calhours div{font-size:10px;color:var(--muted);border-top:1px solid var(--line);box-sizing:border-box;padding-top:2px}'
    + '.calcol{position:relative;background:#f1f3f7;border-radius:8px}'
    + '.calcol::after{content:"";position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(#dfe4ec 1px,transparent 1px);background-size:100% ' + HOUR_PX + 'px}'
    + '.calwork{position:absolute;inset-inline:0;background:#fff;border-radius:6px}.calitem{z-index:1}'
    + '.calitem{position:absolute;inset-inline:3px;border-radius:7px;padding:3px 6px;font-size:11px;line-height:1.35;overflow:hidden;cursor:grab;touch-action:none;background:#e8eefc;border:1px solid #c9d6fb;user-select:none}'
    + '.calitem .ct{font-weight:800;font-size:10px}.calitem.k-focus{background:#efe8ff;border-color:#d8c8fb}.calitem.k-break{background:#e9f7ef;border-color:#c5ead3}.calitem.k-buffer{background:#fff6e0;border-color:#f0dca8}'
    + '.calitem.done{opacity:.55;text-decoration:line-through}.calitem.conflict{border:2px solid var(--danger)}'
    + '.calitem.busy{background:repeating-linear-gradient(45deg,#eceff4,#eceff4 6px,#e3e7ee 6px,#e3e7ee 12px);border-color:#d5dae3;color:#5b6475;cursor:default}'
    + '.calitem .rz{position:absolute;inset-inline:0;bottom:0;height:7px;cursor:ns-resize}'
    + '.unsched{display:flex;flex-wrap:wrap;gap:7px}.uitem{border:1px solid var(--line);background:#fff;border-radius:10px;padding:7px 9px;font-size:12px;cursor:grab;display:flex;gap:6px;align-items:center}'
    + '.wd{display:flex;flex-wrap:wrap;gap:6px 12px}.wd label{font-weight:500}'
    + '@media(max-width:720px){.calgrid{grid-template-columns:40px repeat(var(--cols),minmax(0,1fr))}}';
  document.head.appendChild(css);
})();
