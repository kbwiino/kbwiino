RM.pages['projects.index'] = async function (root) {
  const canCreate = RM.hasRole('student', 'coordinator', 'grad_admin');
  root.innerHTML = `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h1 class="h3 mb-0">Research projects</h1>
      ${canCreate ? '<a class="btn btn-primary" href="/projects/new"><i class="bi bi-plus-lg"></i> New project</a>' : ''}
    </div>
    <form id="filters" class="row g-2 mb-3">
      <div class="col-md-5"><label class="visually-hidden" for="q">Search</label><input id="q" class="form-control" placeholder="Search title, area or student"></div>
      <div class="col-md-3"><label class="visually-hidden" for="stage">Stage</label><select id="stage" class="form-select"><option value="">All stages</option></select></div>
      <div class="col-md-2"><label class="visually-hidden" for="status">Status</label><select id="status" class="form-select"><option value="">Active</option><option value="archived">Archived</option><option value="completed">Completed</option><option value="on_hold">On hold</option></select></div>
      <div class="col-md-2"><button class="btn btn-outline-secondary w-100" type="submit">Filter</button></div>
    </form>
    <div id="list">${RM.spinner()}</div>`;
  const lookups = await RM.get('/api/lookups/structure');
  const stageSel = root.querySelector('#stage');
  lookups.stages.forEach((s) => stageSel.insertAdjacentHTML('beforeend', `<option value="${RM.esc(s)}">${RM.label(s)}</option>`));
  async function load() {
    const p = new URLSearchParams({ q: root.querySelector('#q').value, stage: stageSel.value, status: root.querySelector('#status').value });
    const list = root.querySelector('#list');
    try {
      const rows = await RM.get('/api/projects?' + p);
      if (!rows.length) { list.innerHTML = '<div class="alert alert-info">No projects found.</div>'; return; }
      list.innerHTML = `<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Title</th><th>Student</th><th>Supervisor</th><th>Stage</th><th>Status</th><th>Updated</th></tr></thead><tbody>
        ${rows.map((r) => `<tr><td><a href="/projects/${RM.esc(r.id)}">${RM.esc(r.title)}</a><div class="small text-muted">${RM.esc(r.research_area)}</div></td>
          <td>${RM.esc(r.student)}</td><td>${r.supervisor ? RM.esc(r.supervisor.name) : '<span class="text-muted">Unassigned</span>'}</td>
          <td><span class="badge text-bg-primary">${RM.label(r.stage)}</span></td><td>${RM.label(r.status)}</td><td>${RM.fmtDate(r.updated_at)}</td></tr>`).join('')}
        </tbody></table></div></div>`;
    } catch (e) { RM.showError(e); }
  }
  root.querySelector('#filters').addEventListener('submit', (ev) => { ev.preventDefault(); load(); });
  load();
};
