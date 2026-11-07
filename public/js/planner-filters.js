/* Haman Planner — tags, smart lists and saved filters for the Tasks view.
 * Filter definitions are validated on the server (TaskFilter); this file only builds them. */
(function () {
  'use strict';
  const FL = (k, r) => { let s = (I18N.filters || {})[k] || k; if (r) for (const x in r) s = s.split(':' + x).join(r[x]); return s; };
  const PRESETS = ['today', 'tomorrow', 'overdue', 'upcoming', 'high_priority', 'high_contribution', 'quick', 'deep_work', 'blocked', 'no_deadline', 'no_project'];
  const ST = window.FILTERS = { saved: null, loading: null };
  const COLORS = ['#5b4fe0', '#2f6fe0', '#0fa3a3', '#13804c', '#e0893a', '#d94b7a', '#7d839c'];

  // ------------------------------------------------------------------ tag picker in the task form
  window.tagWidget = function (selected) {
    const sel = new Set((selected || []).map(t => t.id || t));
    return '<div class="tagpick" data-tagwidget data-pending="[]">' + (cache.tags || []).map(t =>
      '<label class="tagopt" style="--tc:' + esc(t.color || 'var(--accent)') + '"><input type="checkbox" data-skip="1" value="' + t.id + '"' + (sel.has(t.id) ? ' checked' : '') + '><span>#' + esc(t.name) + '</span></label>').join('')
      + '<span class="tagnew"></span><input class="input tagin" data-skip="1" type="text" maxlength="40" placeholder="' + esc(FL('add_tag_ph')) + '" aria-label="' + esc(FL('new_tag')) + '"></div>';
  };
  window.setTagWidget = function (el, tags) {
    if (!el) return;
    const ids = new Set((tags || []).map(t => t.id));
    el.querySelectorAll('input[type=checkbox]').forEach(c => { c.checked = ids.has(+c.value); });
  };
  function addPending(el, name) {
    name = name.replace(/[,#<>]/g, '').trim();
    if (!name) return;
    const existing = [...el.querySelectorAll('.tagopt')].find(l => l.textContent.slice(1).toLowerCase() === name.toLowerCase());
    if (existing) { existing.querySelector('input').checked = true; return; }
    const p = JSON.parse(el.dataset.pending || '[]');
    if (p.map(x => x.toLowerCase()).includes(name.toLowerCase())) return;
    p.push(name); el.dataset.pending = JSON.stringify(p);
    el.querySelector('.tagnew').insertAdjacentHTML('beforeend', '<button type="button" class="tagopt new" data-name="' + esc(name) + '" title="' + esc(t('delete')) + '"><span>#' + esc(name) + ' ×</span></button>');
  }
  document.addEventListener('keydown', e => {
    const inp = e.target.closest && e.target.closest('.tagin');
    if (!inp || (e.key !== 'Enter' && e.key !== ',')) return;
    e.preventDefault(); addPending(inp.closest('[data-tagwidget]'), inp.value); inp.value = '';
  });
  document.addEventListener('focusout', e => { const inp = e.target.closest && e.target.closest('.tagin'); if (inp && inp.value.trim()) { addPending(inp.closest('[data-tagwidget]'), inp.value); inp.value = ''; } });
  document.addEventListener('click', e => {
    const b = e.target.closest && e.target.closest('.tagopt.new');
    if (!b) return;
    const el = b.closest('[data-tagwidget]'), p = JSON.parse(el.dataset.pending || '[]').filter(x => x !== b.dataset.name);
    el.dataset.pending = JSON.stringify(p); b.remove();
  });

  // ------------------------------------------------------------------ filter bar
  function describe(def) {
    const tagName = id => ((cache.tags || []).find(t => t.id === id) || {}).name || '#' + id;
    const ref = (list, id) => ((cache[list] || []).find(x => x.id === id) || {}).title || '#' + id;
    return Object.entries(def || {}).filter(([k]) => k !== 'tag_mode').map(([k, v]) => {
      let val;
      if (k === 'tags') val = v.map(id => '#' + tagName(id)).join(def.tag_mode === 'all' ? ' + ' : ' / ');
      else if (k === 'priority') val = v.map(p => String(label(p)).split('—').pop().trim()).join('، ');
      else if (k === 'project_id') val = ref('projects', v);
      else if (k === 'goal_id') val = ref('goals', v);
      else if (k === 'area_id') val = ((cache.areas || []).find(x => x.id === v) || {}).name || '#' + v;
      else if (k === 'min_minutes' || k === 'max_minutes') val = typeof window.dur === 'function' ? dur(v) : num(v);
      else if (typeof v === 'boolean' || ['state', 'due'].includes(k)) val = FL('v_' + v);
      else val = String(v);
      return '<span class="fchip">' + esc(FL('k_' + k)) + ': <b>' + esc(val) + '</b></span>';
    }).join('');
  }

  window.taskFilterBar = function (st) {
    if (ST.saved === null && !ST.loading) {
      ST.loading = api('/filters').then(r => { ST.saved = r.saved || []; ST.loading = null; if (view === 'tasks') loadList('tasks'); }).catch(() => { ST.saved = []; ST.loading = null; });
    }
    const on = k => st.smart === k && !st.def;
    const chips = PRESETS.map(k => '<button class="sl' + (on(k) ? ' on' : '') + '" aria-pressed="' + on(k) + '" onclick="setSmart(\'' + k + '\')">' + esc(FL('p_' + k)) + '</button>').join('');
    const saved = (ST.saved || []).map(f => '<span class="sl saved' + (st.savedId === f.id ? ' on' : '') + '"><button onclick="applySaved(' + f.id + ')">' + ic('filter', 'sm') + esc(f.name) + '</button><button class="x" aria-label="' + esc(t('delete')) + '" onclick="deleteSaved(' + f.id + ')">×</button></span>').join('');
    const tags = (cache.tags || []).length ? '<div class="menu"><button class="sl" aria-haspopup="menu" aria-expanded="false" onclick="toggleMenu(this,event)">' + ic('tag', 'sm') + esc(FL('tags')) + '</button><div class="menu-pop" role="menu">'
      + cache.tags.map(g => '<button role="menuitem" onclick="filterByTag(' + g.id + ')"><span class="dot" style="background:' + esc(g.color || 'var(--accent)') + '"></span>#' + esc(g.name) + (g.open_tasks ? ' <span class="muted small">(' + esc(num(g.open_tasks)) + ')</span>' : '') + '</button>').join('')
      + '<button role="menuitem" onclick="manageTags()">' + ic('settings', 'sm') + esc(FL('manage_tags')) + '</button></div></div>' : '';
    const active = st.def ? '<div class="factive"><span class="muted small">' + esc(FL('active')) + ':</span>' + describe(st.def) + (st.savedId ? '' : '<button class="btn small" onclick="saveFilter()">' + ic('plus', 'sm') + esc(FL('save')) + '</button>') + '<button class="btn small ghost" onclick="clearFilter()">' + esc(FL('clear')) + '</button></div>' : '';
    return '<div class="filterbar"><div class="slrow" role="toolbar" aria-label="' + esc(FL('smart')) + '">' + chips + saved + tags + '</div>'
      + '<form class="aifilter" onsubmit="event.preventDefault();aiFilter(this)"><span class="aiic">' + ic('haman', 'sm') + '</span><input name="q" class="input" maxlength="300" placeholder="' + esc(FL('ai_ph')) + '" aria-label="' + esc(FL('ai_ph')) + '"><button class="btn small">' + esc(FL('ai_btn')) + '</button></form>'
      + active + '</div>';
  };

  const reset = (patch) => { const st = listState.tasks || (listState.tasks = { state: 'open', page: 1, rows: [], future: false }); Object.assign(st, { smart: null, def: null, savedId: null }, patch); loadList('tasks'); };
  window.setSmart = k => { const st = listState.tasks || {}; reset(st.smart === k && !st.def ? {} : { smart: k }); };
  window.clearFilter = () => reset({});
  window.filterByTag = id => { listState.tasks = Object.assign(listState.tasks || { state: 'open', page: 1, rows: [], future: false }, { smart: null, def: { tags: [id] }, savedId: null }); if (view !== 'tasks') loadView('tasks'); else loadList('tasks'); };
  window.applySaved = id => { const f = (ST.saved || []).find(x => x.id === id); if (f) reset({ def: f.definition, savedId: id }); };

  window.aiFilter = async function (form) {
    const q = form.q.value.trim(); if (q.length < 3) return;
    const btn = form.querySelector('button'); btn.classList.add('is-busy');
    try {
      const r = await api('/filters/parse', { method: 'POST', body: JSON.stringify({ text: q }) });
      // Show what Haman understood; the user applies it explicitly.
      modal(FL('title'), '<p class="muted small">' + esc(FL('ai_preview')) + '</p><div class="factive" style="margin:0 0 12px">' + (describe(r.definition) || '<span class="muted">—</span>') + '</div>'
        + '<div class="modalactions"><button class="btn" onclick="closeModal()">' + esc(t('cancel')) + '</button><button class="btn primary" id="aiapply">' + esc(FL('apply')) + '</button></div>');
      $('aiapply').onclick = () => { closeModal(); reset({ def: r.definition }); };
    } catch (e) { fail(e); } finally { btn.classList.remove('is-busy'); }
  };

  window.saveFilter = function () {
    const st = listState.tasks; if (!st || !st.def) return;
    modal(FL('save'), '<form id="sff"><div class="field"><label for="sfn">' + esc(FL('save_name')) + '</label><input id="sfn" name="name" class="input" required maxlength="60"></div><div class="factive">' + describe(st.def) + '</div><div class="modalactions"><button class="btn primary">' + esc(t('save')) + '</button></div></form>');
    $('sff').onsubmit = async e => {
      e.preventDefault();
      try { const f = await api('/filters', { method: 'POST', body: JSON.stringify({ name: e.target.name.value, definition: st.def }) }); ST.saved = (ST.saved || []).concat([f]); st.savedId = f.id; closeModal(); toast(t('saved')); loadList('tasks'); } catch (x) { fail(x, e.target); }
    };
  };
  window.deleteSaved = async function (id) {
    if (!confirm(FL('delete_confirm'))) return;
    try { await api('/filters/' + id, { method: 'DELETE' }); ST.saved = (ST.saved || []).filter(f => f.id !== id); const st = listState.tasks; if (st && st.savedId === id) reset({}); else loadList('tasks'); } catch (e) { fail(e); }
  };

  // ------------------------------------------------------------------ tag management
  window.manageTags = async function () {
    let tags = await api('/tags').catch(() => []);
    cache.tags = tags;
    const row = g => '<div class="row" data-tag="' + g.id + '"><input class="input" value="' + esc(g.name) + '" maxlength="40" aria-label="' + esc(FL('tag')) + '" style="flex:1">'
      + '<div class="swatches">' + COLORS.map(c => '<label class="sw" style="--c:' + c + '"><input type="radio" name="c' + g.id + '" value="' + c + '"' + ((g.color || COLORS[0]) === c ? ' checked' : '') + '><i></i></label>').join('') + '</div>'
      + '<span class="muted small">' + esc(FL('open_count', { n: num(g.open_tasks || 0) })) + '</span><button class="btn small" onclick="saveTag(' + g.id + ')">' + esc(t('save')) + '</button><button class="btn small ghost danger" onclick="deleteTag(' + g.id + ')">' + esc(t('delete')) + '</button></div>';
    modal(FL('manage_tags'), tags.length ? '<div class="list">' + tags.map(row).join('') + '</div>' : '<p class="muted">' + esc(FL('no_tags')) + '</p>');
  };
  window.saveTag = async function (id) {
    const r = document.querySelector('[data-tag="' + id + '"]');
    const color = (r.querySelector('input[type=radio]:checked') || {}).value;
    try { await api('/tags/' + id, { method: 'PUT', body: JSON.stringify({ name: r.querySelector('.input').value, color }) }); toast(t('saved')); await cacheAll(); if (view === 'tasks') loadList('tasks'); } catch (e) { fail(e); }
  };
  window.deleteTag = async function (id) {
    if (!confirm(FL('tag_delete_confirm'))) return;
    try { await api('/tags/' + id, { method: 'DELETE' }); await cacheAll(); manageTags(); if (view === 'tasks') { const st = listState.tasks; if (st && st.def && (st.def.tags || []).includes(id)) clearFilter(); else loadList('tasks'); } } catch (e) { fail(e); }
  };
})();
