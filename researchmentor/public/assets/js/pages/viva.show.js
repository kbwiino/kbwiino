RM.pages['viva.show'] = async function (root, id) {
  const proj = await RM.get('/api/projects/' + id);
  const modes = await RM.get('/api/viva/modes');
  const hist = await RM.get(`/api/projects/${id}/viva`);
  const canStart = RM.hasRole('student');
  root.innerHTML = `<a href="/projects/${id}" class="small">&larr; ${RM.esc(proj.title)}</a><h1 class="h3 mb-2">Viva practice simulator</h1>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <strong>This is an AI practice exercise, not an official examination.</strong> Feedback is formative; there are no marks, grades or decisions.</div><div id="alerts"></div>
  ${canStart ? `<div class="card mb-4"><div class="card-header">Start a practice session</div><form id="sf" class="card-body row g-2 align-items-end"><div class="col-md-6"><label class="form-label" for="mode">Mode</label><select class="form-select" id="mode">${modes.map((m) => `<option value="${m.mode}">${RM.esc(m.label)} - ${RM.esc(m.description)}</option>`).join('')}</select></div><div class="col-md-3"><button class="btn btn-primary w-100">Start</button></div></form></div>` : '<div class="alert alert-info">Practice sessions are private to the student.</div>'}
  <div id="session"></div>
  <div class="card mt-4"><div class="card-header">Practice history</div><ul class="list-group list-group-flush" id="hist">${hist.map((s) => `<li class="list-group-item d-flex justify-content-between"><span><button class="btn btn-link p-0" data-s="${RM.esc(s.id)}">${RM.esc(s.mode_label)}</button> <span class="small text-muted">${RM.fmtDateTime(s.started_at)}</span> ${s.mock ? '<span class="badge badge-ai">mock</span>' : ''}</span><span class="badge text-bg-${s.status === 'active' ? 'primary' : 'secondary'}">${RM.label(s.status)}</span></li>`).join('') || '<li class="list-group-item text-muted">No practice sessions yet.</li>'}</ul></div>`;
  const box = root.querySelector('#session');

  function show(s) {
    const last = s.exchanges[s.exchanges.length - 1];
    box.innerHTML = `<div class="card"><div class="card-header d-flex justify-content-between"><span>${RM.esc(s.mode_label)} ${s.mock ? '<span class="badge badge-ai">development mock AI</span>' : ''}</span><span>${s.status === 'completed' ? `<a class="btn btn-sm btn-outline-secondary" href="/api/reports/viva/${RM.esc(s.id)}?format=pdf">PDF report</a>` : '<button class="btn btn-sm btn-outline-danger" id="end">End session</button>'}</span></div><div class="card-body">
      ${s.exchanges.map((e) => `<div class="mb-3"><div class="fw-semibold">Q${e.seq}${e.is_follow_up ? ' <span class="badge text-bg-light border">follow-up</span>' : ''}: ${RM.esc(e.question)}</div>${e.answer ? `<div class="ms-3 mt-1"><span class="text-muted small">Your answer</span><div>${RM.nl2br(e.answer)}</div></div>` : ''}${e.feedback ? `<div class="ai-box ms-3 mt-2 small"><strong><i class="bi bi-robot"></i> Formative AI feedback</strong><div>${RM.nl2br(e.feedback.text)}</div>${e.feedback.strengths.length ? `<div class="mt-1"><strong>Strengths:</strong> ${e.feedback.strengths.map(RM.esc).join('; ')}</div>` : ''}${e.feedback.improvements.length ? `<div><strong>To improve:</strong> ${e.feedback.improvements.map(RM.esc).join('; ')}</div>` : ''}</div>` : ''}</div>`).join('')}
      ${s.status === 'active' && last && !last.answer ? `<form id="af"><label class="form-label fw-semibold" for="ans">Your answer</label><textarea class="form-control mb-2" id="ans" rows="5" required maxlength="8000"></textarea><button class="btn btn-primary">Submit answer</button></form>` : ''}
      ${s.status === 'active' && last && last.answer ? '<div class="alert alert-info mb-0">No further questions. You can end the session.</div>' : ''}
      ${s.status === 'completed' ? `<div class="alert alert-secondary mb-0">${RM.esc(s.summary || '')}</div>` : ''}</div></div>`;
    const af = box.querySelector('#af'); if (af) af.addEventListener('submit', async (ev) => { ev.preventDefault(); const btn = af.querySelector('button'); btn.disabled = true; try { await RM.post(`/api/viva/sessions/${s.id}/answer`, { question_id: last.id, answer: af.querySelector('#ans').value }); show(await RM.get('/api/viva/sessions/' + s.id)); } catch (e) { RM.showError(e); btn.disabled = false; } });
    const en = box.querySelector('#end'); if (en) en.addEventListener('click', async () => { try { show(await RM.post(`/api/viva/sessions/${s.id}/end`, {})); } catch (e) { RM.showError(e); } });
  }
  const sf = root.querySelector('#sf'); if (sf) sf.addEventListener('submit', async (ev) => { ev.preventDefault(); const btn = sf.querySelector('button'); btn.disabled = true; try { show(await RM.post('/api/ai/viva-session', { project_id: id, mode: sf.querySelector('#mode').value })); } catch (e) { RM.showError(e); } btn.disabled = false; });
  root.querySelectorAll('[data-s]').forEach((b) => b.addEventListener('click', async () => { try { show(await RM.get('/api/viva/sessions/' + b.dataset.s)); } catch (e) { RM.showError(e); } }));
};
