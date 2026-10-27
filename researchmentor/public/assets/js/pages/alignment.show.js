RM.pages['alignment.show'] = async function (root, id) {
  const proj = await RM.get('/api/projects/' + id);
  const m = await RM.get(`/api/projects/${id}/alignment`);
  const cols = [['objective', 'Objective'], ['research_question', 'Question'], ['hypothesis', 'Hypothesis'], ['variable', 'Variable'], ['indicator', 'Indicator'], ['instrument', 'Instrument'], ['data_source', 'Data'], ['analysis', 'Analysis'], ['mismatch_note', 'Mismatch / note']];
  root.innerHTML = `<a href="/projects/${id}" class="small">&larr; ${RM.esc(proj.title)}</a><h1 class="h3 mb-2">Research alignment matrix</h1><div id="alerts"></div>
  <p class="small text-muted">Objective → Question → Hypothesis → Variable → Indicator → Instrument → Data → Analysis. The AI drafts and annotates; the student and supervisor edit; only a supervisor can mark it reviewed.</p>
  ${m ? `<div class="mb-3"><span class="badge ${m.status === 'ai_draft' ? 'badge-ai' : 'badge-human'}">${RM.esc(m.label)}</span> ${m.review_id ? `<a class="small ms-2" href="/reviews/${RM.esc(m.review_id)}">View the AI review</a>` : ''}</div>
  <div class="card"><div class="table-responsive"><table class="table table-bordered align-middle mb-0" style="min-width:1100px"><thead class="table-light"><tr>${cols.map((c) => `<th>${c[1]}</th>`).join('')}<th></th></tr></thead><tbody id="rows"></tbody></table></div></div>
  <div class="mt-3 d-flex gap-2">${proj.permissions.edit ? '<button class="btn btn-outline-primary btn-sm" id="add">Add row</button>' : ''}${proj.permissions.approve_constitution && m.status === 'ai_draft' ? '<button class="btn btn-success btn-sm" id="approve">Mark as reviewed by supervisor</button>' : ''}<a class="btn btn-outline-secondary btn-sm" href="/api/reports/review/${RM.esc(m.review_id || '')}?format=pdf">PDF report</a></div>`
   : `<div class="alert alert-info">No alignment matrix yet.</div>${proj.permissions.run_ai ? '<button class="btn btn-primary" id="gen">Generate alignment matrix (AI draft)</button>' : ''}`}`;
  const gen = root.querySelector('#gen');
  if (gen) gen.addEventListener('click', async () => { gen.disabled = true; try { let job = await RM.post('/api/ai/alignment-review', { project_id: id }); for (let i = 0; i < 60 && ['queued', 'running'].includes(job.status); i++) { await new Promise((r) => setTimeout(r, 2000)); job = await RM.get('/api/review-jobs/' + job.id); } if (job.status === 'completed') location.reload(); else RM.alert(job.error || 'Could not generate the matrix.'); } catch (e) { RM.showError(e); } gen.disabled = false; });
  if (!m) return;
  const tbody = root.querySelector('#rows'); const editable = proj.permissions.edit;
  const draw = (rows) => { tbody.innerHTML = rows.map((r) => `<tr data-r="${r.id}">${cols.map(([k]) => `<td>${editable ? `<textarea class="form-control form-control-sm border-0" rows="2" data-k="${k}" aria-label="${k}">${RM.esc(r[k] || '')}</textarea>` : RM.nl2br(r[k] || '')}</td>`).join('')}<td class="text-nowrap small">${RM.originBadge(r.origin)}${editable ? `<br><button class="btn btn-sm btn-link p-0" data-save>Save</button> <button class="btn btn-sm btn-link text-danger p-0" data-del>Delete</button>` : ''}</td></tr>`).join(''); };
  draw(m.rows);
  const base = `/api/projects/${id}/alignment/${m.id}`;
  tbody.addEventListener('click', async (ev) => {
    const tr = ev.target.closest('tr'); if (!tr) return;
    try {
      if (ev.target.closest('[data-save]')) { const body = {}; tr.querySelectorAll('[data-k]').forEach((t) => { body[t.dataset.k] = t.value; }); draw(await RM.put(`${base}/rows/${tr.dataset.r}`, body)); RM.alert('Saved. The row is now marked as edited by you.', 'success'); }
      else if (ev.target.closest('[data-del]')) { if (confirm('Delete this row?')) draw(await RM.del(`${base}/rows/${tr.dataset.r}`)); }
    } catch (e) { RM.showError(e); }
  });
  const add = root.querySelector('#add'); if (add) add.addEventListener('click', async () => { try { draw(await RM.post(`${base}/rows`, {})); } catch (e) { RM.showError(e); } });
  const ap = root.querySelector('#approve'); if (ap) ap.addEventListener('click', async () => { if (!confirm('Confirm that you have reviewed this matrix?')) return; try { await RM.post(`${base}/approve`, {}); location.reload(); } catch (e) { RM.showError(e); } });
};
