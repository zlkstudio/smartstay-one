/* SmartStay ONE — Rapoarte. Numbers are rendered server-side (One\Reports\Analytics); this file
   only adds behaviour: period switch + skeleton, bar details, channel metric toggle, apartment
   sheet, recalculate, delete / add payment lines. Access is enforced server-side. */
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

    initPeriod();
    initBars();
    initChannels();
    initSheet();

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

  // ── Prezentare ──────────────────────────────────────────────────────────

  function initPeriod() {
    root.querySelectorAll('[data-period-link]').forEach((a) => {
      a.addEventListener('click', () => root.classList.add('is-loading'));
    });
    const toggle = root.querySelector('[data-custom-toggle]');
    const form = root.querySelector('[data-custom-form]');
    if (!toggle || !form) return;
    toggle.addEventListener('click', () => {
      form.hidden = !form.hidden;
      toggle.setAttribute('aria-expanded', String(!form.hidden));
      if (!form.hidden) form.querySelector('input').focus();
    });
    form.addEventListener('submit', () => root.classList.add('is-loading'));
  }

  function initBars() {
    const bars = root.querySelector('[data-bars]');
    const tip = root.querySelector('[data-chart-tip]');
    if (!bars || !tip) return;
    bars.addEventListener('click', (event) => {
      const bar = event.target.closest('.bar');
      if (!bar) return;
      bars.querySelectorAll('.bar.is-selected').forEach((b) => b.classList.remove('is-selected'));
      bar.classList.add('is-selected');
      tip.textContent = bar.dataset.tip;
    });
  }

  const LEI = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
  const UNITS = { reservations: 'rezervări', nights: 'nopți', revenue: 'Lei' };

  function initChannels() {
    const box = root.querySelector('[data-channels]');
    if (!box) return;
    const legend = box.querySelector('[data-legend]');
    const donut = box.querySelector('[data-donut]');
    if (!legend || !donut) return;
    const render = (metric) => {
      const items = Array.from(legend.children);
      const value = (li) => Number(li.dataset[metric]) || 0;
      const total = items.reduce((sum, li) => sum + value(li), 0);
      items.sort((a, b) => value(b) - value(a)).forEach((li) => legend.appendChild(li));
      let acc = 0;
      const stops = [];
      items.forEach((li) => {
        const share = total ? (value(li) * 100) / total : 0;
        li.querySelector('[data-share]').textContent = Math.round(share) + '%';
        if (share > 0) stops.push(`${li.dataset.color} ${acc.toFixed(2)}% ${(acc + share).toFixed(2)}%`);
        acc += share;
      });
      donut.style.background = stops.length ? `conic-gradient(${stops.join(', ')})` : 'var(--surface-2)';
      box.querySelector('[data-donut-value]').textContent = LEI.format(total);
      box.querySelector('[data-donut-unit]').textContent = UNITS[metric];
    };
    box.querySelectorAll('[data-channel-metric]').forEach((input) => {
      input.addEventListener('change', () => render(input.value));
    });
    render('reservations');
  }

  function initSheet() {
    const sheet = root.querySelector('[data-apt-sheet]');
    const body = root.querySelector('[data-sheet-body]');
    const table = root.querySelector('[data-apt-table]');
    if (!sheet || !body || !table || typeof sheet.showModal !== 'function') return;
    table.addEventListener('click', (event) => {
      const row = event.target.closest('[data-apt]');
      if (!row) return;
      const tpl = root.querySelector(`template[data-apt-detail="${CSS.escape(row.dataset.apt)}"]`);
      if (!tpl) return;
      body.replaceChildren(tpl.content.cloneNode(true));
      sheet.showModal();
    });
    sheet.addEventListener('click', (event) => {
      if (event.target === sheet || event.target.closest('[data-sheet-close]')) sheet.close();
    });
  }
})();
