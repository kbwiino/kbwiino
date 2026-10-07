RM.pages['admin.system'] = async function (root) {
  const h = await RM.get('/api/admin/health');
  const ai = await RM.get('/api/ai/status');
  root.innerHTML = `<h1 class="h3 mb-3">System settings &amp; health</h1>
  <div class="alert alert-${h.overall_ok ? 'success' : 'danger'}">${h.overall_ok ? 'All system checks are passing.' : 'One or more checks need attention.'}</div>
  <div class="card mb-4"><ul class="list-group list-group-flush">${Object.entries(h).filter(([k]) => k !== 'overall_ok').map(([k, v]) => `<li class="list-group-item"><i class="bi ${v.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'}"></i> <strong>${RM.esc(k)}</strong> <span class="small text-muted">${RM.esc(JSON.stringify(Object.fromEntries(Object.entries(v).filter(([x]) => x !== 'ok'))))}</span></li>`).join('')}</ul></div>
  <div class="card"><div class="card-header">AI configuration</div><div class="card-body small"><p>Provider: <strong>${RM.esc(ai.provider)}</strong>${ai.mock ? ' <span class="badge badge-ai">development mock</span>' : ''} · Available: <strong>${ai.available}</strong></p><p class="mb-0">Technical settings (API key, model, token limits, timeouts, retention defaults) are configured in the server's <code>.env</code> file and are never exposed to the browser. See <code>docs/DEPLOYMENT.md</code>.</p></div></div>`;
};
