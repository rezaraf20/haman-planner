/* Task breakdown into subtasks and history-based duration suggestions.
 * The AI only fills an editable list; subtasks are created when the user presses "Create".
 * Estimate suggestions only fill the form field when the user clicks "Use"; saving is still theirs. */
(function () {
  const B = k => (I18N.breakdown || {})[k] || k;
  const BT = (k, p) => { let s = B(k); Object.entries(p || {}).forEach(([a, v]) => { s = s.split(':' + a).join(v); }); return s; };
  const dur = m => { m = Math.max(0, Math.round(+m || 0)); const h = Math.floor(m / 60), r = m % 60; return h && r ? t('dur_hm', { h: num(h), m: num(r) }) : h ? t('dur_h', { h: num(h) }) : t('dur_m', { m: num(r) }); };
  let bd = null;

  // ------------------------------------------------------------------ estimate hint in the task form
  window.formReady = function (form) {
    if (!form || form.dataset.kind !== 'task') return;
    const inp = form.querySelector('[name=estimated_minutes]');
    if (!inp || form.querySelector('.est-hint')) return;
    const box = document.createElement('div');
    box.className = 'est-hint';
    box.innerHTML = '<button type=button class="btn ghost small">' + ic('history', 'sm') + esc(B('est_button')) + '</button><div class=est-out aria-live=polite></div>';
    inp.closest('.field').appendChild(box);
    box.querySelector('button').onclick = () => estimate(form, inp, box.querySelector('.est-out'));
  };

  async function estimate(form, inp, out) {
    const title = (form.querySelector('[name=title]') || {}).value || '';
    if (!title.trim()) { form.querySelector('[name=title]').focus(); return; }
    const d = collect(form, true);
    const body = { title, task_id: form.dataset.id ? +form.dataset.id : undefined, project_id: d.project_id ? +d.project_id : undefined,
      tag_ids: d.tag_ids || undefined, estimated_minutes: inp.value ? +inp.value : undefined };
    out.innerHTML = '<span class="muted small">…</span>';
    let r;
    try { r = await api('/estimate', { method: 'POST', body: JSON.stringify(body) }); } catch (e) { out.innerHTML = ''; return fail(e); }
    if (!r.suggested_minutes) { out.innerHTML = '<p class="muted small">' + esc(B(r.reason === 'estimate_matches_history' ? 'est_ok' : 'est_none')) + '</p>'; return; }
    const text = r.basis === 'similar'
      ? BT('est_similar', { time: dur(r.suggested_minutes), count: num(r.similar_count), min: dur(r.range[0]), max: dur(r.range[1]) })
      : BT('est_factor', { time: dur(r.suggested_minutes) });
    out.innerHTML = '<p class=small>' + esc(text) + '</p>'
      + (r.examples && r.examples.length ? '<details class=small><summary>' + esc(B('est_examples')) + '</summary><ul>' + r.examples.map(x => '<li>' + esc(x.title) + ' — ' + esc(dur(x.actual_minutes)) + '</li>').join('') + '</ul></details>' : '')
      + (+inp.value === r.suggested_minutes ? '' : '<button type=button class="btn small">' + esc(BT('est_use', { time: dur(r.suggested_minutes) })) + '</button>');
    const use = out.querySelector('button');
    if (use) use.onclick = () => { inp.value = r.suggested_minutes; inp.dispatchEvent(new Event('input', { bubbles: true })); use.remove(); inp.focus(); };
  }

  // ------------------------------------------------------------------ breakdown modal
  window.breakdownModal = async function (taskId) {
    let d;
    try { d = await api('/tasks/' + taskId + '/breakdown'); } catch (e) { return fail(e); }
    bd = { id: taskId, data: d, rows: [{ title: '', minutes: '' }], note: '', updateParent: !d.task.estimated_minutes };
    modal(B('title') + ': ' + d.task.title, '');
    render();
  };

  function total() { return bd.rows.reduce((a, r) => a + (r.title.trim() ? (+r.minutes || 0) : 0), 0); }

  function render() {
    const d = bd.data;
    const kids = d.children.length ? '<div class=bd-existing><h3>' + esc(B('existing')) + '</h3><ul class=rw-done>' + d.children.map(c => '<li class="' + (c.status === 'completed' ? 'done' : '') + '">' + (c.status === 'completed' ? ic('check', 'sm') : '<span class=bd-dot aria-hidden=true></span>') + '<span>' + esc(c.title) + (c.estimated_minutes ? ' <span class="muted small">' + esc(dur(c.estimated_minutes)) + '</span>' : '') + '</span></li>').join('') + '</ul></div>' : '';
    const rows = bd.rows.map((r, i) => '<div class=bd-row><span class=bd-n aria-hidden=true>' + num(i + 1) + '</span>'
      + '<input class=bd-title value="' + esc(r.title) + '" maxlength=255 placeholder="' + esc(B('step_ph')) + '" aria-label="' + esc(B('step_ph') + ' ' + (i + 1)) + '" oninput="bdSet(' + i + ',\'title\',this.value)">'
      + '<input class=bd-min type=number min=0 max=1440 step=5 value="' + esc(r.minutes) + '" aria-label="' + esc(B('minutes')) + '" oninput="bdSet(' + i + ',\'minutes\',this.value)"><span class="muted small">' + esc(B('minutes')) + '</span>'
      + '<button type=button class="btn ghost icon small" onclick="bdRemove(' + i + ')" aria-label="' + esc(B('remove')) + '" title="' + esc(B('remove')) + '">' + ic('x', 'sm') + '</button></div>').join('');
    $('mbody').innerHTML = '<div class=bd><p class=muted style="margin-top:0">' + esc(B('intro')) + '</p>' + kids
      + (d.ai_available ? '<button type=button class="btn" id=bdai onclick="bdAsk()">' + ic('haman') + esc(B('ask_ai')) + '</button>' : '<p class="muted small">' + esc(B('ai_unavailable')) + '</p>')
      + (bd.note ? '<p class="muted small bd-note">' + esc(bd.note) + '</p>' : '')
      + '<div class=bd-rows>' + rows + '</div>'
      + '<div class=bd-foot><button type=button class="btn ghost small" onclick="bdAdd()"' + (bd.rows.length >= d.max_steps ? ' disabled' : '') + '>' + ic('plus', 'sm') + esc(B('add_step')) + '</button><span class="muted small" id=bdtotal>' + esc(BT('total', { time: dur(total()) })) + '</span></div>'
      + '<label class=check><input type=checkbox ' + (bd.updateParent ? 'checked' : '') + ' onchange="bdUpdateParent(this.checked)"> ' + esc(B('update_parent')) + '</label>'
      + '<div class=modalactions><button type=button class="btn primary" onclick="bdCreate(this)">' + esc(B('create')) + '</button><button type=button class=btn onclick="closeModal()">' + esc(t('cancel')) + '</button></div></div>';
  }

  window.bdSet = (i, k, v) => { bd.rows[i][k] = v; const el = $('bdtotal'); if (el) el.textContent = BT('total', { time: dur(total()) }); };
  window.bdAdd = () => { if (bd.rows.length < bd.data.max_steps) { bd.rows.push({ title: '', minutes: '' }); render(); const all = document.querySelectorAll('.bd-title'); all[all.length - 1].focus(); } };
  window.bdRemove = i => { bd.rows.splice(i, 1); if (!bd.rows.length) bd.rows.push({ title: '', minutes: '' }); render(); };
  window.bdUpdateParent = v => { bd.updateParent = v; };

  window.bdAsk = async function () {
    const b = $('bdai'); b.disabled = true; b.innerHTML = ic('haman') + esc(B('asking'));
    try {
      const r = await api('/tasks/' + bd.id + '/breakdown', { method: 'POST', body: '{}' });
      const fresh = r.steps.map(s => ({ title: s.title, minutes: s.suggested_minutes ?? s.estimated_minutes ?? '' }));
      const kept = bd.rows.filter(x => x.title.trim());
      bd.rows = kept.concat(fresh).slice(0, bd.data.max_steps);
      bd.note = B('ai_note') + (r.calibrated && r.factor ? ' ' + BT('calibrated', { factor: num(r.factor) }) : '');
      render();
    } catch (e) { b.disabled = false; b.innerHTML = ic('haman') + esc(B('ask_ai')); fail(e); }
  };

  window.bdCreate = async function (btn) {
    const steps = bd.rows.filter(r => r.title.trim()).map(r => ({ title: r.title.trim(), estimated_minutes: r.minutes === '' ? null : +r.minutes }));
    if (!steps.length) { toast(B('empty'), false, true); const f = document.querySelector('.bd-title'); if (f) f.focus(); return; }
    btn.disabled = true;
    try {
      const r = await api('/tasks/' + bd.id + '/subtasks', { method: 'POST', body: JSON.stringify({ steps, update_parent_estimate: bd.updateParent }) });
      closeModal(); toast(r.message || t('saved')); loadView(view);
    } catch (e) { btn.disabled = false; fail(e); }
  };
})();
