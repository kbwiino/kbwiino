RM.pages['documents.show'] = async function (root, id) {
  const base = '/api/documents/' + id;
  const d = await RM.get(base);
  const aiStatus = await RM.get('/api/ai/status');
  const proj = await RM.get('/api/projects/' + d.project_id);
  const v = d.current_version;
  root.innerHTML = `<a href="/projects/${RM.esc(d.project_id)}/documents" class="small">&larr; Documents</a>
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h1 class="h3 mb-1">${RM.esc(d.title)}</h1><div class="text-muted">${RM.label(d.category)} · uploaded by ${RM.esc(d.owner.name)} · retention until ${RM.fmtDate(d.retention_until)}</div></div>
    <div class="d-flex gap-2">${d.permissions.download ? `<a class="btn btn-outline-primary btn-sm" href="${base}/download"><i class="bi bi-download"></i> Download v${v.version}</a>` : ''}${d.permissions.delete ? '<button class="btn btn-outline-danger btn-sm" id="del">Delete</button>' : ''}</div></div>
  <div id="alerts"></div>
  <div class="row g-3">
    <div class="col-lg-8">
      <ul class="nav nav-tabs mb-3"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-prev">Preview (extracted text)</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-ver">Versions (${d.versions.length})</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-com">Comments</button></li>${d.permissions.manage_access ? '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-acc">Access</button></li>' : ''}</ul>
      <div class="tab-content"><div class="tab-pane fade show active" id="t-prev"></div><div class="tab-pane fade" id="t-ver"></div><div class="tab-pane fade" id="t-com"></div><div class="tab-pane fade" id="t-acc"></div></div>
    </div>
    <div class="col-lg-4">
      <div class="card mb-3"><div class="card-header">File details</div><dl class="row small mb-0 p-3"><dt class="col-5">File</dt><dd class="col-7">${RM.esc(v.filename)}</dd><dt class="col-5">Size</dt><dd class="col-7">${Math.round(v.size_bytes / 1024)} KB</dd><dt class="col-5">Words</dt><dd class="col-7">${v.word_count ?? '—'}</dd><dt class="col-5">Pages</dt><dd class="col-7">${v.page_count ?? '—'}</dd><dt class="col-5">Extraction</dt><dd class="col-7"><span class="badge text-bg-${v.extraction_status === 'completed' ? 'success' : v.extraction_status === 'failed' ? 'danger' : 'secondary'}">${RM.label(v.extraction_status)}</span>${v.extraction_error ? `<div class="text-danger">${RM.esc(v.extraction_error)}</div>` : ''}</dd><dt class="col-5">SHA-256</dt><dd class="col-7 text-break"><code class="small">${RM.esc(v.sha256.slice(0, 16))}…</code></dd></dl></div>
      ${d.permissions.run_review ? `<div class="card mb-3"><div class="card-header"><i class="bi bi-robot"></i> AI review</div><div class="card-body">
        ${aiStatus.mock ? '<div class="alert alert-warning py-2 small">Development mock AI is active - output is placeholder only.</div>' : ''}
        <p class="small text-muted mb-2">${RM.esc(aiStatus.disclosure)}</p>
        ${!aiStatus.mock && !proj.ai_consent ? '<div class="alert alert-danger py-2 small">AI processing is not enabled for this project. The project owner must consent first (project &rarr; Manage).</div>' : ''}
        <label class="form-label small" for="rt">Review type</label><select class="form-select form-select-sm mb-2" id="rt">${aiStatus.review_types.filter((t) => t.needs_document).map((t) => `<option value="${t.type}">${RM.esc(t.label)}</option>`).join('')}<option value="constitution_check">Constitution consistency check</option></select>
        <button class="btn btn-primary btn-sm w-100" id="run" ${v.extraction_status !== 'completed' ? 'disabled' : ''}>Run AI review</button><div class="small text-muted mt-1">${aiStatus.remaining_this_hour} AI requests left this hour</div><div id="job" class="mt-2"></div></div></div>` : ''}
      <div class="card"><div class="card-header">AI review status</div><div class="card-body small">${d.ai_review ? `<a href="/reviews/${RM.esc(d.ai_review.id)}">Latest: ${RM.label(d.ai_review.type)} review</a><br><span class="badge text-bg-${d.ai_review.human_status === 'unreviewed' ? 'warning' : 'success'}">${d.ai_review.human_status === 'unreviewed' ? 'Awaiting supervisor review' : 'Supervisor: ' + d.ai_review.human_status}</span>` : 'No AI review yet.'}</div></div>
    </div></div>`;

  // preview
  let page = 1;
  async function preview() {
    const p = await RM.get(`${base}/preview?page=${page}`);
    const box = root.querySelector('#t-prev');
    if (p.extraction_status !== 'completed') { box.innerHTML = `<div class="alert alert-${p.extraction_status === 'failed' ? 'danger' : 'info'}">Text extraction: ${RM.label(p.extraction_status)}. ${RM.esc(p.extraction_error || '')}</div>`; return; }
    const total = p.total_segments || 0; const pages = Math.max(1, Math.ceil(total / 20));
    box.innerHTML = `${p.inline_pdf_available ? `<a class="btn btn-sm btn-outline-secondary mb-2" target="_blank" rel="noopener" href="${base}/download?inline=1">Open PDF in new tab</a>` : ''}
      ${p.segments.map((s) => `<div class="mb-3"><div class="small text-muted">${s.page ? 'Page ' + s.page : ''}${s.page && s.section ? ' · ' : ''}${s.section ? RM.esc(s.section) : ''}</div><pre class="doc-text" style="max-height:none">${RM.esc(s.content)}</pre></div>`).join('')}
      <nav class="d-flex justify-content-between"><button class="btn btn-sm btn-outline-secondary" id="pp" ${page <= 1 ? 'disabled' : ''}>Previous</button><span class="small text-muted align-self-center">Section ${page} of ${pages}</span><button class="btn btn-sm btn-outline-secondary" id="np" ${page >= pages ? 'disabled' : ''}>Next</button></nav>`;
    const pp = box.querySelector('#pp'), np = box.querySelector('#np');
    pp.addEventListener('click', () => { page--; preview(); }); np.addEventListener('click', () => { page++; preview(); });
  }
  preview().catch((e) => RM.showError(e));

  // versions + compare + new version
  const vb = root.querySelector('#t-ver');
  vb.innerHTML = `<div class="card mb-3"><ul class="list-group list-group-flush">${d.versions.map((x) => `<li class="list-group-item d-flex justify-content-between align-items-center"><div><strong>Version ${x.version}</strong> <span class="text-muted small">${RM.fmtDateTime(x.uploaded_at)} · ${RM.esc(x.uploaded_by ? x.uploaded_by.name : '')}</span>${x.change_note ? `<div class="small">${RM.esc(x.change_note)}</div>` : ''}</div>${d.permissions.download && x.file_available ? `<a class="btn btn-sm btn-outline-secondary" href="${base}/versions/${x.version}/download">Download</a>` : '<span class="small text-muted">file removed</span>'}</li>`).join('')}</ul></div>
    ${d.versions.length > 1 ? `<form id="cmp" class="row g-2 mb-3"><div class="col-auto"><label class="visually-hidden" for="cf">From</label><select id="cf" class="form-select form-select-sm">${d.versions.map((x) => `<option value="${x.version}">v${x.version}</option>`).join('')}</select></div><div class="col-auto align-self-center">to</div><div class="col-auto"><label class="visually-hidden" for="ct">To</label><select id="ct" class="form-select form-select-sm">${d.versions.map((x, i) => `<option value="${x.version}" ${i === 0 ? 'selected' : ''}>v${x.version}</option>`).join('')}</select></div><div class="col-auto"><button class="btn btn-sm btn-outline-primary">Compare</button></div></form><div id="cmp-out"></div>` : ''}
    ${d.permissions.new_version ? '<form id="nv" class="card card-body" enctype="multipart/form-data"><h2 class="h6">Upload a new version</h2><input class="form-control mb-2" type="file" name="file" required aria-label="New version file"><input class="form-control mb-2" name="change_note" maxlength="500" placeholder="What changed? (optional)" aria-label="Change note"><button class="btn btn-primary btn-sm align-self-start">Upload version</button></form>' : ''}`;
  const cmp = vb.querySelector('#cmp');
  if (cmp) { cmp.querySelector('#ct').value = d.versions[0].version; cmp.querySelector('#cf').value = d.versions[d.versions.length - 1].version; cmp.addEventListener('submit', async (ev) => { ev.preventDefault(); const out = vb.querySelector('#cmp-out'); out.innerHTML = RM.spinner(); try { const c = await RM.get(`${base}/compare?from=${cmp.querySelector('#cf').value}&to=${cmp.querySelector('#ct').value}`); out.innerHTML = `<div class="small mb-2">+${c.stats.added_words} / −${c.stats.removed_words} words (v${c.from} → v${c.to})</div><pre class="doc-text" style="max-height:60vh">${c.diff.map((o) => o.op === 'add' ? `<span class="diff-add">${RM.esc(o.text)}</span>` : o.op === 'del' ? `<span class="diff-del">${RM.esc(o.text)}</span>` : RM.esc(o.text)).join('')}</pre>`; } catch (e) { out.innerHTML = ''; RM.showError(e); } }); }
  const nv = vb.querySelector('#nv');
  if (nv) nv.addEventListener('submit', async (ev) => { ev.preventDefault(); const fd = new FormData(nv); fd.append('document_id', id); try { await RM.api('POST', '/api/documents/upload', fd); location.reload(); } catch (e) { RM.showError(e); } });

  // comments
  async function comments() {
    const list = await RM.get(base + '/comments');
    root.querySelector('#t-com').innerHTML = `<div class="mb-3">${list.map((c) => `<div class="card mb-2"><div class="card-body py-2"><div class="d-flex justify-content-between"><div>${RM.originBadge(c.origin)} <strong>${RM.esc(c.author)}</strong>${c.page ? ` <span class="small text-muted">p.${c.page}</span>` : ''}</div><span class="small text-muted">${RM.fmtDateTime(c.created_at)}</span></div><div>${RM.nl2br(c.body)}</div>${c.status === 'open' && d.permissions.comment ? `<button class="btn btn-link btn-sm p-0" data-res="${RM.esc(c.id)}">Mark resolved</button>` : `<span class="badge text-bg-success">${c.status}</span>`}</div></div>`).join('') || '<div class="text-muted">No comments.</div>'}</div>
      ${d.permissions.comment ? '<form id="cf2" class="card card-body"><label class="form-label" for="cb">Add a comment</label><textarea class="form-control mb-2" id="cb" rows="2" required maxlength="5000"></textarea><div class="row g-2"><div class="col-4"><label class="visually-hidden" for="cp">Page</label><input class="form-control form-control-sm" id="cp" type="number" min="1" placeholder="Page (optional)"></div><div class="col-auto"><button class="btn btn-primary btn-sm">Post comment</button></div></div></form>' : ''}`;
    root.querySelectorAll('[data-res]').forEach((b) => b.addEventListener('click', async () => { try { await RM.post(`${base}/comments/${b.dataset.res}/resolve`); comments(); } catch (e) { RM.showError(e); } }));
    const f = root.querySelector('#cf2'); if (f) f.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await RM.post(base + '/comments', { body: f.querySelector('#cb').value, page: f.querySelector('#cp').value || null }); comments(); } catch (e) { RM.showError(e); } });
  }
  comments().catch(() => {});

  // access
  if (d.permissions.manage_access) {
    const acc = root.querySelector('#t-acc');
    const canList = RM.hasRole('supervisor', 'coordinator', 'grad_admin'); // students cannot enumerate examiners
    const examiners = canList ? await RM.get('/api/users?role=external_examiner').catch(() => []) : [];
    acc.innerHTML = `<div class="card card-body"><p class="small text-muted">Grant a specific person access to this document (for example an assigned external examiner). Grants can expire.</p><form id="gf" class="row g-2"><div class="col-md-5"><label class="visually-hidden" for="gu">Person</label><select class="form-select" id="gu">${examiners.map((x) => `<option value="${RM.esc(x.id)}">${RM.esc(x.name)}</option>`).join('') || '<option value="">' + (canList ? 'No external examiners available' : 'Ask your supervisor to share this document with an examiner') + '</option>'}</select></div><div class="col-md-3"><label class="visually-hidden" for="gp">Permission</label><select class="form-select" id="gp"><option value="view">View</option><option value="download">Download</option><option value="comment">Comment</option></select></div><div class="col-md-3"><label class="visually-hidden" for="ge">Expires</label><input class="form-control" id="ge" type="date"></div><div class="col-md-1"><button class="btn btn-primary w-100">Grant</button></div></form></div>`;
    acc.querySelector('#gf').addEventListener('submit', async (ev) => { ev.preventDefault(); try { await RM.post(base + '/access', { user_id: acc.querySelector('#gu').value, permission: acc.querySelector('#gp').value, expires_at: acc.querySelector('#ge').value || null }); RM.alert('Access granted.', 'success'); } catch (e) { RM.showError(e); } });
  }

  const del = root.querySelector('#del'); if (del) del.addEventListener('click', async () => { if (!confirm('Delete this document? It is hidden immediately and the stored files are purged when the retention period ends.')) return; try { await RM.del(base); location.href = `/projects/${d.project_id}/documents`; } catch (e) { RM.showError(e); } });

  // run review + poll
  const run = root.querySelector('#run');
  if (run) run.addEventListener('click', async () => {
    const out = root.querySelector('#job'); run.disabled = true; out.innerHTML = RM.spinner('Running AI review…');
    try {
      let job = await RM.post(base + '/review', { review_type: root.querySelector('#rt').value });
      for (let i = 0; i < 90 && ['queued', 'running'].includes(job.status); i++) { await new Promise((r) => setTimeout(r, 2000)); job = await RM.get('/api/review-jobs/' + job.id); }
      if (job.status === 'completed') { location.href = '/reviews/' + job.review_id; return; }
      out.innerHTML = `<div class="alert alert-${job.status === 'failed' ? 'danger' : 'warning'} py-2 small">${job.status === 'failed' ? RM.esc(job.error || 'The review failed.') : 'Still running - check back soon.'}</div>`;
    } catch (e) { out.innerHTML = ''; RM.showError(e); }
    run.disabled = false;
  });
};
