/* Morning planning and evening shutdown wizards (optional, skippable).
 * Every change shown here is applied only when the user presses the final button; nothing is
 * changed on open, on "next" or on "skip". Deadlines are never touched. */
(function () {
  const R = k => (I18N.rituals || {})[k] || k;
  const RT = (k, p) => { let s = R(k); Object.entries(p || {}).forEach(([a, b]) => { s = s.split(':' + a).join(b); }); return s; };
  const mins = m => t('minutes', { count: num(+m || 0) });
  let st = null;

  const meta = x => '<div class=meta>' + dueMeta(x.deadline, x.status === 'completed')
    + (x.project_title ? '<span>' + ic('project', 'sm') + esc(x.project_title) + '</span>' : '')
    + (x.estimated_minutes ? '<span>' + ic('timer', 'sm') + esc(mins(x.estimated_minutes)) + '</span>' : '') + '</div>';

  function scale(name, labels, value) {
    return '<div class=rw-scale role=radiogroup aria-label="' + esc(R(name)) + '">' + labels.map((l, i) =>
      '<label class="rw-chip' + (value === i + 1 ? ' on' : '') + '"><input type=radio name=' + name + ' value=' + (i + 1) + (value === i + 1 ? ' checked' : '')
      + ' onchange="ritualSet(\'' + name + '\',' + (i + 1) + ')"><b>' + num(i + 1) + '</b><span>' + esc(l) + '</span></label>').join('') + '</div>';
  }

  function moveRow(x, actions, withReason) {
    const mv = st.moves[x.id] || { action: 'keep' };
    return '<div class=rw-row><div class=rowmain><div class=rowtitle>' + esc(x.title) + '</div>' + meta(x) + '</div>'
      + '<div class=rw-ctl><div class=seg role=radiogroup aria-label="' + esc(x.title) + '">' + actions.map(a =>
        '<button type=button class="' + (mv.action === a ? 'on' : '') + '" aria-pressed=' + (mv.action === a) + ' onclick="ritualMove(' + x.id + ',\'' + a + '\')">' + esc(R('act_' + a)) + '</button>').join('') + '</div>'
      + (mv.action === 'date' ? '<input type=date class=rw-date min="' + esc(st.data.date) + '" value="' + esc(mv.date || '') + '" onchange="ritualMoveDate(' + x.id + ',this.value)" aria-label="' + esc(R('act_date')) + '">' : '')
      + (withReason ? '<select class=rw-reason onchange="ritualReason(' + x.id + ',this.value)" aria-label="' + esc(R('reason')) + '"><option value="">' + esc(R('reason_none')) + '</option>'
        + st.data.reasons.map(c => '<option value="' + esc(c) + '"' + (mv.reason === c ? ' selected' : '') + '>' + esc(I18N.failure[c] || c) + '</option>').join('') + '</select>' : '')
      + '</div></div>';
  }

  function pickList(rows, hint) {
    if (!rows.length) return '<p class=muted>' + esc(R('no_candidates')) + '</p>';
    return '<div class=rw-picks>' + rows.map(x => {
      const on = st.picks.includes(x.id);
      return '<label class="rw-pick' + (on ? ' on' : '') + '"><input type=checkbox ' + (on ? 'checked' : '') + ' onchange="ritualPick(' + x.id + ',this)">'
        + '<div class=rowmain><div class=rowtitle>' + (['p0', 'p1'].includes(x.priority) ? '<span class="prio ' + x.priority + '">' + esc(String(I18N.prio[x.priority] || '').split('—').pop().trim()) + '</span> ' : '') + esc(x.title)
        + (hint && hint.includes(x.id) ? ' <span class="pill accent">' + esc(R('from_yesterday')) + '</span>' : '') + '</div>' + meta(x) + '</div></label>';
    }).join('') + '</div>';
  }

  const STEPS = {
    morning: [
      { key: 'checkin', html: d => '<p class=muted>' + esc(RT('yesterday_line', { count: num(d.yesterday.completed), time: d.yesterday.logged_text })) + '</p>'
        + '<div class=field><label>' + esc(R('energy')) + '</label>' + scale('energy', R('energy_scale'), st.energy) + '</div>'
        + '<div class=field><label for=rwint>' + esc(R('intention')) + '</label><input id=rwint maxlength=500 value="' + esc(st.intention || '') + '" placeholder="' + esc(R('intention_ph')) + '" oninput="ritualSet(\'intention\',this.value)"></div>' },
      { key: 'leftovers', skip: d => !d.leftovers.length, html: d => '<p class=muted>' + esc(R('leftovers_help')) + '</p><div class=rw-list>' + d.leftovers.map(x => moveRow(x, ['today', 'tomorrow', 'date', 'keep'])).join('') + '</div>' },
      { key: 'top3', html: d => '<p class=muted>' + esc(R('top3_help')) + '</p>' + pickList(d.candidates, d.from_yesterday) },
      { key: 'capacity', html: d => capacityHtml(d) },
    ],
    evening: [
      { key: 'review', html: d => summaryHtml(d.summary) + '<h3 class=rw-h>' + esc(R('done_today')) + '</h3>'
        + (d.completed.length ? '<ul class=rw-done>' + d.completed.map(x => '<li>' + ic('check', 'sm') + esc(x.title) + '</li>').join('') + '</ul>' : '<p class=muted>' + esc(R('none_done')) + '</p>') },
      { key: 'unfinished', html: d => '<p class=muted>' + esc(R('unfinished_help')) + '</p>'
        + (d.unfinished.length ? '<div class=rw-list>' + d.unfinished.map(x => moveRow(x, ['tomorrow', 'date', 'keep'], true)).join('') + '</div>' : '<p class=muted>' + esc(R('unfinished_empty')) + '</p>') },
      { key: 'reflect', html: () => '<div class=field><label>' + esc(R('mood')) + '</label>' + scale('mood', R('mood_scale'), st.mood) + '</div>'
        + '<div class=field><label for=rwwins>' + esc(R('wins')) + '</label><textarea id=rwwins maxlength=1000 placeholder="' + esc(R('wins_ph')) + '" oninput="ritualSet(\'wins\',this.value)">' + esc(st.wins || '') + '</textarea></div>'
        + '<div class=field><label for=rwnotes>' + esc(R('notes')) + '</label><textarea id=rwnotes maxlength=1000 oninput="ritualSet(\'notes\',this.value)">' + esc(st.notes || '') + '</textarea></div>' },
      { key: 'tomorrow', html: d => '<p class=muted>' + esc(R('tomorrow_help')) + '</p>' + pickList(d.candidates) },
    ],
  };

  function summaryHtml(s) {
    const c = (k, v) => '<div class=rw-stat><span>' + esc(R(k)) + '</span><b>' + esc(v) + '</b></div>';
    return '<div class=rw-stats>' + c('sum_completed', num(s.completed)) + c('sum_logged', s.logged_text) + c('sum_focus', s.focus_text)
      + (s.habits_due ? c('sum_habits', RT('of', { done: num(s.habits_done), total: num(s.habits_due) })) : '')
      + (s.top_total ? c('sum_top3', RT('of', { done: num(s.top_done), total: num(s.top_total) })) : '') + '</div>';
  }

  function capacityHtml(d) {
    const c = d.capacity;
    if (!c.working) return '<p class=muted>' + esc(R('capacity_off')) + '</p>';
    // What the user chose in this wizard changes today's load: count it before saving.
    const all = d.leftovers.concat(d.candidates), byId = {}; all.forEach(x => { byId[x.id] = x; });
    let extra = 0;
    Object.entries(st.moves).forEach(([id, mv]) => { if (mv.action === 'today' && byId[id]) extra += byId[id].estimated_minutes || 0; });
    const committed = c.committed_minutes + extra, over = c.usable_minutes > 0 && committed > c.usable_minutes;
    const pct = c.usable_minutes ? Math.min(100, Math.round(committed / c.usable_minutes * 100)) : 0;
    return '<p>' + esc(RT('capacity_line', { usable: c.usable_text, committed: extra ? mins(committed) : c.committed_text })) + '</p>'
      + '<div class=progress role=progressbar aria-valuenow=' + pct + ' aria-valuemin=0 aria-valuemax=100><i class="' + (over ? 'danger' : pct > 90 ? 'warn' : '') + '" style="width:' + pct + '%"></i></div>'
      + '<p class="' + (over ? 'late' : 'muted') + '" style="margin-top:10px">' + esc(R(over ? 'capacity_over' : 'capacity_ok')) + '</p>'
      + '<label class=check style="margin-top:8px"><input type=checkbox ' + (st.planAfter ? 'checked' : '') + ' onchange="ritualSet(\'planAfter\',this.checked)"> ' + esc(R('plan_with_haman')) + '</label>';
  }

  function steps() { return STEPS[st.kind].filter(s => !(s.skip && s.skip(st.data))); }

  function render() {
    const list = steps(), i = Math.min(st.step, list.length - 1), s = list[i], last = i === list.length - 1;
    $('mtitle').textContent = R(st.kind + '_title');
    $('mbody').innerHTML = '<div class=rw><div class=rw-head><ol class=rw-steps>' + list.map((x, j) => '<li class="' + (j < i ? 'done' : j === i ? 'on' : '') + '"' + (j === i ? ' aria-current=step' : '') + '>' + esc(R('step_' + x.key)) + '</li>').join('') + '</ol>'
      + '<span class="muted small">' + esc(RT('step', { n: num(i + 1), total: num(list.length) })) + '</span></div>'
      + '<div class=rw-body>' + s.html(st.data) + '</div>'
      + '<div class=modalactions><button type=button class="btn primary" onclick="' + (last ? 'ritualSave()' : 'ritualStep(1)') + '">' + esc(last ? R('save_' + st.kind) : R('next')) + (last ? '' : ic('chev', 'sm flip')) + '</button>'
      + (i > 0 ? '<button type=button class=btn onclick="ritualStep(-1)">' + esc(R('back')) + '</button>' : '<button type=button class="btn ghost" onclick="ritualSkip(\'' + st.kind + '\',true)">' + esc(R('skip_today')) + '</button>') + '</div></div>';
  }

  window.ritualWizard = async function (kind) {
    let d;
    try { d = await api('/rituals/' + kind); } catch (e) { return fail(e); }
    const r = d.ritual && d.ritual.status === 'completed' ? d.ritual : {};
    st = { kind, data: d, step: 0, moves: {}, energy: r.energy || null, intention: r.intention || '', mood: r.mood || null, wins: r.wins || '', notes: r.notes || '',
      picks: (kind === 'morning' ? d.suggested_top : d.suggested_tomorrow).slice(0, 3), planAfter: false };
    modal(R(kind + '_title'), '');
    render();
  };
  window.ritualStep = n => { st.step = Math.max(0, st.step + n); render(); $('mbody').scrollTop = 0; };
  window.ritualSet = (k, v) => { st[k] = v; if (k === 'energy' || k === 'mood') render(); };
  window.ritualMove = (id, a) => { st.moves[id] = Object.assign(st.moves[id] || {}, { action: a }); render(); };
  window.ritualMoveDate = (id, v) => { st.moves[id] = Object.assign(st.moves[id] || { action: 'date' }, { date: v }); };
  window.ritualReason = (id, v) => { st.moves[id] = Object.assign(st.moves[id] || { action: 'keep' }, { reason: v || null }); };
  window.ritualPick = (id, el) => {
    if (el.checked && !st.picks.includes(id)) {
      if (st.picks.length >= 3) { el.checked = false; return toast(R('top3_limit'), false, true); }
      st.picks.push(id);
    } else if (!el.checked) st.picks = st.picks.filter(x => x !== id);
    el.closest('.rw-pick').classList.toggle('on', el.checked);
  };

  window.ritualSave = async function () {
    const moves = Object.entries(st.moves).filter(([, m]) => m.action !== 'keep' || m.reason)
      .map(([id, m]) => ({ task_id: +id, action: m.action, date: m.action === 'date' ? m.date : undefined, reason: m.reason || undefined }));
    if (moves.some(m => m.action === 'date' && !m.date)) return toast(R('act_date'), false, true);
    const body = st.kind === 'morning'
      ? { energy: st.energy, intention: st.intention, top_task_ids: st.picks, moves }
      : { mood: st.mood, wins: st.wins, notes: st.notes, tomorrow_task_ids: st.picks, moves };
    const btn = $('mbody').querySelector('.btn.primary'); if (btn) btn.disabled = true;
    try {
      const r = await api('/rituals/' + st.kind, { method: 'POST', body: JSON.stringify(body) });
      const planAfter = st.kind === 'morning' && st.planAfter;
      closeModal(); toast(r.message || t('saved'));
      if (planAfter && window.openPlanner) openPlanner('day'); else if (view === 'today') loadView('today');
    } catch (e) { if (btn) btn.disabled = false; fail(e); }
  };

  window.ritualSkip = async function (kind, fromModal) {
    try { await api('/rituals/' + kind + '/skip', { method: 'POST', body: '{}' }); } catch (e) { return fail(e); }
    if (fromModal) closeModal();
    toast(R('skipped'));
    if (view === 'today') loadView('today');
  };

  // Home: the prompt card and the user's own top 3 (used by planner-ai.js when present).
  window.ritualWidgets = {
    prompt(rt) {
      if (!rt || !rt.enabled || !rt.prompt) return '';
      const k = rt.prompt;
      return '<section class="card ritual-card" aria-labelledby=ritualh><div class=ritual-ic aria-hidden=true>' + ic(k === 'morning' ? 'sun' : 'moon') + '</div>'
        + '<div class=ritual-txt><h2 id=ritualh>' + esc(R(k + '_title')) + '</h2><p>' + esc(R('prompt_' + k)) + '</p></div>'
        + '<div class=actions><button class="btn primary" onclick="ritualWizard(\'' + k + '\')">' + esc(R('start_' + k)) + '</button><button class="btn ghost" onclick="ritualSkip(\'' + k + '\')">' + esc(R('skip_today')) + '</button></div></section>';
    },
    button(rt) {
      if (!rt || !rt.enabled) return '';
      const k = rt.morning === 'completed' || new Date().getHours() >= 15 ? 'evening' : 'morning';
      return '<button class="btn ghost" onclick="ritualWizard(\'' + k + '\')">' + ic(k === 'morning' ? 'sun' : 'moon') + esc(R('start_' + k)) + '</button>';
    },
    top(rt) {
      if (!rt || !rt.top || !rt.top.length) return '';
      return '<section class=card aria-labelledby=mytoph><div class=head><h2 id=mytoph>' + ic('flag', 'sm') + ' ' + esc(R('my_top3')) + '</h2><button class="btn ghost small" onclick="ritualWizard(\'morning\')">' + esc(R('change_top3')) + '</button></div>'
        + (rt.intention ? '<p class="muted small rw-intent">' + esc(R('intention_label')) + ': ' + esc(rt.intention) + '</p>' : '')
        + '<div class=list>' + rt.top.map(x => {
          const done = x.status === 'completed';
          return '<div class="row task' + (done ? ' done' : '') + '"><button class=check aria-pressed=' + done + ' aria-label="' + esc(I18N.row.complete) + '" onclick="toggleDone(' + x.id + ',' + done + ',this)"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></button>'
            + '<div class=rowmain><div class=rowtitle>' + esc(x.title) + '</div>' + meta(x) + '</div>'
            + '<div class=rowactions>' + rowMenu([window.startFocusOn && !done ? mi('focus', (I18N.focus || {}).start_on_task || 'Focus', 'startFocusOn(' + x.id + ')') : '', mi('edit', t('edit'), "editItem(" + x.id + ",'task')")]) + '</div></div>';
        }).join('') + '</div></section>';
    },
    weekly(m) {
      const r = m && m.rituals;
      if (!r || !(r.mornings || r.evenings)) return '';
      const c = (k, v) => '<div><div class=muted>' + esc(R(k)) + '</div><strong>' + esc(v) + '</strong></div>';
      return '<div class=rw-weekly><h3>' + ic('sun', 'sm') + ' ' + esc(R('weekly_title')) + '</h3><div class=statgrid>'
        + c('mornings', RT('days_suffix', { n: num(r.mornings) })) + c('evenings', RT('days_suffix', { n: num(r.evenings) }))
        + (r.avg_energy != null ? c('avg_energy', RT('of_five', { n: num(r.avg_energy) })) : '') + (r.avg_mood != null ? c('avg_mood', RT('of_five', { n: num(r.avg_mood) })) : '')
        + (r.top3_rate != null ? c('top3_rate', num(r.top3_rate) + '%') : '') + '</div>'
        + (r.wins && r.wins.length ? '<h3>' + esc(R('wins_title')) + '</h3><ul class=rw-done>' + r.wins.map(w => '<li>' + ic('check', 'sm') + '<span><span class="muted small">' + esc(fmt(w.date + 'T12:00:00Z')) + '</span> ' + esc(w.text) + '</span></li>').join('') + '</ul>' : '')
        + '</div>';
    },
  };
})();
