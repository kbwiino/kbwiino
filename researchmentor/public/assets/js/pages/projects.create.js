RM.pages['projects.create'] = async function (root) {
  const L = await RM.get('/api/lookups/structure');
  const isStudent = RM.hasRole('student');
  const sups = await RM.get('/api/users?role=supervisor');
  const students = isStudent ? [] : await RM.get('/api/users?role=student');
  const opt = (arr, f) => arr.map((x) => `<option value="${RM.esc(x.id)}">${RM.esc(f(x))}</option>`).join('');
  root.innerHTML = `
  <h1 class="h3 mb-3">New research project</h1>
  <form id="f" class="card"><div class="card-body row g-3">
    ${isStudent ? '' : `<div class="col-md-6"><label class="form-label" for="student_id">Student *</label><select class="form-select" id="student_id" name="student_id" required><option value="">Select…</option>${opt(students, (s) => s.name)}</select></div>`}
    <div class="col-md-6"><label class="form-label" for="supervisor_id">Assigned supervisor ${isStudent ? '*' : ''}</label><select class="form-select" id="supervisor_id" name="supervisor_id" ${isStudent ? 'required' : ''}><option value="">Select…</option>${opt(sups, (s) => s.name + (s.department ? ' — ' + s.department : ''))}</select></div>
    <div class="col-md-4"><label class="form-label" for="faculty_id">Faculty *</label><select class="form-select" id="faculty_id" name="faculty_id" required><option value="">Select…</option>${opt(L.faculties, (f) => f.name)}</select></div>
    <div class="col-md-4"><label class="form-label" for="department_id">Department *</label><select class="form-select" id="department_id" name="department_id" required></select></div>
    <div class="col-md-4"><label class="form-label" for="programme_id">Programme *</label><select class="form-select" id="programme_id" name="programme_id" required></select></div>
    <div class="col-md-4"><label class="form-label" for="degree_level">Degree level *</label><select class="form-select" id="degree_level" name="degree_level"><option value="masters">Master's</option><option value="phd">PhD</option></select></div>
    <div class="col-md-4"><label class="form-label" for="academic_year_id">Academic year *</label><select class="form-select" id="academic_year_id" name="academic_year_id" required>${opt(L.academic_years, (y) => y.label)}</select></div>
    <div class="col-md-4"><label class="form-label" for="stage">Research stage</label><select class="form-select" id="stage" name="stage">${L.stages.map((s) => `<option value="${s}">${RM.label(s)}</option>`).join('')}</select></div>
    <div class="col-12"><label class="form-label" for="title">Research title *</label><input class="form-control" id="title" name="title" maxlength="500" required></div>
    <div class="col-md-6"><label class="form-label" for="research_area">Research area *</label><input class="form-control" id="research_area" name="research_area" maxlength="255" required></div>
    <div class="col-md-3"><label class="form-label" for="approach">Research approach *</label><select class="form-select" id="approach" name="approach"><option value="quantitative">Quantitative</option><option value="qualitative">Qualitative</option><option value="mixed">Mixed methods</option><option value="other">Other</option></select></div>
    <div class="col-md-3"><label class="form-label" for="design">Research design *</label><input class="form-control" id="design" name="design" maxlength="255" required placeholder="e.g. Cross-sectional survey"></div>
    <div class="col-12"><label class="form-label" for="problem_statement">Research problem *</label><textarea class="form-control" id="problem_statement" name="problem_statement" rows="4" required></textarea></div>
    <div class="col-12"><label class="form-label" for="purpose">Research purpose *</label><textarea class="form-control" id="purpose" name="purpose" rows="3" required></textarea></div>
    <details class="col-12"><summary class="mb-2">Methodology details (optional now; can be added later)</summary><div class="row g-3">
      <div class="col-md-6"><label class="form-label" for="paradigm">Paradigm</label><input class="form-control" id="paradigm" name="paradigm"></div>
      <div class="col-md-6"><label class="form-label" for="population">Population</label><input class="form-control" id="population" name="population"></div>
      <div class="col-md-6"><label class="form-label" for="sampling_approach">Sampling approach</label><textarea class="form-control" id="sampling_approach" name="sampling_approach" rows="2"></textarea></div>
      <div class="col-md-6"><label class="form-label" for="data_collection_methods">Data collection methods</label><textarea class="form-control" id="data_collection_methods" name="data_collection_methods" rows="2"></textarea></div>
      <div class="col-md-6"><label class="form-label" for="data_analysis_methods">Data analysis methods</label><textarea class="form-control" id="data_analysis_methods" name="data_analysis_methods" rows="2"></textarea></div>
      <div class="col-md-6"><label class="form-label" for="ethics_information">Ethics information</label><textarea class="form-control" id="ethics_information" name="ethics_information" rows="2"></textarea></div>
    </div></details>
  </div><div class="card-footer text-end"><a class="btn btn-link" href="/projects">Cancel</a><button class="btn btn-primary" type="submit">Create project</button></div></form>`;
  const f = root.querySelector('#f');
  const fac = f.faculty_id, dep = f.department_id, prog = f.programme_id;
  const refreshDeps = () => { const F = L.faculties.find((x) => x.id === fac.value); dep.innerHTML = '<option value="">Select…</option>' + opt(F ? F.departments : [], (d) => d.name); refreshProgs(); };
  const refreshProgs = () => { const F = L.faculties.find((x) => x.id === fac.value); const D = F && F.departments.find((x) => x.id === dep.value); prog.innerHTML = '<option value="">Select…</option>' + (D ? D.programmes.map((p) => `<option value="${RM.esc(p.id)}" data-level="${p.degree_level}">${RM.esc(p.name)} (${RM.label(p.degree_level)})</option>`).join('') : ''); };
  fac.addEventListener('change', refreshDeps); dep.addEventListener('change', refreshProgs);
  prog.addEventListener('change', () => { const o = prog.selectedOptions[0]; if (o && o.dataset.level) f.degree_level.value = o.dataset.level; });
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const body = RM.formData(f); Object.keys(body).forEach((k) => { if (body[k] === '') delete body[k]; });
    try { const p = await RM.post('/api/projects', body); location.href = '/projects/' + p.id; } catch (e) { RM.showError(e); }
  });
};
