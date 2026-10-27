RM.pages['notifications.index'] = async function (root) {
  const d = await RM.get('/api/notifications');
  root.innerHTML = `<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h3 mb-0">Notifications</h1><button class="btn btn-outline-secondary btn-sm" id="all">Mark all read</button></div><div id="alerts"></div>
  <div class="list-group">${d.items.map((n) => `<a class="list-group-item list-group-item-action ${n.read ? '' : 'fw-semibold'}" href="${n.link ? RM.esc(n.link) : '#'}" data-n="${RM.esc(n.id)}"><div class="d-flex justify-content-between"><span>${n.read ? '' : '<i class="bi bi-circle-fill text-primary small"></i> '}${RM.esc(n.title)}</span><small class="text-muted">${RM.fmtDateTime(n.created_at)}</small></div>${n.body ? `<div class="small text-muted fw-normal">${RM.esc(n.body)}</div>` : ''}</a>`).join('') || '<div class="text-muted">No notifications.</div>'}</div>`;
  root.querySelectorAll('[data-n]').forEach((a) => a.addEventListener('click', () => { RM.post(`/api/notifications/${a.dataset.n}/read`, {}).catch(() => {}); }));
  root.querySelector('#all').addEventListener('click', async () => { await RM.post('/api/notifications/read-all', {}); location.reload(); });
};
