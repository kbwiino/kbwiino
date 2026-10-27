(function () {
  'use strict';
  const form = document.getElementById('login-form');
  RM.get('/api/auth/csrf').then((d) => RM.setCsrf(d.csrf_token)).catch(() => {});
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = form.querySelector('button[type=submit]'); btn.disabled = true;
    try {
      const d = await RM.post('/api/auth/login', RM.formData(form));
      location.href = d.redirect || '/dashboard';
    } catch (e) {
      RM.showError(e); btn.disabled = false;
      try { RM.setCsrf((await RM.get('/api/auth/csrf')).csrf_token); } catch (x) { /* ignore */ }
    }
  });
})();
