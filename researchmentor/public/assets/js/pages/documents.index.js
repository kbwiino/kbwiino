RM.pages['documents.index'] = async function (root, id) {
  const proj = await RM.get(`/api/projects/${id}`);
  const CATS = ['proposal', 'chapter_1', 'chapter_2', 'chapter_3', 'chapter_4', 'chapter_5', 'complete_thesis', 'dissertation', 'journal_article', 'dataset', 'supervisor_feedback', 'institutional_guideline', 'other'];
  const canUpload = RM.hasRole('student') || RM.hasRole('supervisor');
  root.innerHTML = `<a href="/projects/${id}" class="small">&larr; ${RM.esc(proj.title)}</a><h1 class="h3 mb-3">Documents</h1><div id="alerts"></div>
  ${canUpload ? `<div class="card mb-4"><div class="card-header">Upload a document</div><form id="uf" class="card-body row g-3" enctype="multipart/form-data">
    <div class="col-md-4"><label class="form-label" for="u-file">File (PDF, DOCX, TXT, XLSX, CSV)</label><input class="form-control" id="u-file" type="file" name="file" accept=".pdf,.docx,.txt,.xlsx,.csv" required></div>
    <div class="col-md-3"><label class="form-label" for="u-cat">Category</label><select class="form-select" id="u-cat" name="category">${CATS.map((c) => `<option value="${c}">${RM.label(c)}</option>`).join('')}</select></div>
    <div class="col-md-3"><label class="form-label" for="u-title">Title</label><input class="form-control" id="u-title" name="title" maxlength="300" placeholder="Defaults to the file name"></div>
    <div class="col-md-2"><label class="form-label" for="u-vis">Visibility</label><select class="form-select" id="u-vis" name="visibility"><option value="shared">Shared with supervisors</option><option value="private">Private draft</option></select></div>
    <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Upload</button> <span class="small text-muted ms-2">Files are stored privately and checked for type and content. Max size is set by the administrator.</span></div></form></div>` : ''}
  <div id="list">${RM.spinner()}</div>`;
  async function load() {
    const rows = await RM.get(`/api/projects/${id}/documents`);
    const st = { ai_reviewed_pending_human: ['warning', 'AI reviewed - awaiting supervisor'], human_reviewed: ['success', 'Supervisor reviewed'], not_reviewed: ['secondary', 'Not reviewed'] };
    root.querySelector('#list').innerHTML = rows.length ? `<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Title</th><th>Category</th><th>Version</th><th>Extraction</th><th>AI review</th><th>Updated</th></tr></thead><tbody>${rows.map((d) => `<tr><td><a href="/documents/${RM.esc(d.id)}">${RM.esc(d.title)}</a>${d.visibility === 'private' ? ' <span class="badge text-bg-secondary">private</span>' : ''}<div class="small text-muted">${RM.esc(d.current_version.filename)} · ${Math.round(d.current_version.size_bytes / 1024)} KB</div></td><td>${RM.label(d.category)}</td><td>v${d.current_version.version}</td><td><span class="badge text-bg-${d.current_version.extraction_status === 'completed' ? 'success' : d.current_version.extraction_status === 'failed' ? 'danger' : 'secondary'}">${RM.label(d.current_version.extraction_status)}</span></td><td><span class="badge text-bg-${st[d.ai_review_status][0]}">${st[d.ai_review_status][1]}</span></td><td>${RM.fmtDate(d.updated_at)}</td></tr>`).join('')}</tbody></table></div></div>` : '<div class="alert alert-info">No documents yet.</div>';
  }
  const uf = root.querySelector('#uf');
  if (uf) uf.addEventListener('submit', async (ev) => {
    ev.preventDefault(); const fd = new FormData(uf); fd.append('project_id', id); if (!fd.get('title')) fd.delete('title');
    const btn = uf.querySelector('button'); btn.disabled = true;
    try { const d = await RM.api('POST', '/api/documents/upload', fd); location.href = '/documents/' + d.id; } catch (e) { RM.showError(e); btn.disabled = false; }
  });
  load();
};
