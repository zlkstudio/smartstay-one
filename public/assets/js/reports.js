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
    const body = root.querySelector('[data-report-body]');
    if (body && body.dataset.src) {
      loadBody(body);
    } else {
      initBody();
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

  // ── Prezentare ──────────────────────────────────────────────────────────

  function initBody() {
    initBars();
    initChannels();
    initSheet();
    countUp();
  }

  // Cifrele mari numără de la 0 la valoare (600 ms), în formatul de pe server: „1.234,5 Lei”, „72,4%”.
  // Doar indicatorii de sus (max. 8 numere), un singur requestAnimationFrame pentru toate.
  function countUp() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const jobs = [];
    root.querySelectorAll('.kpi-grid .kpi-value, .stat-grid .stat-value').forEach((el) => {
      const node = Array.from(el.childNodes).find((n) => n.nodeType === 3 && n.nodeValue.trim());
      const m = node && node.nodeValue.match(/^(\D*?)(\d[\d.]*(?:,\d+)?)([\s\S]*)$/);
      if (!m) return;
      const dec = m[2].includes(',') ? m[2].split(',')[1].length : 0;
      const value = parseFloat(m[2].replace(/\./g, '').replace(',', '.'));
      if (!(value > 0)) return;
      jobs.push({ node, final: node.nodeValue, pre: m[1], post: m[3], dec, value, group: m[2].includes('.') || value < 1000 });
    });
    if (!jobs.length) return;
    const fmt = (v, dec, group) => {
      const parts = v.toFixed(dec).split('.');
      if (group) parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
      return parts.join(',');
    };
    const start = performance.now();
    const tick = (now) => {
      const t = Math.min(1, (now - start) / 600);
      const e = 1 - Math.pow(1 - t, 3);
      jobs.forEach((j) => { j.node.nodeValue = t >= 1 ? j.final : j.pre + fmt(j.value * e, j.dec, j.group) + j.post; });
      if (t < 1) requestAnimationFrame(tick);
    };
    jobs.forEach((j) => { j.node.nodeValue = j.pre + fmt(0, j.dec, j.group) + j.post; });
    requestAnimationFrame(tick);
  }

  // The tab opens on a skeleton; the computed report (Previo + Analytics) arrives here.
  function loadBody(body) {
    const url = body.dataset.src;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 60000);
    fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' }, signal: controller.signal })
      .then((r) => {
        clearTimeout(timer);
        if (new URL(r.url).pathname !== '/reports/body') {   // session expired → login page
          location.href = '/login?expired=1&next=' + encodeURIComponent(location.pathname + location.search);
          throw new Error('redirect');
        }
        if (!r.ok) throw new Error('Eroare ' + r.status);
        return r.text();
      })
      .then((html) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const next = doc.querySelector('[data-report-body]');
        if (!next) throw new Error('Răspuns neașteptat de la server.');
        // Pieces outside the body that depend on the data: "Actualizat la…", the compare label, the error.
        const heroNext = doc.querySelector('.hero p');
        const hero = root.querySelector('.hero .grow');
        const heroNow = hero && hero.querySelector('p');
        if (heroNow) heroNow.remove();
        if (heroNext && hero) hero.appendChild(document.importNode(heroNext, true));
        const labelNext = doc.querySelector('.period-label');
        const label = root.querySelector('.period-label');
        if (labelNext && label) label.innerHTML = labelNext.innerHTML;
        const alertNext = doc.querySelector('[data-reports] > .alert-error');
        if (alertNext) body.before(document.importNode(alertNext, true));
        body.replaceWith(document.importNode(next, true));
        initBody();
        document.dispatchEvent(new Event('one:ready'));
      })
      .catch((e) => {
        clearTimeout(timer);
        if (e.message === 'redirect') return;
        const msg = e.name === 'AbortError' ? 'Previo răspunde greu.' : e.message;
        body.removeAttribute('aria-busy');
        const heroNow = root.querySelector('.hero .grow p');
        if (heroNow) heroNow.textContent = 'Datele din Previo nu s-au putut încărca.';
        body.innerHTML = '<div class="alert alert-error" role="alert"><span>Raportul nu s-a putut încărca. ' +
          msg.replace(/[<>&]/g, '') + '</span></div><button type="button" class="btn btn-secondary" data-body-retry>Încearcă din nou</button>';
        body.querySelector('[data-body-retry]').addEventListener('click', () => location.reload());
        document.dispatchEvent(new Event('one:ready'));
      });
  }

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
    root.querySelectorAll('[data-bars]').forEach((bars) => {
      const tip = bars.parentElement.querySelector('[data-chart-tip]');
      if (!tip) return;
      bars.addEventListener('click', (event) => {
        const bar = event.target.closest('.bar');
        if (!bar) return;
        bars.querySelectorAll('.bar.is-selected').forEach((b) => b.classList.remove('is-selected'));
        bar.classList.add('is-selected');
        tip.textContent = bar.dataset.tip;
      });
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
      donut.style.setProperty('--ring', stops.length ? `conic-gradient(${stops.join(', ')})` : 'var(--surface-2)');
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
