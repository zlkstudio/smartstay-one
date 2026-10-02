/* SmartStay ONE — Home indicators (today) from /api/reports/today. Server-side cache ≤ 15 min. */
(function () {
  'use strict';

  const card = document.querySelector('[data-home-today]');
  if (!card) return;

  function set(el, value) { el.textContent = value; }

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.ONE) return;
    const meta = card.querySelector('[data-home-meta]');
    const donut = card.querySelector('[data-home-donut]');
    const val = (k) => card.querySelector(`[data-home-v="${k}"]`);

    window.ONE.api('/api/reports/today', { timeout: 40000 })
      .then((d) => {
        const t = d.today;
        const free = t.free.length;
        const total = d.total || free + t.occupied;
        const pct = total ? (free / total) * 100 : 0;
        donut.style.background = `conic-gradient(var(--free) 0 ${pct}%, var(--busy) ${pct}% 100%)`;
        donut.setAttribute('aria-label', `${free} din ${total} apartamente libere la noapte`);
        set(card.querySelector('[data-home-free]'), String(free));
        set(card.querySelector('[data-home-free-of]'), `din ${total} libere`);
        set(val('free'), free);
        set(val('occupied'), t.occupied);
        set(val('checkIns'), t.checkIns);
        set(val('checkOuts'), t.checkOuts);

        const list = card.querySelector('[data-home-free-list]');
        list.replaceChildren(...t.free.map((apt) => {
          const chip = document.createElement('span');
          chip.className = 'free-chip';
          chip.textContent = apt;
          return chip;
        }));
        list.hidden = free === 0;
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
        card.querySelectorAll('[data-home-v], [data-home-free]').forEach((s) => set(s, '—'));
        set(meta, 'Previo nu a răspuns: ' + e.message);
      });
  });
})();
