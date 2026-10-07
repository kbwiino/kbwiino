RM.pages['reviews.index'] = async function (root, id) {
  const proj = await RM.get('/api/projects/' + id);
  const status = await RM.get('/api/ai/status');
  const rows = await RM.get(`/api/projects/${id}/reviews`);
  const docs = await RM.get(`/api/projects/${id}/documents`);
  root.innerHTML = `<a href="/projects/${id}" class="small">&larr; ${RM.esc(proj.title)}</a><h1 class="h3 mb-3">AI research assistant &amp; reviews</h1><div id="alerts"></div>
  <div class="ai-box mb-3 small"><i class="bi bi-robot"></i> <strong>AI suggestions only.</strong> Reviews are not grades or official assessments. ${status.mock ? '<span class="badge badge-ai">Development mock AI active</span>' : ''} ${RM.esc(status.disclosure)}</div>
  ${proj.permissions.run_ai ? `<div class="card mb-4"><div class="card-header">Run a review</div><form id="rf" class="card-body row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label" for="rtype">Review type</label><select class="form-select" id="rtype">${status.review_types.map((t) => `<option value="${t.type}" data-doc="${t.needs_document}">${RM.esc(t.label)}</option>`).join('')}<option value="constitution_check" data-doc="true">Constitution consistency check</option></select></div>
    <div class="col-md-5"><label class="form-label" for="rdoc">Document</label><select class="form-select" id="rdoc">${docs.map((d) => `<option value="${RM.esc(d.id)}">${RM.esc(d.title)} (v${d.current_version.version}${d.current_version.extraction_status !== 'completed' ? ', not extracted' : ''})</option>`).join('')}</select></div>
    <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Run review</button></div><div class="col-12 small text-muted">${status.remaining_this_hour} AI requests left this hour. The alignment review uses your recorded objectives, questions, hypotheses and variables.</div><div class="col-12" id="job"></div></form></div>` : ''}
  <div class="card"><div class="card-header">Review history</div>${rows.length ? `<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Review</th><th>Document</th><th>Open findings</th><th>Human review</th><th>Created</th></tr></thead><tbody>${rows.map((r) => `<tr><td><a href="/reviews/${RM.esc(r.id)}">${RM.label(r.type)}</a> ${RM.originBadge('ai_suggestion')}${r.ai.mock ? ' <span class="badge text-bg-secondary">mock</span>' : ''}</td><td>${r.document ? RM.esc(r.document.title) : '—'}</td><td>${r.open_issues}</td><td><span class="badge text-bg-${r.human_review.status === 'unreviewed' ? 'warning' : 'success'}">${RM.label(r.human_review.status)}</span></td><td>${RM.fmtDateTime(r.created_at)}</td></tr>`).join('')}</tbody></table></div>` : '<div class="card-body text-muted">No reviews yet.</div>'}</div>`;
  const rf = root.querySelector('#rf');
  if (rf) {
    const sync = () => { const needs = rf.querySelector('#rtype').selectedOptions[0].dataset.doc === 'true'; rf.querySelector('#rdoc').disabled = !needs; };
    rf.querySelector('#rtype').addEventListener('change', sync); sync();
    const map = { proposal: 'proposal-review', literature: 'literature-review', methodology: 'methodology-review', alignment: 'alignment-review', gap: 'gap-analysis', writing: 'writing-review', integrity: 'integrity-review', statistics: 'statistics-review', constitution_check: 'constitution-check' };
    rf.addEventListener('submit', async (ev) => {
      ev.preventDefault(); const out = rf.querySelector('#job'); const btn = rf.querySelector('button'); btn.disabled = true; out.innerHTML = RM.spinner('Running AI review…');
      try {
        const type = rf.querySelector('#rtype').value; const body = { project_id: id }; if (!rf.querySelector('#rdoc').disabled) body.document_id = rf.querySelector('#rdoc').value;
        let job = await RM.post('/api/ai/' + map[type], body);
        for (let i = 0; i < 90 && ['queued', 'running'].includes(job.status); i++) { await new Promise((r) => setTimeout(r, 2000)); job = await RM.get('/api/review-jobs/' + job.id); }
        if (job.status === 'completed') { location.href = '/reviews/' + job.review_id; return; }
        out.innerHTML = `<div class="alert alert-${job.status === 'failed' ? 'danger' : 'warning'} py-2">${RM.esc(job.status === 'failed' ? (job.error || 'The review failed.') : 'Still running; check back soon.')}</div>`;
      } catch (e) { out.innerHTML = ''; RM.showError(e); }
      btn.disabled = false;
    });
  }
};
