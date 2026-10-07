RM.pages['dashboard'] = async function (root) {
  const { dashboards } = await RM.get('/api/dashboard');
  root.innerHTML = '<div id="alerts"></div>' + dashboards.map((d) => `<section id="d-${d}" class="mb-5"></section>`).join('') || '<div class="alert alert-info">No dashboard is available for your role.</div>';
  const PAL = ['#2a7de1', '#e8833a', '#2ca58d', '#8e6bbf', '#c9484a', '#7f8c8d', '#d4a72c'];
  const stageLabel = (s) => String(s).replace(/_/g, ' ');
  const tile = (label, value, cls = '') => `<div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-3"><div class="small text-muted">${label}</div><div class="fs-3 fw-semibold ${cls}">${RM.esc(value)}</div></div></div></div>`;
  const attn = (a) => a.needs_attention ? `<span class="badge text-bg-warning" title="${RM.esc(a.reasons.join('; '))}">Needs attention</span><div class="small text-muted">${RM.esc(a.reasons.join('; '))}</div>` : '<span class="badge text-bg-success">No flags</span>';

  for (const kind of dashboards) {
    const box = root.querySelector('#d-' + kind);
    try {
      if (kind === 'student') await student(box);
      else if (kind === 'supervisor') await supervisor(box);
      else if (kind === 'administrator') await admin(box);
      else if (kind === 'examiner') await examiner(box);
    } catch (e) { box.innerHTML = `<div class="alert alert-danger">${RM.esc(e.message)}</div>`; }
  }

  async function student(box) {
    const d = await RM.get('/api/dashboard/student');
    if (!d.projects.length) { box.innerHTML = `<h1 class="h3">Welcome, ${RM.esc(d.student.name)}</h1><div class="alert alert-info">You have no research project yet. <a href="/projects/new">Create one</a>.</div>`; return; }
    box.innerHTML = `<h1 class="h3 mb-3">Welcome, ${RM.esc(d.student.name)}</h1>` + d.projects.map((p) => `
      <div class="card mb-4"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2"><div><h2 class="h5 mb-1"><a href="/projects/${RM.esc(p.id)}">${RM.esc(p.title)}</a></h2><div class="text-muted small">${RM.esc(p.programme)} · Supervisor: ${p.supervisor ? RM.esc(p.supervisor.name) : 'Unassigned'}</div></div>
        <div><span class="badge text-bg-primary fs-6">${RM.label(p.stage)}</span></div></div>
        ${p.progress.attention.needs_attention ? `<div class="alert alert-warning mt-3 mb-0 py-2 small"><strong>For your attention:</strong> ${RM.esc(p.progress.attention.reasons.join('; '))}</div>` : ''}
        <div class="row g-3 mt-1">
          <div class="col-lg-4"><h3 class="h6">Milestones</h3><ul class="list-unstyled small mb-0">${p.milestones.map((m) => `<li><i class="bi ${m.done ? 'bi-check-circle-fill text-success' : 'bi-circle'}"></i> ${RM.esc(m.title)} <span class="text-muted">(${RM.fmtDate(m.due_date)})</span></li>`).join('') || '<li class="text-muted">None</li>'}</ul></div>
          <div class="col-lg-4"><h3 class="h6">Pending tasks</h3><ul class="list-unstyled small mb-0">${p.pending_tasks.map((t) => `<li>${t.overdue ? '<span class="badge text-bg-danger">overdue</span> ' : ''}${RM.esc(t.title)} <span class="text-muted">${t.due_date ? '(' + RM.fmtDate(t.due_date) + ')' : ''}</span></li>`).join('') || '<li class="text-muted">None</li>'}</ul><a class="small" href="/projects/${RM.esc(p.id)}/supervision">All tasks &rarr;</a></div>
          <div class="col-lg-4"><h3 class="h6">Upcoming meetings</h3><ul class="list-unstyled small mb-0">${p.upcoming_meetings.map((m) => `<li><i class="bi bi-calendar-event"></i> ${RM.esc(m.title)} <span class="text-muted">${RM.fmtDateTime(m.scheduled_at)}</span></li>`).join('') || '<li class="text-muted">None scheduled</li>'}</ul></div>
          <div class="col-lg-4"><h3 class="h6">Recent documents</h3><ul class="list-unstyled small mb-0">${p.recent_documents.map((x) => `<li><a href="/documents/${RM.esc(x.id)}">${RM.esc(x.title)}</a></li>`).join('') || '<li class="text-muted">None</li>'}</ul><a class="small" href="/projects/${RM.esc(p.id)}/documents">Upload / manage &rarr;</a></div>
          <div class="col-lg-4"><h3 class="h6">Supervisor feedback</h3><ul class="list-unstyled small mb-0">${p.supervisor_feedback.map((f) => `<li class="mb-1">${RM.originBadge('supervisor')} ${RM.esc(f.body)} ${f.status === 'open' ? '<span class="badge text-bg-warning">open</span>' : ''}</li>`).join('') || '<li class="text-muted">None</li>'}</ul></div>
          <div class="col-lg-4"><h3 class="h6">AI review history</h3><ul class="list-unstyled small mb-0">${p.ai_review_history.map((r) => `<li><a href="/reviews/${RM.esc(r.id)}">${RM.label(r.type)} review</a> ${RM.originBadge('ai_suggestion')} <span class="text-muted">${RM.label(r.human_status)}${r.mock ? ' · mock' : ''}</span></li>`).join('') || '<li class="text-muted">None</li>'}</ul></div>
        </div></div>
        <div class="card-footer d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-primary" href="/projects/${RM.esc(p.id)}/reviews"><i class="bi bi-robot"></i> Research assistant</a><a class="btn btn-sm btn-outline-primary" href="/projects/${RM.esc(p.id)}/viva"><i class="bi bi-chat-square-quote"></i> Practise viva</a><a class="btn btn-sm btn-outline-primary" href="/knowledge"><i class="bi bi-book"></i> Ask the guidelines</a></div>
      </div>`).join('') + `<p class="small text-muted">${RM.esc(d.research_assistant.note)}${d.research_assistant.mock ? ' <span class="badge badge-ai">Development mock AI</span>' : ''}</p>`;
  }

  async function supervisor(box) {
    const d = await RM.get('/api/dashboard/supervisor');
    const c = d.counts;
    box.innerHTML = `<h1 class="h3 mb-3">Supervisor dashboard</h1>
      <div class="row g-3 mb-4">${tile('Assigned students', c.assigned_students)}${tile('Active projects', c.active_projects)}${tile('Upcoming meetings', c.upcoming_meetings)}${tile('Pending submissions', c.pending_submissions, c.pending_submissions ? 'text-warning' : '')}${tile('Overdue tasks', c.overdue_tasks, c.overdue_tasks ? 'text-danger' : '')}${tile('Unresolved comments', c.unresolved_comments)}</div>
      <div class="row g-3"><div class="col-lg-8"><div class="card"><div class="card-header">My students</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Student</th><th>Stage</th><th>Attention indicators</th><th>Last submission</th></tr></thead><tbody>
        ${d.students.map((s) => `<tr><td><a href="/projects/${RM.esc(s.project_id)}">${RM.esc(s.student)}</a><div class="small text-muted">${RM.esc(s.title)}</div></td><td>${RM.label(s.stage)}</td><td>${attn(s.attention)}</td><td>${RM.fmtDate(s.last_submission_at)}</td></tr>`).join('') || '<tr><td colspan="4" class="text-muted">No students assigned.</td></tr>'}</tbody></table></div>
        <div class="card-footer small text-muted">${RM.esc(d.note)}</div></div></div>
      <div class="col-lg-4"><div class="card mb-3"><div class="card-header">Research stages</div><div class="card-body"><canvas id="stage-chart" height="220" role="img" aria-label="Projects by research stage"></canvas></div></div>
        <div class="card"><div class="card-header">Upcoming meetings</div><ul class="list-group list-group-flush small">${d.upcoming_meetings.map((m) => `<li class="list-group-item"><a href="/projects/${RM.esc(m.project.id)}/supervision">${RM.esc(m.title)}</a><div class="text-muted">${RM.esc(m.project.title)} · ${RM.fmtDateTime(m.scheduled_at)}</div></li>`).join('') || '<li class="list-group-item text-muted">None</li>'}</ul></div></div></div>
      <div class="row g-3 mt-1"><div class="col-lg-4"><div class="card"><div class="card-header">Pending submissions</div><ul class="list-group list-group-flush small">${d.pending_submissions.map((t) => `<li class="list-group-item">${RM.esc(t.title)}<div class="text-muted">${RM.esc(t.project.title)}</div></li>`).join('') || '<li class="list-group-item text-muted">None</li>'}</ul></div></div>
      <div class="col-lg-4"><div class="card"><div class="card-header">Overdue tasks</div><ul class="list-group list-group-flush small">${d.overdue_tasks.map((t) => `<li class="list-group-item"><span class="badge text-bg-danger">due ${RM.fmtDate(t.due_date)}</span> ${RM.esc(t.title)}<div class="text-muted">${RM.esc(t.project.title)}</div></li>`).join('') || '<li class="list-group-item text-muted">None</li>'}</ul></div></div>
      <div class="col-lg-4"><div class="card"><div class="card-header">Recent student activity</div><ul class="list-group list-group-flush small">${d.recent_activity.map((a) => `<li class="list-group-item">${RM.esc(a.text)}<div class="text-muted">${RM.fmtDateTime(a.at)}</div></li>`).join('') || '<li class="list-group-item text-muted">None</li>'}</ul></div></div></div>`;
    RM.chart('stage-chart', { type: 'bar', data: { labels: d.stage_distribution.map((s) => stageLabel(s.stage)), datasets: [{ label: 'Projects', data: d.stage_distribution.map((s) => s.count), backgroundColor: PAL[0] }] }, options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } } });
  }

  async function admin(box) {
    const d = await RM.get('/api/dashboard/administrator');
    const o = d.outstanding_admin_tasks; const a = d.supervision_activity;
    box.innerHTML = `<h1 class="h3 mb-1">Administration dashboard <span class="badge text-bg-secondary fs-6">${RM.label(d.scope)} scope</span></h1><p class="text-muted small">${RM.esc(d.privacy_note)}</p>
      <div class="row g-3 mb-4">${tile('Students', d.totals.students)}${tile('Supervisors', d.totals.supervisors)}${tile('Projects', d.totals.projects)}${tile('Meetings (30 d)', a.meetings_last_30_days)}${tile('Tasks completed (30 d)', a.tasks_completed_last_30_days)}${tile('Documents (30 d)', a.documents_submitted_last_30_days)}</div>
      <div class="row g-3"><div class="col-lg-6"><div class="card h-100"><div class="card-header">Projects by stage</div><div class="card-body"><canvas id="adm-stage" height="240" role="img" aria-label="Projects by stage"></canvas></div></div></div>
      <div class="col-lg-3"><div class="card h-100"><div class="card-header">Outstanding administrative tasks</div><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between">Projects without supervisor <strong>${o.projects_without_supervisor}</strong></li><li class="list-group-item d-flex justify-content-between">Pending Constitution proposals <strong>${o.pending_constitution_proposals}</strong></li><li class="list-group-item d-flex justify-content-between">Projects with overdue milestones <strong>${o.projects_overdue_milestones}</strong></li><li class="list-group-item d-flex justify-content-between">Locked accounts <strong>${o.locked_accounts}</strong></li></ul></div></div>
      <div class="col-lg-3"><div class="card h-100"><div class="card-header">AI review jobs (30 d)</div><div class="card-body"><canvas id="adm-jobs" height="200" role="img" aria-label="AI review jobs by status"></canvas></div></div></div></div>
      ${d.ai_usage_30_days ? `<div class="card mt-3"><div class="card-header">AI usage (30 days): ${d.ai_usage_30_days.calls} calls · ${d.ai_usage_30_days.input_tokens + d.ai_usage_30_days.output_tokens} tokens · ${d.ai_usage_30_days.errors} errors · ${d.ai_usage_30_days.avg_latency_ms} ms avg</div><div class="card-body"><canvas id="adm-ai" height="90" role="img" aria-label="AI calls per day"></canvas></div></div>` : ''}
      ${d.system_health ? `<div class="card mt-3"><div class="card-header">System health <span class="badge text-bg-${d.system_health.overall_ok ? 'success' : 'danger'}">${d.system_health.overall_ok ? 'All checks passing' : 'Attention needed'}</span></div><ul class="list-group list-group-flush small">${Object.entries(d.system_health).filter(([k]) => k !== 'overall_ok').map(([k, v]) => `<li class="list-group-item"><i class="bi ${v.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'}"></i> <strong>${RM.esc(k)}</strong> <span class="text-muted">${RM.esc(JSON.stringify(Object.fromEntries(Object.entries(v).filter(([x]) => x !== 'ok'))))}</span></li>`).join('')}</ul></div>` : ''}`;
    RM.chart('adm-stage', { type: 'bar', data: { labels: d.projects_by_stage.map((s) => stageLabel(s.stage)), datasets: [{ label: 'Projects', data: d.projects_by_stage.map((s) => s.count), backgroundColor: PAL[0] }] }, options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
    const bs = d.review_jobs.by_status;
    RM.chart('adm-jobs', { type: 'doughnut', data: { labels: bs.map((x) => x.status), datasets: [{ data: bs.map((x) => x.count), backgroundColor: PAL }] }, options: { plugins: { legend: { position: 'bottom' } } } });
    if (d.ai_usage_30_days) RM.chart('adm-ai', { type: 'line', data: { labels: d.ai_usage_30_days.daily.map((x) => x.date), datasets: [{ label: 'AI calls', data: d.ai_usage_30_days.daily.map((x) => x.calls), borderColor: PAL[3], backgroundColor: PAL[3], tension: .2 }] }, options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
  }

  async function examiner(box) {
    const rows = await RM.get('/api/projects');
    box.innerHTML = `<h1 class="h3 mb-3">Assigned projects (External Examiner)</h1><p class="text-muted small">You can open only projects explicitly assigned to you, and only documents shared with you.</p>` + (rows.length ? `<div class="list-group">${rows.map((r) => `<a class="list-group-item list-group-item-action" href="/projects/${RM.esc(r.id)}"><strong>${RM.esc(r.title)}</strong><div class="small text-muted">${RM.esc(r.student)} · ${RM.label(r.stage)}</div></a>`).join('')}</div>` : '<div class="alert alert-info">No projects have been assigned to you.</div>');
  }
};
