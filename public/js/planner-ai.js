/* Haman Planner — Haman AI planning UI (proposals, what-now, weekly review, insights).
 * Nothing is changed until the user presses "Apply selected". Reuses dashboard helpers.
 */
(function () {
  'use strict';
  const T = (k, r) => t(k, r);

  // ------------------------------------------------------------------ proposals

  window.openPlanner = async function (kind, message) {
    if (view !== 'ai-planner') { document.querySelectorAll('[data-view]').forEach(x => x.classList.toggle('active', x.dataset.view === 'ai-planner')); view = 'ai-planner'; $('title').textContent = I18N.nav.ai_planner; await plannerHome(); }
    const out = $('planout');
    out.innerHTML = '<div class=card><div class=skeleton><i></i><i></i><i></i></div><p class="muted small" style="margin:10px 0 0">' + esc(T('ai_thinking')) + '</p></div>';
    try {
      const body = message ? { message } : { kind };
      const r = await api('/planning/proposals', { method: 'POST', body: JSON.stringify(body) });
      out.innerHTML = r.kind === 'now' ? whatNowHtml(r) : proposalHtml(r);
      if (r.kind !== 'now') bindProposal(r);
    } catch (e) { out.innerHTML = '<div class=empty>' + esc(e.message) + '</div>'; fail(e); }
  };

  function proposalHtml(p) {
    const m = p.metrics_text || {};
    let h = '<div class=card><div class=head><h2>' + esc(p.headline) + '</h2><span class=muted>' + esc(fmt(p.period_start + 'T12:00:00Z')) + (p.period_end !== p.period_start ? ' – ' + esc(fmt(p.period_end + 'T12:00:00Z')) : '') + '</span></div>'
      + '<div class=statgrid><div><div class=muted>' + esc(T('plan_usable')) + '</div><strong>' + esc(m.usable) + '</strong></div><div><div class=muted>' + esc(T('plan_scheduled')) + '</div><strong>' + esc(m.scheduled) + '</strong></div>'
      + '<div><div class=muted>' + esc(T('plan_overload')) + '</div><strong>' + esc(m.overload) + '</strong></div></div>'
      + (p.metrics && p.metrics.history_used ? '<p class=muted>ⓘ ' + esc(T('plan_history_used')) + '</p>' : '');
    if (p.summary) h += '<div class=aisum><b>' + ic('haman', 'sm') + ' ' + esc(T('plan_ai_label')) + '</b><p>' + esc(p.summary) + '</p></div>';
    else if (p.ai_note) h += '<p class=muted>ⓘ ' + esc(p.ai_note) + '</p>';
    (p.ai_warnings || []).forEach(w => h += '<div class="notice">⚠ ' + esc(w) + '</div>');
    if (!p.actions.length) return h + '</div>';
    h += '<div class=list style="margin-top:12px">' + p.actions.map(a => {
      const applied = p.applied_actions && p.applied_actions[a.key];
      return '<label class="row pa' + (a.type === 'at_risk' ? ' risk' : '') + '"><div class=rowmain style="display:flex;gap:10px;align-items:flex-start">'
        + (p.open && a.type !== 'at_risk' ? '<input type=checkbox data-key="' + esc(a.key) + '"' + (a.selected ? ' checked' : '') + '>' : '<span>' + (applied ? (applied.status === 'applied' ? '✓' : '–') : '•') + '</span>')
        + '<div><div class=rowtitle>' + esc(a.text) + '</div><div class=rowsub>' + esc(T('plan_because')) + ' ' + esc((a.reasons_text || []).join(' · ')) + '</div>'
        + (a.ai_note ? '<div class=rowsub>' + ic('haman', 'sm') + ' ' + esc(a.ai_note) + (a.ai_advises_against ? ' <b>(' + esc(T('plan_ai_against')) + ')</b>' : '') + '</div>' : '')
        + (applied && applied.status !== 'applied' ? '<div class=rowsub>' + esc(T('plan_skipped_' + applied.reason) || applied.reason) + '</div>' : '')
        + '</div></div></label>';
    }).join('') + '</div>';
    if (p.open) h += '<div class=modalactions><button class="btn" id=pdismiss>' + esc(T('plan_dismiss')) + '</button><button class="btn primary" id=papply>' + esc(T('plan_apply')) + '</button></div><p class=muted>' + esc(T('plan_confirm_note')) + '</p>';
    else h += '<p class=muted>' + esc(T('plan_status_' + p.status)) + '</p>';
    return h + '</div>';
  }

  function bindProposal(p) {
    const ap = $('papply'), di = $('pdismiss');
    if (ap) ap.onclick = async () => {
      const keys = Array.from(document.querySelectorAll('#planout input[data-key]:checked')).map(c => c.dataset.key);
      if (!keys.length) return toast(T('plan_none_selected'));
      if (!confirm(T('plan_apply_confirm', { n: num(keys.length) }))) return;
      ap.disabled = true;
      try {
        const r = await api('/planning/proposals/' + p.id + '/apply', { method: 'POST', body: JSON.stringify({ actions: keys }) });
        const ok = Object.values(r.results).filter(x => x.status === 'applied').length;
        toast(T('plan_applied', { n: num(ok) }));
        $('planout').innerHTML = proposalHtml(r);
      } catch (e) { fail(e); ap.disabled = false; }
    };
    if (di) di.onclick = async () => { await api('/planning/proposals/' + p.id + '/dismiss', { method: 'POST' }); $('planout').innerHTML = '<div class=empty>' + esc(T('plan_status_dismissed')) + '</div>'; };
  }

  function whatNowHtml(r) {
    let h = '<div class=card><div class=head><h2>' + esc(T('what_now_title')) + '</h2><span class=muted>' + esc(T('what_now_remaining', { time: r.remaining_text })) + '</span></div>';
    if (r.now_scheduled) h += '<div class=aisum><b>◉ ' + esc(T('what_now_scheduled')) + '</b><p>' + esc(r.now_scheduled.title || '') + '</p></div>';
    h += '<div class=list>' + (r.ranked.map((x, i) => '<div class=row><div class=rowmain><div class=rowtitle>' + (i + 1) + '. ' + esc(x.title) + '</div><div class=rowsub>' + esc(T('plan_because')) + ' ' + esc((x.reasons_text || []).join(' · ') || T('what_now_no_reason')) + '</div></div>'
      + '<div class=rowactions>' + pill(x.priority) + '<button class="btn small" onclick="editItem(' + x.id + ',\'task\')">' + esc(t('edit')) + '</button></div></div>').join('') || empty()) + '</div>';
    if (r.blocked.length) h += '<p class=muted>⛓ ' + esc(T('what_now_blocked', { n: num(r.blocked.length) })) + ' ' + esc(r.blocked.map(b => b.title).join('، ')) + '</p>';
    return h + '<p class=muted>' + esc(T('what_now_rules')) + '</p></div>';
  }

  async function plannerHome() {
    const tile = (k, icon) => '<button class="ai-tile" data-plan="' + k + '">' + ic(icon) + '<span><b>' + esc(T(k === 'ask' ? 'ai_ask_advice' : k === 'now' ? 'ai_what_now' : k === 'fix' ? 'ai_fix' : 'ai_plan_' + k)) + '</b><span>' + esc(T('plan_desc_' + k)) + '</span></span></button>';
    $('content').innerHTML = '<div class=card><p class="muted" style="margin:0 0 12px">' + esc(T('plan_intro')) + '</p><div class=ai-tiles>'
      + tile('day', 'today') + tile('week', 'calendar') + tile('next_week', 'arrow') + tile('fix', 'refresh') + tile('now', 'goal') + tile('ask', 'support') + '</div>'
      + '<div class=toolbar style="margin:14px 0 0"><input id=plantext class="input" style="flex:1;min-width:200px" aria-label="' + esc(T('plan_free_placeholder')) + '" placeholder="' + esc(T('plan_free_placeholder')) + '"><button class="btn primary" id=plango>' + esc(T('ai_button')) + '</button></div></div>'
      + '<div id=planout class=section></div>';
    document.querySelectorAll('[data-plan]').forEach(x => x.onclick = () => x.dataset.plan === 'ask' ? legacyAsk() : openPlanner(x.dataset.plan));
    const go = () => { const v = $('plantext').value.trim(); if (v) openPlanner(null, v); };
    $('plango').onclick = go; $('plantext').onkeydown = e => { if (e.key === 'Enter') go(); };
  }

  // The earlier free-form AI recommendations stay available.
  async function legacyAsk() {
    const text = $('plantext').value.trim() || T('ai_placeholder');
    $('planout').innerHTML = '<div class=empty>' + esc(T('ai_thinking')) + '</div>';
    try { const r = await api('/planner/ai-recommendations', { method: 'POST', body: JSON.stringify({ focus: text }) }); $('planout').innerHTML = '<div class=card>' + renderAIResult(r) + '</div>'; }
    catch (e) { $('planout').innerHTML = ''; fail(e); }
  }

  window.aiPlanner = plannerHome;

  // ------------------------------------------------------------------ home ("Today")
  // Answers, in order: what now, what matters today, how the week is going, what needs attention,
  // what Haman recommends. Everything comes from recorded data; AI is never called here.

  const H = k => (I18N.home || {})[k] || k;
  const hm = iso => new Date(iso).toLocaleTimeString(INTL, { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false });
  function greeting() { const h = +tzParts(new Date()).hour; return h < 12 ? H('morning') : h < 17 ? H('afternoon') : H('evening'); }

  window.today = async function () {
    const p = tzParts(new Date()), d = p.year + '-' + p.month + '-' + p.day;
    const [sch, wn, wk] = await Promise.all([
      api('/schedule?from=' + d + '&to=' + d).catch(() => null),
      api('/planning/what-now').catch(() => null),
      api('/planning/week').catch(() => null),
    ]);
    if (view !== 'today') return;
    const day = sch && sch.days && sch.days[0], items = (sch ? sch.items : []).slice().sort((a, b) => a.starts_at < b.starts_at ? -1 : 1);
    const busy = sch ? sch.busy || [] : [], pairs = sch && sch.conflicts || [], conflicts = new Set(pairs.flat());
    const keyOf = i => (i.type || 'event') + ':' + i.id;
    const ranked = wn ? wn.ranked : [], now = Date.now();
    const important = ranked.filter(x => ['p0', 'p1'].includes(x.priority) || (x.deadline && new Date(x.deadline) - now < 864e5)).length;
    const free = day && day.working ? Math.max(0, day.usable_minutes - day.scheduled_minutes) : null;

    // hero
    $('title').textContent = greeting().replace(':name', USER_NAME);
    let h = '<div class=home-hero><div><p>'
      + esc(ranked.length ? T('home_line', { n: num(important || ranked.length) }) : H('nothing_open'))
      + (day && day.working ? ' ' + esc(free > 0 ? T('home_free', { time: dur(free) }) : day.overload_minutes > 0 ? T('home_over', { time: dur(day.overload_minutes) }) : T('home_full')) : ' ' + esc(H('day_off')))
      + '</p></div><div class=actions><button class="btn" onclick="openPlanner(\'day\')">' + ic('haman') + esc(T('ai_plan_day')) + '</button></div></div>';

    // left column
    let L = '';
    const cur = wn && wn.now_scheduled, top = cur ? null : ranked[0];
    if (cur || top) {
      const x = cur || top;
      L += '<section class="card now-card" aria-labelledby=nowh><div class=eyebrow id=nowh>' + esc(cur ? H('now_scheduled') : H('now_next')) + '</div>'
        + '<div class=now-title>' + esc(x.title || '') + '</div><div class=meta>'
        + (cur ? '<span>' + ic('clock', 'sm') + esc(hm(cur.starts_at) + '–' + hm(cur.ends_at)) + '</span>' : '')
        + (!cur && top.deadline ? dueMeta(top.deadline, false) : '') + (!cur && top.estimated_minutes ? '<span>' + ic('timer', 'sm') + esc(dur(top.estimated_minutes)) + '</span>' : '')
        + (!cur && top.reasons_text && top.reasons_text.length ? '<span>' + esc(top.reasons_text[0]) + '</span>' : '') + '</div>'
        + '<div class=actions style="margin-top:12px">' + ((cur ? cur.task_id : top.id) ? '<button class="btn primary" onclick="homeDone(' + (cur ? cur.task_id : top.id) + ',this)">' + ic('check') + esc(H('mark_done')) + '</button><button class=btn onclick="executionForm()">' + ic('timer') + esc(t('log_work')) + '</button>' : '')
        + '<button class="btn ghost" onclick="openPlanner(\'now\')">' + esc(H('why_this')) + '</button></div></section>';
    }
    // schedule
    const all = items.map(i => ({ ...i, src: 'plan' })).concat(busy.map(b => ({ ...b, src: 'busy', kind: 'busy' }))).sort((a, b) => a.starts_at < b.starts_at ? -1 : 1);
    L += '<section class=card aria-labelledby=schh><div class=head><h2 id=schh>' + esc(H('schedule')) + '</h2><button class="btn ghost small" data-go2=calendar onclick="loadView(\'calendar\')">' + esc(H('open_calendar')) + ic('chev', 'sm flip') + '</button></div>';
    if (all.length) {
      L += '<div class=tl>' + all.map(i => {
        const doneI = i.status === 'completed', isNow = new Date(i.starts_at) <= now && new Date(i.ends_at) > now, conf = conflicts.has(keyOf(i));
        return '<div class="tl-item' + (doneI ? ' done' : '') + (isNow ? ' now' : '') + (conf ? ' conflict' : '') + '"><div class=tl-time>' + esc(hm(i.starts_at)) + '</div>'
          + '<div class="tl-bar k-' + esc(i.kind || 'task') + (i.src === 'busy' ? ' busy' : '') + '"><div class=rowtitle>' + esc(i.title || (i.src === 'busy' ? H('busy') : t('focus'))) + '</div><div class=meta><span>' + esc(hm(i.starts_at) + '–' + hm(i.ends_at)) + '</span>'
          + (i.src === 'busy' ? '<span>' + esc(H('from_calendar')) + '</span>' : '') + (conf ? '<span class=late>' + ic('alert', 'sm') + esc(H('conflict')) + '</span>' : '') + '</div></div>'
          + '<div>' + (doneI ? '<span class="pill ok">' + esc(label('completed')) + '</span>' : isNow ? '<span class="pill accent">' + esc(H('now')) + '</span>' : '') + '</div></div>';
      }).join('') + '</div>';
    } else {
      L += '<div class=empty style="padding:20px"><p class=empty-text>' + esc(H('schedule_empty')) + '</p><button class="btn" onclick="openPlanner(\'day\')">' + ic('haman') + esc(T('ai_plan_day')) + '</button></div>';
    }
    if (day && day.working) {
      const pct = day.usable_minutes ? Math.min(100, Math.round(day.scheduled_minutes / day.usable_minutes * 100)) : 0;
      L += '<div class=cap-line><span>' + esc(H('capacity')) + '</span><span class=num>' + esc(T('home_cap', { used: dur(day.scheduled_minutes), total: dur(day.usable_minutes) })) + '</span></div>'
        + '<div class=progress role=progressbar aria-valuenow="' + pct + '" aria-valuemin=0 aria-valuemax=100 aria-label="' + esc(H('capacity')) + '"><i class="' + (day.overload_minutes > 0 ? 'danger' : pct > 90 ? 'warn' : '') + '" style="width:' + pct + '%"></i></div>';
    }
    L += '</section>';
    // next up
    const rest = ranked.slice(cur ? 0 : 1, cur ? 4 : 5);
    if (rest.length) {
      L += '<section class=card aria-labelledby=nexth><div class=head><h2 id=nexth>' + esc(H('next_up')) + '</h2><button class="btn ghost small" onclick="loadView(\'tasks\')">' + esc(H('all_tasks')) + ic('chev', 'sm flip') + '</button></div><div class=list>'
        + rest.map(x => '<div class="row task"><button class=check aria-pressed=false aria-label="' + esc(I18N.row.complete) + '" onclick="toggleDone(' + x.id + ',false,this)"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></button><div class=rowmain><div class=rowtitle>'
          + (['p0', 'p1'].includes(x.priority) ? '<span class="prio ' + x.priority + '" title="' + esc(label(x.priority)) + '">' + esc(String(label(x.priority)).split('—').pop().trim()) + '</span> ' : '') + esc(x.title) + '</div><div class=meta>' + dueMeta(x.deadline, false)
          + (x.estimated_minutes ? '<span>' + ic('timer', 'sm') + esc(dur(x.estimated_minutes)) + '</span>' : '') + '</div></div>'
          + '<div class=rowactions>' + rowMenu([mi('edit', t('edit'), "editItem(" + x.id + ",'task')")]) + '</div></div>').join('') + '</div></section>';
    }

    // right column
    let R = '';
    if (wk) {
      const pct = wk.percent;
      R += '<section class=card aria-labelledby=wkh><div class=head><h2 id=wkh>' + esc(H('week')) + '</h2><span class="muted small">' + esc(fmt(wk.week_start + 'T12:00:00Z')) + ' – ' + esc(fmt(wk.week_end + 'T12:00:00Z')) + '</span></div>'
        + '<div class=week-top><div class=ring style="--p:' + (pct || 0) + '" data-label="' + esc(pct == null ? '—' : num(pct) + '%') + '" role=img aria-label="' + esc(H('week') + ' ' + (pct == null ? '—' : pct + '%')) + '"></div><div><b>' + esc(T('home_week_done', { done: num(wk.tasks_completed), total: num(wk.tasks_total) })) + '</b><div class="muted small">' + esc(pct == null ? H('week_empty') : H('week_hint')) + '</div></div></div>'
        + '<dl class=kv><dt>' + esc(H('planned')) + '</dt><dd>' + esc(wk.planned_text) + '</dd><dt>' + esc(H('actual')) + '</dt><dd>' + esc(wk.actual_text) + '</dd>'
        + '<dt>' + esc(H('overdue')) + '</dt><dd' + (wk.overdue_count ? ' style="color:var(--danger)"' : '') + '>' + esc(num(wk.overdue_count)) + '</dd></dl></section>';
    }
    // Haman recommends: only concrete, data-backed observations
    const recs = [];
    if (day && day.overload_minutes > 0) recs.push({ text: T('rec_over', { time: dur(day.overload_minutes) }), act: 'fix', label: H('review_suggestion') });
    if (pairs.length) recs.push({ text: T('rec_conflicts', { n: num(pairs.length) }), act: 'fix', label: H('review_suggestion') });
    if (wk && wk.due_soon_unscheduled.length) recs.push({ text: T('rec_due_soon', { n: num(wk.due_soon_unscheduled.length), title: wk.due_soon_unscheduled[0].title }), act: 'day', label: T('ai_plan_day') });
    if (wk && wk.overdue_count) recs.push({ text: T('rec_overdue', { n: num(wk.overdue_count) }), act: 'week', label: T('ai_plan_week') });
    if (!recs.length && day && day.working && free > 60 && ranked.length) recs.push({ text: T('rec_free', { time: dur(free) }), act: 'day', label: T('ai_plan_day') });
    if (recs.length) {
      const r = recs[0];
      R += '<section class="card suggest" aria-labelledby=sugh><div class=suggest-label id=sugh>' + ic('haman', 'sm') + esc(H('recommends')) + '</div><p style="margin:0 0 10px">' + esc(r.text) + '</p>'
        + (recs.length > 1 ? '<ul class="muted small" style="margin:0 0 10px">' + recs.slice(1).map(x => '<li>' + esc(x.text) + '</li>').join('') + '</ul>' : '')
        + '<button class="btn small" onclick="openPlanner(\'' + r.act + '\')">' + esc(r.label) + ic('chev', 'sm flip') + '</button><p class="muted small" style="margin:8px 0 0">' + esc(H('recommends_note')) + '</p></section>';
    }
    if (wk && wk.overdue.length) {
      R += '<section class=card aria-labelledby=atth><div class=head><h2 id=atth>' + esc(H('attention')) + '</h2></div><div class=list>'
        + wk.overdue.map(x => '<div class=row><div class=rowmain><div class=rowtitle>' + esc(x.title) + '</div><div class=meta>' + dueMeta(x.deadline, false) + '</div></div><button class="btn ghost small" onclick="editItem(' + x.id + ',\'task\')">' + esc(t('edit')) + '</button></div>').join('') + '</div></section>';
    }
    if (!ranked.length && !all.length) {
      L = '<section class=card>' + empty('today') + '</section>';
    }
    $('content').innerHTML = h + '<div class=home-grid><div style="display:grid;gap:16px">' + L + '</div><div style="display:grid;gap:16px">' + R + '</div></div>';
  };

  window.homeDone = async function (id, btn) {
    btn.classList.add('is-busy');
    try { await api('/tasks/' + id, { method: 'PUT', body: JSON.stringify({ status: 'completed' }) }); toast(t('task_done')); window.today(); }
    catch (e) { btn.classList.remove('is-busy'); fail(e); }
  };

  // ------------------------------------------------------------------ weekly review

  window.reviews = async function () {
    const r = await api('/reviews'), a = r.data || [];
    $('content').innerHTML = '<div class=card><div class=head><h2>' + esc(I18N.nav.reviews) + '</h2><div class=actions><button class="btn primary" id=wkgen>✦ ' + esc(T('weekly_generate')) + '</button><button class="btn" onclick="generateReview()">＋ ' + esc(t('generate_review')) + '</button></div></div>'
      + '<div class=list>' + (a.map(x => '<div class=row><div class=rowmain><div class=rowtitle>' + esc(I18N.reviewType[x.type] || x.type) + ' · ' + esc(t('range', { from: fmt(x.period_start), to: fmt(x.period_end) })) + '</div><div class=rowsub>' + esc(x.summary || '') + '</div></div>'
        + (x.type === 'weekly' && x.metrics_json && x.metrics_json.week_start ? '<button class="btn small" onclick="showWeekly(' + x.id + ')">' + esc(T('weekly_open')) + '</button>' : '') + '</div>').join('') || empty()) + '</div></div><div id=wkout class=section></div>';
    $('wkgen').onclick = async () => {
      $('wkout').innerHTML = '<div class=empty>' + esc(T('ai_thinking')) + '</div>';
      try { const w = await api('/reviews/weekly', { method: 'POST', body: '{}' }); await window.reviews(); $('wkout').innerHTML = weeklyHtml(w); } catch (e) { $('wkout').innerHTML = ''; fail(e); }
    };
  };

  window.showWeekly = async function (id) {
    try { $('wkout').innerHTML = weeklyHtml(await api('/reviews/' + id + '/details')); $('wkout').scrollIntoView({ behavior: 'smooth' }); } catch (e) { fail(e); }
  };

  function weeklyHtml(w) {
    const m = w.metrics || {};
    const cell = (k, v) => '<div><div class=muted>' + esc(T(k)) + '</div><strong>' + esc(v) + '</strong></div>';
    return '<div class=card><div class=head><h2>' + esc(T('weekly_title')) + '</h2><span class=muted>' + esc(t('range', { from: fmt(w.period_start + 'T12:00:00Z'), to: fmt(w.period_end + 'T12:00:00Z') })) + '</span></div>'
      + '<div class=statgrid>' + cell('weekly_completed', num(m.completed)) + cell('weekly_missed', num(m.missed)) + cell('weekly_rescheduled', num(m.rescheduled))
      + cell('weekly_planned', w.metrics_text.planned) + cell('weekly_actual', w.metrics_text.actual) + cell('weekly_skipped', num(m.skipped_occurrences)) + '</div>'
      + '<div class=grid2><div><h3>' + esc(T('weekly_blockers')) + '</h3>' + (w.blockers_text.length ? '<ul>' + w.blockers_text.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '<p class=muted>' + esc(T('weekly_no_blockers')) + '</p>')
      + '<h3>' + esc(T('weekly_projects')) + '</h3>' + ((m.projects_attention || []).length ? '<ul>' + m.projects_attention.map(p => '<li>' + esc(p.title) + ' — ' + esc(T('weekly_project_line', { overdue: num(p.overdue), missed: num(p.missed) })) + '</li>').join('') + '</ul>' : '<p class=muted>—</p>') + '</div>'
      + '<div><h3>' + esc(T('weekly_recs')) + '</h3><ul>' + w.recommendations_text.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>'
      + (w.ai_summary ? '<div class=aisum><b>' + ic('haman', 'sm') + ' ' + esc(T('plan_ai_label')) + '</b><p>' + esc(w.ai_summary) + '</p><p class=muted>' + esc(T('weekly_ai_note')) + '</p></div>' : '') + '</div></div></div>';
  }

  // ------------------------------------------------------------------ analytics: insights

  const origAnalytics = window.analytics;
  window.analytics = async function () {
    await origAnalytics();
    try {
      const r = await api('/planning/insights'), p = r.plan_vs_actual, f = r.failure_patterns, m = p.metrics;
      const box = document.createElement('div'); box.className = 'grid2';
      box.innerHTML = '<div class=card><div class=head><h2>' + esc(T('insights_title')) + '</h2></div>'
        + (r.not_enough_history ? '<p class=muted>' + esc(r.not_enough_history) + '</p>' : '<ul>' + (p.insights_text.map(x => '<li>' + esc(x) + '</li>').join('') || '<li>' + esc(T('insights_none')) + '</li>') + '</ul>')
        + '<div class=statgrid>' + [['insights_estimated', dur(m.estimated_minutes)], ['insights_actual', dur(m.actual_minutes)], ['insights_variance', m.variance_percent == null ? '—' : num(m.variance_percent) + '%'],
          ['insights_deadline', m.deadline_reliability_percent == null ? '—' : num(m.deadline_reliability_percent) + '%'], ['insights_completion', m.completion_rate_percent == null ? '—' : num(m.completion_rate_percent) + '%'],
          ['insights_schedule_var', m.schedule_variance_minutes == null ? '—' : num(m.schedule_variance_minutes) + "'"]].map(([k, v]) => '<div><div class=muted>' + esc(T(k)) + '</div><strong>' + esc(v) + '</strong></div>').join('') + '</div></div>'
        + '<div class=card><div class=head><h2>' + esc(T('insights_failures')) + '</h2></div>'
        + (f.enough_data ? '<ol>' + f.top_reasons.map((x, i) => '<li>' + esc(f.top_reasons_text[i]) + ' — ' + esc(num(x.count)) + ' (' + esc(num(x.share)) + '%)</li>').join('') + '</ol>' : '<p class=muted>' + esc(T('insights_failures_none')) + '</p>')
        + (f.most_common_missed_text ? '<p><b>' + esc(T('insights_missed_common')) + ':</b> ' + esc(f.most_common_missed_text) + '</p>' : '')
        + (f.overloaded_misses ? '<p class=muted>' + esc(T('insights_overloaded_misses', { n: num(f.overloaded_misses) })) + '</p>' : '') + '</div>';
      box.style.marginTop = '13px';
      $('content').appendChild(box);
    } catch (e) { /* insights are optional */ }
  };

  function dur(mins) { mins = +mins || 0; const h = Math.floor(mins / 60), r = mins % 60; return h && r ? T('dur_hm', { h: num(h), m: num(r) }) : h ? T('dur_h', { h: num(h) }) : T('dur_m', { m: num(r) }); }

})();

/* ---------------------------------------------------------------- attachments & activity timeline */
(function () {
  'use strict';
  const T = (k, r) => t(k, r);
  const size = b => b >= 1048576 ? num((b / 1048576).toFixed(1)) + ' MB' : num(Math.max(1, Math.round(b / 1024))) + ' KB';

  window.attachmentsModal = async function (type, id) {
    const render = async () => {
      const r = await api('/' + type + '/' + id + '/attachments'), a = r.data || [];
      $('attlist').innerHTML = a.map(f => '<div class=row><div class=rowmain><div class=rowtitle>' + esc(f.original_name) + '</div><div class=rowsub>' + esc(size(f.size)) + ' · ' + esc(fmtDT(f.created_at)) + '</div></div>'
        + '<div class=rowactions><a class="btn small" href="/api/attachments/' + f.id + '/download">⤓</a><button class="btn small danger" data-del="' + f.id + '">' + esc(t('delete')) + '</button></div></div>').join('') || '<div class=empty>' + esc(T('att_empty')) + '</div>';
      document.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
        if (!confirm(T('att_delete_confirm'))) return;
        try { await api('/attachments/' + b.dataset.del, { method: 'DELETE' }); render(); } catch (e) { fail(e); }
      });
    };
    modal('📎 ' + T('att_title'), '<div class=list id=attlist></div><form id=attform class=modalactions style="justify-content:space-between"><input type=file name=file required><button class="btn primary">' + esc(T('att_upload')) + '</button></form><p class=muted>' + esc(T('att_help')) + '</p>');
    $('attform').onsubmit = async e => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const r = await fetch('/api/' + type + '/' + id + '/attachments', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: fd });
      if (!r.ok) { const x = await r.json().catch(() => ({})); const err = Error(x.message || Object.values(x.errors || {}).flat().join(' ') || t('error')); err.status = r.status; err.upgrade = x.upgrade_url; return fail(err); }
      e.target.reset(); toast(T('att_uploaded')); render();
    };
    render().catch(fail);
  };

  window.activityTimeline = async function (actor, before, append) {
    const r = await api('/activity/timeline?' + (actor ? 'actor=' + actor + '&' : '') + (before ? 'before=' + before : ''));
    const icon = { user: '👤', ai: '✦', system: '⚙' };
    const html = r.groups.map(g => '<h3 class=muted style="margin:16px 0 6px">' + esc(g.label) + '</h3><div class=list>' + g.items.map(i =>
      '<div class="row tl-' + i.actor + '"><div class=rowmain><div class=rowtitle><span class=muted>' + esc(i.time) + '</span> — ' + esc(i.text) + '</div>'
      + '<div class=rowsub>' + icon[i.actor] + ' ' + esc(I18N.activity.actor[i.actor] || i.actor) + (i.channel ? ' · ' + esc(I18N.activity.channel[i.channel] || i.channel) : '') + '</div></div>'
      + (i.entity === 'task' && i.entity_id && i.action !== 'deleted' ? '<button class="btn small" onclick="editItem(' + i.entity_id + ',\'task\')">' + esc(t('edit')) + '</button>' : '') + '</div>').join('') + '</div>').join('');
    if (!append) {
      const f = (k, l) => '<button class="btn small' + ((actor || '') === k ? ' primary' : '') + '" onclick="activityTimeline(\'' + k + '\')">' + esc(l) + '</button>';
      $('content').innerHTML = '<div class=card><div class=head><h2>' + esc(I18N.nav.activity) + '</h2><div class=actions>' + f('', I18N.activity.filter_all) + f('user', I18N.activity.actor.user) + f('ai', 'Haman AI') + f('system', I18N.activity.actor.system) + '</div></div><div id=tl>' + (html || empty()) + '</div><div id=tlmore></div></div>';
    } else {
      $('tl').insertAdjacentHTML('beforeend', html);
    }
    $('tlmore').innerHTML = r.next_before ? '<div class=modalactions><button class=btn onclick="activityTimeline(\'' + (actor || '') + '\',' + r.next_before + ',true)">' + esc(T('more')) + '</button></div>' : '';
  };

  const css = document.createElement('style');
  css.textContent = '.list .row.tl-ai{border-inline-start:3px solid var(--accent)}.tl-system{opacity:.85}';
  document.head.appendChild(css);
})();
