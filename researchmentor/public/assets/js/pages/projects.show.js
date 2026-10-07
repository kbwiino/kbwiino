RM.pages['projects.show'] = async function (root, id) {
  const base = '/api/projects/' + id;
  const p = await RM.get(base + '/overview');
  const full = p.detail_level === 'full';
  const links = [
    ['Constitution', `/projects/${id}/constitution`, 'bi-bank'], ['Documents', `/projects/${id}/documents`, 'bi-files'], ['AI reviews', `/projects/${id}/reviews`, 'bi-robot'],
    ['Alignment', `/projects/${id}/alignment`, 'bi-diagram-3'], ['Supervision', `/projects/${id}/supervision`, 'bi-people'], ['Literature', `/projects/${id}/literature`, 'bi-journal-text'],
    ['Viva practice', `/projects/${id}/viva`, 'bi-chat-square-quote'],
  ].filter((l) => full || ['Constitution'].includes(l[0]) === false);
  const sup = p.members.filter((m) => m.role === 'supervisor' || m.role === 'co_supervisor').map((m) => RM.esc(m.name) + (m.role === 'co_supervisor' ? ' (co)' : '')).join(', ') || 'Unassigned';
  const prog = p.progress || { milestones: {}, tasks: {} };
  root.innerHTML = `
  <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
    <div><h1 class="h3 mb-1">${RM.esc(p.title)}</h1>
      <div class="text-muted">${RM.esc(p.owner.name)} · ${RM.esc(p.programme.name)} · ${RM.esc(p.academic_year.label)}</div></div>
    <div><span class="badge text-bg-primary fs-6">${RM.label(p.stage)}</span> <span class="badge text-bg-secondary fs-6">${RM.label(p.status)}</span></div>
  </div>
  ${p.detail_level === 'summary' ? '<div class="alert alert-secondary">You have summary-level (oversight) access to this project. Research content is not shown.</div>' : ''}
  <div class="d-flex flex-wrap gap-2 mb-4">${links.map((l) => `<a class="btn btn-outline-primary btn-sm" href="${l[1]}"><i class="bi ${l[2]}"></i> ${l[0]}</a>`).join('')}</div>
  <div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Supervisor</div><div class="fw-semibold">${sup}</div></div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Milestones</div><div class="fw-semibold">${prog.milestones.completed || 0} / ${prog.milestones.total || 0} done</div>${prog.milestones.overdue ? `<span class="badge text-bg-danger">${prog.milestones.overdue} overdue</span>` : ''}</div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Open tasks</div><div class="fw-semibold">${(prog.tasks || {}).open || 0}</div>${(prog.tasks || {}).overdue ? `<span class="badge text-bg-danger">${prog.tasks.overdue} overdue</span>` : ''}</div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Open AI findings</div><div class="fw-semibold">${p.open_review_issues ?? '—'}</div></div></div></div>
  </div>
  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-overview" type="button">Overview</button></li>
    ${full ? '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-design" type="button">Research design</button></li>' : ''}
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-milestones" type="button">Milestones</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-timeline" type="button">Timeline</button></li>
    ${p.permissions.view_content ? '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-history" type="button">History</button></li>' : ''}
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-manage" type="button">Manage</button></li>
  </ul>
  <div id="alerts"></div>
  <div class="tab-content">
    <div class="tab-pane fade show active" id="t-overview"><div class="card"><div class="card-body" id="overview-body"></div></div></div>
    <div class="tab-pane fade" id="t-design"></div>
    <div class="tab-pane fade" id="t-milestones"></div>
    <div class="tab-pane fade" id="t-timeline"></div>
    <div class="tab-pane fade" id="t-history"></div>
    <div class="tab-pane fade" id="t-manage"></div>
  </div>`;
  const field = (l, v) => (v ? `<dt class="col-sm-3">${l}</dt><dd class="col-sm-9">${RM.nl2br(v)}</dd>` : '');
  const ob = root.querySelector('#overview-body');
  const renderOverview = (p) => {
    ob.innerHTML = `<dl class="row mb-0">${field('Research area', p.research_area)}${field('Degree level', RM.label(p.degree_level))}${field('Approach', RM.label(p.approach))}${field('Design', p.design)}
    ${full ? field('Problem statement', p.problem_statement) + field('Purpose', p.purpose) + field('Background', p.background) + field('Scope', p.scope) + field('Significance', p.significance) + field('Paradigm', p.paradigm) + field('Population', p.population) + field('Sampling', p.sampling_approach) + field('Data collection', p.data_collection_methods) + field('Data analysis', p.data_analysis_methods) + field('Ethics', p.ethics_information) : ''}</dl>
    ${p.permissions.edit ? '<button class="btn btn-sm btn-outline-secondary mt-3" id="edit-btn"><i class="bi bi-pencil"></i> Edit project details</button>' : ''}`;
    const eb = ob.querySelector('#edit-btn'); if (eb) eb.addEventListener('click', () => editForm(p));
  };
  const editForm = (p) => {
    const fields = [['title', 'Title', 'input'], ['research_area', 'Research area', 'input'], ['design', 'Design', 'input'], ['paradigm', 'Paradigm', 'input'], ['problem_statement', 'Problem statement', 'textarea'], ['purpose', 'Purpose', 'textarea'], ['background', 'Background', 'textarea'], ['scope', 'Scope', 'textarea'], ['significance', 'Significance', 'textarea'], ['population', 'Population', 'textarea'], ['sampling_approach', 'Sampling approach', 'textarea'], ['data_collection_methods', 'Data collection methods', 'textarea'], ['data_analysis_methods', 'Data analysis methods', 'textarea'], ['ethics_information', 'Ethics information', 'textarea']];
    ob.innerHTML = `<form id="ef">${fields.map(([k, l, t]) => `<div class="mb-3"><label class="form-label" for="e-${k}">${l}</label>${t === 'input' ? `<input class="form-control" id="e-${k}" name="${k}" value="${RM.esc(p[k] || '')}">` : `<textarea class="form-control" id="e-${k}" name="${k}" rows="3">${RM.esc(p[k] || '')}</textarea>`}</div>`).join('')}
      <div class="mb-3"><label class="form-label" for="e-approach">Approach</label><select class="form-select" name="approach" id="e-approach">${['quantitative', 'qualitative', 'mixed', 'other'].map((a) => `<option ${a === p.approach ? 'selected' : ''} value="${a}">${RM.label(a)}</option>`).join('')}</select></div>
      <button class="btn btn-primary" type="submit">Save</button> <button class="btn btn-link" type="button" id="cancel">Cancel</button></form>`;
    ob.querySelector('#cancel').addEventListener('click', () => renderOverview(p));
    ob.querySelector('#ef').addEventListener('submit', async (ev) => { ev.preventDefault(); try { const np = await RM.put(base, RM.formData(ev.target)); Object.assign(p, np); renderOverview(p); RM.alert('Saved.', 'success'); } catch (e) { RM.showError(e); } });
  };
  renderOverview(p);

  // ---- research design components
  const designPane = root.querySelector('#t-design');
  if (full) {
    const comps = { objectives: ['Objectives', 'body'], questions: ['Research questions', 'body'], hypotheses: ['Hypotheses', 'body'], variables: ['Variables', 'name'], theories: ['Theories', 'name'] };
    designPane.innerHTML = '<div class="row g-3" id="comp-grid"></div><div class="card mt-3"><div class="card-body"><h2 class="h6">Conceptual framework</h2><div id="fw"></div></div></div>';
    const grid = designPane.querySelector('#comp-grid');
    for (const [key, [title, tf]] of Object.entries(comps)) {
      grid.insertAdjacentHTML('beforeend', `<div class="col-lg-6"><div class="card h-100"><div class="card-body"><h2 class="h6">${title}</h2><ul class="list-group list-group-flush mb-2" id="l-${key}"></ul>
        ${p.permissions.edit ? `<form class="d-flex gap-2" data-key="${key}"><label class="visually-hidden" for="n-${key}">Add</label><input class="form-control form-control-sm" id="n-${key}" required maxlength="500" placeholder="Add…"><button class="btn btn-sm btn-primary">Add</button></form>` : ''}</div></div></div>`);
    }
    async function loadComp(key) {
      const items = await RM.get(`${base}/${key}`); const tf = comps[key][1];
      designPane.querySelector('#l-' + key).innerHTML = items.length ? items.map((i, n) => `<li class="list-group-item d-flex justify-content-between align-items-start gap-2"><div><span class="text-muted me-1">${n + 1}.</span>${RM.esc(i[tf])} ${i.origin !== 'student' ? RM.originBadge(i.origin) : ''}${key === 'variables' && i.indicators ? `<div class="small text-muted">Indicators: ${RM.esc(i.indicators)}</div>` : ''}</div>${p.permissions.edit ? `<button class="btn btn-sm btn-link text-danger p-0" data-del="${RM.esc(i.id)}" aria-label="Delete"><i class="bi bi-trash"></i></button>` : ''}</li>`).join('') : '<li class="list-group-item text-muted small">None yet</li>';
    }
    for (const k of Object.keys(comps)) loadComp(k).catch(() => {});
    designPane.addEventListener('submit', async (ev) => {
      const form = ev.target.closest('form[data-key]'); if (!form) return; ev.preventDefault();
      const key = form.dataset.key; const v = form.querySelector('input').value;
      try { await RM.post(`${base}/${key}`, { [comps[key][1]]: v }); form.reset(); loadComp(key); } catch (e) { RM.showError(e); }
    });
    designPane.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-del]'); if (!b) return; const key = b.closest('ul').id.slice(2);
      if (!confirm('Delete this item?')) return;
      try { await RM.del(`${base}/${key}/${b.dataset.del}`); loadComp(key); } catch (e) { RM.showError(e); }
    });
    const fwBox = designPane.querySelector('#fw');
    const fw = await RM.get(base + '/conceptual-framework');
    fwBox.innerHTML = p.permissions.edit ? `<form id="fwf"><textarea class="form-control mb-2" rows="3" id="fw-d" aria-label="Conceptual framework description">${RM.esc(fw ? fw.description : '')}</textarea><button class="btn btn-sm btn-primary">Save framework</button></form>` : `<p>${RM.nl2br(fw ? fw.description : 'Not provided.')}</p>`;
    const fwf = fwBox.querySelector('#fwf'); if (fwf) fwf.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await RM.put(base + '/conceptual-framework', { description: fwf.querySelector('textarea').value, relationships: fw ? fw.relationships : [] }); RM.alert('Framework saved.', 'success'); } catch (e) { RM.showError(e); } });
  }

  // ---- milestones
  const msPane = root.querySelector('#t-milestones');
  async function loadMs() {
    const ms = await RM.get(base + '/milestones');
    const cls = { completed: 'success', overdue: 'danger', pending: 'secondary' };
    msPane.innerHTML = `<div class="card"><div class="card-body"><ul class="list-group list-group-flush mb-3">${ms.map((m) => `<li class="list-group-item d-flex justify-content-between align-items-center"><div><strong>${RM.esc(m.title)}</strong> <span class="badge text-bg-${cls[m.state]}">${RM.label(m.state)}</span><div class="small text-muted">Due ${RM.fmtDate(m.due_date)}</div></div>${p.permissions.manage_milestones ? `<button class="btn btn-sm btn-outline-success" data-ms="${RM.esc(m.id)}" data-done="${m.completed_at ? 0 : 1}">${m.completed_at ? 'Reopen' : 'Mark complete'}</button>` : ''}</li>`).join('') || '<li class="list-group-item text-muted">No milestones.</li>'}</ul>
    ${p.permissions.manage_milestones ? '<form id="msf" class="row g-2"><div class="col-md-6"><label class="visually-hidden" for="ms-t">Title</label><input id="ms-t" class="form-control" placeholder="Milestone title" required></div><div class="col-md-3"><label class="visually-hidden" for="ms-d">Due date</label><input id="ms-d" type="date" class="form-control" required></div><div class="col-md-3"><button class="btn btn-primary w-100">Add milestone</button></div></form>' : ''}</div></div>`;
    const f = msPane.querySelector('#msf'); if (f) f.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await RM.post(base + '/milestones', { title: f.querySelector('#ms-t').value, due_date: f.querySelector('#ms-d').value }); loadMs(); } catch (e) { RM.showError(e); } });
    msPane.querySelectorAll('[data-ms]').forEach((b) => b.addEventListener('click', async () => { try { await RM.post(`${base}/milestones/${b.dataset.ms}/complete`, { completed: b.dataset.done === '1' }); loadMs(); } catch (e) { RM.showError(e); } }));
  }
  loadMs().catch(() => {});

  // ---- timeline & history
  RM.get(base + '/timeline').then((t) => {
    const icon = { stage: 'bi-signpost', milestone: 'bi-flag', constitution: 'bi-bank', meeting: 'bi-people', document: 'bi-file-earmark-text' };
    root.querySelector('#t-timeline').innerHTML = `<div class="card"><ul class="list-group list-group-flush">${t.map((e) => `<li class="list-group-item"><i class="bi ${icon[e.type] || 'bi-dot'} me-2"></i><strong>${RM.esc(e.title)}</strong><span class="text-muted small ms-2">${RM.fmtDateTime(e.at)}</span>${e.detail ? `<div class="small text-muted">${RM.esc(e.detail)}</div>` : ''}</li>`).join('') || '<li class="list-group-item text-muted">No events.</li>'}</ul></div>`;
  }).catch(() => {});
  if (p.permissions.view_content) RM.get(base + '/history').then((h) => {
    root.querySelector('#t-history').innerHTML = `<div class="card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>When</th><th>Who</th><th>Action</th></tr></thead><tbody>${h.map((e) => `<tr><td>${RM.fmtDateTime(e.at)}</td><td>${RM.esc(e.actor)}</td><td>${RM.esc(e.action)}</td></tr>`).join('')}</tbody></table></div></div>`;
  }).catch(() => {});

  // ---- manage
  const mg = root.querySelector('#t-manage');
  const perms = p.permissions;
  mg.innerHTML = `<div class="row g-3">
    ${perms.give_ai_consent ? `<div class="col-md-6"><div class="card"><div class="card-body"><h2 class="h6">AI processing consent</h2><p class="small text-muted">AI features send excerpts of your project text to an external AI provider (Anthropic) for analysis. Consent can be withdrawn at any time.</p><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="consent" ${p.ai_consent ? 'checked' : ''}><label class="form-check-label" for="consent">I consent to AI processing of my project content</label></div></div></div></div>` : ''}
    ${perms.change_stage ? `<div class="col-md-6"><div class="card"><div class="card-body"><h2 class="h6">Research stage</h2><form id="stf" class="row g-2"><div class="col-12"><label class="visually-hidden" for="st">Stage</label><select class="form-select" id="st"></select></div><div class="col-12"><label class="visually-hidden" for="stn">Notes</label><input class="form-control" id="stn" placeholder="Notes (optional)"></div><div class="col-12"><button class="btn btn-primary">Update stage</button></div></form></div></div></div>` : ''}
    ${perms.assign_supervisor ? `<div class="col-md-6"><div class="card"><div class="card-body"><h2 class="h6">Assign supervisor</h2><form id="asf" class="row g-2"><div class="col-12"><label class="visually-hidden" for="asu">Supervisor</label><select class="form-select" id="asu"></select></div><div class="col-12"><button class="btn btn-primary">Assign</button></div></form></div></div></div>` : ''}
    ${perms.archive ? `<div class="col-md-6"><div class="card"><div class="card-body"><h2 class="h6">Archive</h2><button class="btn btn-outline-danger" id="arch">${p.status === 'archived' ? 'Restore project' : 'Archive project'}</button></div></div></div>` : ''}
    ${perms.export ? `<div class="col-md-6"><div class="card"><div class="card-body"><h2 class="h6">Export</h2><a class="btn btn-outline-secondary" href="${base}/export">Download project summary (JSON)</a></div></div></div>` : ''}
  </div>`;
  const c = mg.querySelector('#consent'); if (c) c.addEventListener('change', async () => { try { await RM.post(base + '/ai-consent', { consent: c.checked }); RM.alert('Consent preference saved.', 'success'); } catch (e) { RM.showError(e); c.checked = !c.checked; } });
  const stf = mg.querySelector('#stf'); if (stf) { const L = await RM.get('/api/lookups/structure'); const sel = stf.querySelector('#st'); sel.innerHTML = L.stages.map((s) => `<option value="${s}" ${s === p.stage ? 'selected' : ''}>${RM.label(s)}</option>`).join(''); stf.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await RM.post(base + '/stage', { stage: sel.value, notes: stf.querySelector('#stn').value }); location.reload(); } catch (e) { RM.showError(e); } }); }
  const asf = mg.querySelector('#asf'); if (asf) { const sups = await RM.get('/api/users?role=supervisor'); asf.querySelector('#asu').innerHTML = sups.map((s) => `<option value="${RM.esc(s.id)}">${RM.esc(s.name)}</option>`).join(''); asf.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await RM.post(base + '/members', { user_id: asf.querySelector('#asu').value, role: 'supervisor' }); location.reload(); } catch (e) { RM.showError(e); } }); }
  const ar = mg.querySelector('#arch'); if (ar) ar.addEventListener('click', async () => { if (!confirm('Are you sure?')) return; try { await RM.post(base + '/archive', { restore: p.status === 'archived' }); location.reload(); } catch (e) { RM.showError(e); } });
};
