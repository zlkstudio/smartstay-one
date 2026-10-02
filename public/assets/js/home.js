/* SmartStay ONE — Home indicators (today) from /api/reports/today. Server-side cache ≤ 15 min. */
(function () {
  'use strict';

  const card = document.querySelector('[data-home-today]');
  if (!card) return;

  function set(el, value) { el.textContent = value; }

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.ONE) return;
    const stats = card.querySelectorAll('[data-home-stats] .stat-value');
    const meta = card.querySelector('[data-home-meta]');

    window.ONE.api('/api/reports/today', { timeout: 40000 })
      .then((d) => {
        const t = d.today;
        stats[0].innerHTML = '';
        stats[0].append(String(t.free.length));
        const small = document.createElement('small');
        small.textContent = '/' + d.total;
        stats[0].append(small);
        set(stats[1], t.occupied);
        set(stats[2], t.checkIns);
        set(stats[3], t.checkOuts);
        stats.forEach((s) => s.classList.add('tabular'));
        set(meta, `Ocupare medie 30 de nopți: ${d.avgPast}% · actualizat la ${d.computedAt}`);

        const stock = document.querySelector('[data-home-stock]');
        if (stock && Array.isArray(d.critical)) {
          const n = d.critical.length;
          stock.querySelector('[data-home-stock-dot]').className = 'dot ' + (n ? 'dot-warn' : 'dot-ok');
          set(stock.querySelector('[data-home-stock-title]'), n ? `${n} apartamente cu lenjerii pe roșu` : 'Stocul de lenjerii e în regulă');
          set(stock.querySelector('[data-home-stock-sub]'), n ? d.critical.join(', ') : 'Niciun apartament pe roșu');
          stock.hidden = false;
        }
      })
      .catch((e) => {
        stats.forEach((s) => set(s, '—'));
        set(meta, 'Previo nu a răspuns: ' + e.message);
      });
  });
})();
