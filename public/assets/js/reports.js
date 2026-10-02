/* SmartStay ONE — Rapoarte: recalculate, delete / add payment lines. Everything else is
   rendered server-side. Access is enforced server-side; buttons only exist for "edit". */
(function () {
  'use strict';

  const root = document.querySelector('[data-reports]');
  if (!root) return;

  function toast(m) { window.ONE.toast(m); }

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.ONE) return;

    const refresh = root.querySelector('[data-report-refresh]');
    if (refresh) {
      refresh.addEventListener('click', () => {
        refresh.disabled = true;
        refresh.classList.add('is-spinning');
        window.ONE.api('/api/reports/refresh', { method: 'POST', timeout: 60000 })
          .then((res) => { toast(res.message); setTimeout(() => location.reload(), 500); })
          .catch((e) => { toast(e.message); refresh.disabled = false; refresh.classList.remove('is-spinning'); });
      });
    }

    root.addEventListener('click', (event) => {
      const del = event.target.closest('[data-delete]');
      if (!del) return;
      event.preventDefault();
      if (!window.confirm('Ștergi din raport: ' + del.dataset.label + '?')) return;
      del.disabled = true;
      window.ONE.api('/api/reports/cleaning/delete', { method: 'POST', body: { id: Number(del.dataset.delete) } })
        .then((res) => { toast(res.message); setTimeout(() => location.reload(), 500); })
        .catch((e) => { toast(e.message); del.disabled = false; });
    });

    const form = root.querySelector('[data-add-cleaning]');
    if (form) {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const data = Object.fromEntries(new FormData(form).entries());
        button.disabled = true;
        button.classList.add('is-loading');
        window.ONE.api('/api/reports/cleaning', { method: 'POST', body: data })
          .then((res) => { toast(res.message); setTimeout(() => location.reload(), 600); })
          .catch((e) => toast(e.message))
          .finally(() => { button.disabled = false; button.classList.remove('is-loading'); });
      });
    }
  });
})();
