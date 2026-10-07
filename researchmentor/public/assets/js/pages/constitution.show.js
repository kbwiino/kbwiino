RM.pages['constitution.show'] = async function (root, id) {
  const base = `/api/projects/${id}/constitution`;
  const d = await RM.get(base);
  const proj = await RM.get(`/api/projects/${id}`);
  const labels = { title: 'Title', problem: 'Problem statement', purpose: 'Purpose', objectives: 'Objectives', questions: 'Research questions', hypotheses: 'Hypotheses', variables: 'Variables', theories: 'Theories', conceptual_framework: 'Conceptual framework', paradigm: 'Paradigm', approach: 'Approach', design: 'Design', population: 'Population', sampling_plan: 'Sampling plan', data_collection_plan: 'Data collection plan', analysis_plan: 'Analysis plan' };
  const val = (v) => Array.isArray(v) ? (v.length ? '<ol class="mb-0">' + v.map((x) => `<li>${RM.esc(typeof x === 'object' ? Object.values(x).filter(Boolean).join(' — ') : x)}</li>`).join('') + '</ol>' : '<span class="text-muted">None</span>') : (v ? RM.nl2br(v) : '<span class="text-muted">Not specified</span>');
  const c = d.current;
  root.innerHTML = `
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><a href="/projects/${id}" class="small">&larr; ${RM.esc(proj.title)}</a><h1 class="h3 mb-0">Research Constitution</h1></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-primary" href="/projects/${id}/constitution/compare">Compare versions</a></div></div>
  <div class="official-box mb-3 small"><i class="bi bi-patch-check"></i> ${RM.esc(d.notice)}</div><div id="alerts"></div>
  <div id="body"></div><div id="proposals" class="mt-4"></div>`;
  const body = root.querySelector('#body');
  if (!c) {
    body.innerHTML = `<div class="alert alert-info">No Research Constitution has been approved yet.</div>${proj.permissions.approve_constitution ? '<button class="btn btn-primary" id="init">Approve current research design as Constitution v1</button>' : '<p class="text-muted">Your supervisor will approve the initial Constitution once your research design is ready.</p>'}`;
    const b = body.querySelector('#init'); if (b) b.addEventListener('click', async () => { const notes = prompt('Approval notes (optional)') || ''; try { await RM.post(base + '/initialize', { notes }); location.reload(); } catch (e) { RM.showError(e); } });
  } else {
    body.innerHTML = `<div class="card"><div class="card-header d-flex justify-content-between"><span><span class="badge badge-human">Official</span> Version ${c.version}</span><span class="small text-muted">Approved ${RM.fmtDate(c.approved_at)} by ${RM.esc(c.approved_by.name)}</span></div>
      <div class="card-body"><dl class="row">${Object.entries(labels).map(([k, l]) => `<dt class="col-sm-3">${l}</dt><dd class="col-sm-9">${val(c.values[k])}</dd>`).join('')}</dl>${c.approval_notes ? `<div class="small text-muted">Approval notes: ${RM.esc(c.approval_notes)}</div>` : ''}</div></div>
      <div class="mt-2 small text-muted">History: ${d.versions.map((v) => `<a href="${base}/versions/${v.version_number}" data-v="${v.version_number}">v${v.version_number}</a> (${RM.label(v.status)})`).join(' · ')}</div>
      <div class="mt-3" id="inc"></div>${proj.permissions.propose_constitution ? '<button class="btn btn-outline-primary mt-3" id="propose-btn">Propose a change</button><div id="pf" class="mt-3"></div>' : ''}`;
    RM.get(base + '/inconsistencies').then((r) => {
      root.querySelector('#inc').innerHTML = r.items.length ? `<div class="ai-box"><strong><i class="bi bi-exclamation-triangle"></i> ${r.items.length} difference(s) between your working project data and the approved Constitution</strong><div class="small">${RM.esc(r.label)}</div><ul class="mb-0 mt-2">${r.items.map((i) => `<li>${RM.label(i.field)}</li>`).join('')}</ul><a href="${base}/compare">View comparison</a></div>` : '';
    }).catch(() => {});
    const pb = body.querySelector('#propose-btn');
    if (pb) pb.addEventListener('click', () => {
      const fields = ['title', 'problem', 'purpose', 'approach', 'design', 'population', 'sampling_plan', 'data_collection_plan', 'analysis_plan', 'paradigm', 'conceptual_framework'];
      root.querySelector('#pf').innerHTML = `<form id="pform" class="card card-body"><p class="small text-muted">Only fields you change are submitted. Your supervisor must approve before the Constitution changes.</p>${fields.map((k) => `<div class="mb-2"><label class="form-label small" for="p-${k}">${labels[k]}</label><textarea class="form-control" rows="2" id="p-${k}" data-k="${k}">${RM.esc(c.values[k] || '')}</textarea></div>`).join('')}<div class="mb-2"><label class="form-label" for="p-r">Rationale</label><textarea class="form-control" id="p-r" rows="2" required></textarea></div><button class="btn btn-primary">Submit proposal</button></form>`;
      root.querySelector('#pform').addEventListener('submit', async (ev) => { ev.preventDefault(); const changes = {}; ev.target.querySelectorAll('[data-k]').forEach((t) => { if (t.value !== (c.values[t.dataset.k] || '')) changes[t.dataset.k] = t.value; }); try { await RM.post(base + '/proposals', { changes, rationale: ev.target.querySelector('#p-r').value }); location.reload(); } catch (e) { RM.showError(e); } });
    });
  }
  const pp = root.querySelector('#proposals');
  const all = await RM.get(base + '/proposals');
  if (all.length) {
    pp.innerHTML = `<h2 class="h5">Change proposals</h2>` + all.map((p) => `<div class="card mb-2"><div class="card-body"><div class="d-flex justify-content-between"><div><span class="badge ${p.source === 'ai_suggestion' ? 'badge-ai' : 'badge-student'}">${RM.esc(p.label)}</span> <span class="badge text-bg-${p.status === 'pending' ? 'warning' : p.status === 'approved' ? 'success' : 'secondary'}">${RM.label(p.status)}</span></div><small class="text-muted">${RM.fmtDate(p.created_at)} by ${RM.esc(p.proposed_by.name)}</small></div>
      <div class="mt-2">${Object.entries(p.changes).map(([k, v]) => `<div><strong>${RM.label(k)}:</strong> ${RM.esc(typeof v === 'string' ? v : JSON.stringify(v))}</div>`).join('')}</div>${p.rationale ? `<div class="small text-muted mt-1">Rationale: ${RM.esc(p.rationale)}</div>` : ''}
      ${p.status === 'pending' && proj.permissions.approve_constitution ? `<div class="mt-2 d-flex gap-2"><button class="btn btn-sm btn-success" data-d="approve" data-p="${RM.esc(p.id)}">Approve (creates new official version)</button><button class="btn btn-sm btn-outline-danger" data-d="reject" data-p="${RM.esc(p.id)}">Reject</button></div>` : ''}</div></div>`).join('');
    pp.querySelectorAll('[data-d]').forEach((b) => b.addEventListener('click', async () => { const notes = prompt('Decision notes (optional)') || ''; try { await RM.post(`${base}/proposals/${b.dataset.p}/decision`, { decision: b.dataset.d, notes }); location.reload(); } catch (e) { RM.showError(e); } }));
  }
};
