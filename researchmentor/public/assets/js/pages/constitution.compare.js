RM.pages['constitution.compare'] = async function (root, id) {
  const base = `/api/projects/${id}/constitution`;
  const d = await RM.get(base);
  const opts = (sel) => d.versions.map((v) => `<option value="${v.version_number}" ${String(sel) === String(v.version_number) ? 'selected' : ''}>Constitution v${v.version_number} (${RM.label(v.status)})</option>`).join('') + '<option value="working" ' + (sel === 'working' ? 'selected' : '') + '>Working project data (unapproved)</option>';
  root.innerHTML = `<a href="/projects/${id}/constitution" class="small">&larr; Research Constitution</a><h1 class="h3 mb-3">Constitution comparison</h1>
  <form id="cf" class="row g-2 mb-3"><div class="col-md-5"><label class="form-label" for="from">From</label><select class="form-select" id="from">${opts(d.versions.length ? d.versions[0].version_number : '')}</select></div>
  <div class="col-md-5"><label class="form-label" for="to">To</label><select class="form-select" id="to">${opts('working')}</select></div><div class="col-md-2 align-self-end"><button class="btn btn-primary w-100">Compare</button></div></form>
  <div id="alerts"></div><div id="out"></div>`;
  async function run() {
    const out = root.querySelector('#out'); out.innerHTML = RM.spinner();
    try {
      const r = await RM.get(`${base}/compare?from=${root.querySelector('#from').value}&to=${root.querySelector('#to').value}`);
      const changed = r.rows.filter((x) => x.changed).length;
      out.innerHTML = `<div class="mb-2 small">Comparing <strong>${RM.esc(r.from.label)}</strong> → <strong>${RM.esc(r.to.label)}</strong>: ${changed} field(s) differ. ${!r.to.official || !r.from.official ? '<span class="badge badge-ai">Includes unapproved data — suggestions requiring human review</span>' : ''}</div>
      <div class="card"><ul class="list-group list-group-flush">${r.rows.map((x) => `<li class="list-group-item ${x.changed ? '' : 'text-muted'}"><div class="fw-semibold">${RM.label(x.field)} ${x.changed ? '<span class="badge text-bg-warning">changed</span>' : ''}</div><div style="white-space:pre-wrap">${x.changed ? x.diff.map((o) => o.op === 'add' ? `<span class="diff-add">${RM.esc(o.text)}</span>` : o.op === 'del' ? `<span class="diff-del">${RM.esc(o.text)}</span>` : RM.esc(o.text)).join('') : RM.esc(typeof x.to === 'object' ? JSON.stringify(x.to) : (x.to || '—'))}</div></li>`).join('')}</ul></div>`;
    } catch (e) { RM.showError(e); out.innerHTML = ''; }
  }
  root.querySelector('#cf').addEventListener('submit', (ev) => { ev.preventDefault(); run(); });
  run();
};
