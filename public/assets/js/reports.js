/* SmartStay ONE — Rapoarte. Numbers are rendered server-side (One\Reports\Analytics); this file
   only adds behaviour: period switch (in place, no full reload), bar details, channel metric toggle,
   apartment sheet, recalculate, delete / add payment lines. Access is enforced server-side.
   Motion: KPIs count up with the ro-RO formatting, charts enter once when they scroll into view,
   the period switch swaps the report body in place (motion.js tokens throughout). */
(function () {
  'use strict';

  const root = document.querySelector('[data-reports]');
  if (!root) return;

  const M = window.MOTION;
  function toast(m) { window.ONE.toast(m); }

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.ONE) return;

    const refresh = root.querySelector('[data-report-refresh]');
    if (refresh) {
      refresh.addEventListener('click', () => {
        refresh.disabled = true;
        const ic = refresh.querySelector('.icon');
        if (M && ic) M.animate(ic, { rotate: (Math.floor(M.valueOf(ic, 'rotate') / 360) + 1) * 360 }, { spring: 'settle' });
        window.ONE.api('/api/reports/refresh', { method: 'POST', timeout: 60000 })
          .then((res) => { toast(res.message); setTimeout(() => location.reload(), 500); })
          .catch((e) => { toast(e.message); refresh.disabled = false; });
      });
    }

    initPeriod();
    initBars();
    initChannels(root);
    initSheet();
    root.querySelectorAll('.chips').forEach((chips) => { if (M) chips.__pill = M.chipPill(chips); });
    enter(root.querySelector('[data-report-body]') || root, null);

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

  // ════════════════════════════════════════════════════════════════════════
  // Motion: numbers + chart entrances
  // ════════════════════════════════════════════════════════════════════════

  const NUMBER_SEL = '.kpi-value, .stat-value, .kpi-secondary strong, .ops-line strong, .hbar-value, .donut-hole strong, .move-stats strong';

  // KPI order in the grid: Ocupare, ADR, RevPAR, Venit. Occupancy + revenue start together,
  // the secondary ones follow 60 ms apart.
  const KPI_DELAY = [0, 1, 2, 0];

  function countNumbers(scope, prev) {
    if (!M) return;
    const T = M.tokens.stagger.kpi;
    scope.querySelectorAll('.kpi-grid .kpi-value').forEach((el, i) => {
      M.countUp(el, { delay: (KPI_DELAY[i] || 0) * T, from: prev ? prev.kpi[i] : 0 });
    });
    scope.querySelectorAll('.stat-grid .stat-value').forEach((el, i) => {
      M.countUp(el, { delay: i * T, from: prev ? prev.stat[i] : 0 });
    });
    scope.querySelectorAll('.kpi-secondary strong, .ops-line strong').forEach((el, i) => {
      M.countUp(el, { delay: 180 + i * 30, from: 0 });
    });
  }

  function snapshot(scope) {
    const read = (el) => { const p = M.parseNumber(el.textContent); return p ? p.value : 0; };
    return {
      kpi: Array.from(scope.querySelectorAll('.kpi-grid .kpi-value')).map(read),
      stat: Array.from(scope.querySelectorAll('.stat-grid .stat-value')).map(read),
    };
  }

  // Charts: prepare the hidden start state now, play when ≥25 % is on screen.
  function enter(scope, prev) {
    countNumbers(scope, prev);
    if (!M || M.reduced()) return;
    const charts = [];
    scope.querySelectorAll('[data-bars], .wk-bars').forEach((el) => charts.push([el, barsEntrance(el)]));
    scope.querySelectorAll('svg.trend').forEach((el) => charts.push([el, trendEntrance(el)]));
    scope.querySelectorAll('.hbars, [data-apt-table]').forEach((el) => charts.push([el, rankEntrance(el)]));
    scope.querySelectorAll('.mix-bar').forEach((el) => charts.push([el, mixEntrance(el)]));
    scope.querySelectorAll('.stat-value').forEach((el) => {
      if (el.closest('.stat-grid')) return;
      M.holdCount(el); // shows 0 until it scrolls in, then counts
      charts.push([el, () => M.countUp(el)]);
    });

    if (!('IntersectionObserver' in window)) { charts.forEach(([, play]) => play()); return; }
    const plays = new Map(charts);
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        const play = plays.get(e.target);
        if (play) play();
      });
    }, { threshold: 0.25 });
    charts.forEach(([el]) => io.observe(el));
  }

  // Bars grow from scaleY 0 (origin bottom), spring.settle, 40 ms apart. Long ranges (44 nights)
  // compress the step so the last bar still starts within ~0.4 s.
  function barsEntrance(container) {
    const spans = Array.from(container.querySelectorAll('.bar > span, .wk-col > span'));
    spans.forEach((s) => M.set(s, { scaleY: 0 }));
    const step = Math.min(M.tokens.stagger.bar, 400 / Math.max(1, spans.length));
    return () => spans.forEach((s, i) => {
      M.animate(s, { scaleY: [0, 1] }, { spring: 'settle', delay: i * step }).then(() => {
        if (s.__motion && s.__motion.anim) return; // something newer owns it
        // Tapped while still growing: settle into the selected scale instead of dropping it.
        if (s.closest('.is-selected')) M.animate(s, { scale: 1.03 }, { spring: 'settle' });
        else M.reset(s);
      });
    });
  }

  // Line: stroke draws over 800 ms; dots fade in as the line reaches them; columns grow.
  function trendEntrance(svg) {
    const DRAW = 800;
    const ease = M.bezier(0.33, 1, 0.68, 1);
    const lines = Array.from(svg.querySelectorAll('.trend-line'));
    const dots = Array.from(svg.querySelectorAll('.trend-dot'));
    const cols = Array.from(svg.querySelectorAll('.trend-col'));
    lines.forEach((l) => {
      const len = l.getTotalLength ? l.getTotalLength() : 0;
      l.__len = len;
      l.style.strokeDasharray = len + ' ' + len;
      l.style.strokeDashoffset = String(len);
    });
    dots.forEach((d) => { d.style.opacity = '0'; });
    cols.forEach((c) => M.set(c, { scaleY: 0 }));

    // Time at which the eased draw reaches a given fraction of the length.
    const reachAt = (frac) => {
      for (let i = 0; i <= 100; i++) if (ease(i / 100) >= frac) return (i / 100) * DRAW;
      return DRAW;
    };
    const fractionAtX = (line, x) => {
      const pts = Array.from(line.points || []);
      if (pts.length < 2) return 1;
      let acc = 0;
      for (let i = 1; i < pts.length; i++) {
        const seg = Math.hypot(pts[i].x - pts[i - 1].x, pts[i].y - pts[i - 1].y);
        if (x <= pts[i].x + 0.01) {
          const t = (x - pts[i - 1].x) / ((pts[i].x - pts[i - 1].x) || 1);
          return (acc + seg * Math.max(0, Math.min(1, t))) / (line.__len || 1);
        }
        acc += seg;
      }
      return 1;
    };

    return () => {
      lines.forEach((l) => {
        l.animate([{ strokeDashoffset: l.__len }, { strokeDashoffset: 0 }],
          { duration: DRAW, easing: 'cubic-bezier(0.33, 1, 0.68, 1)', fill: 'forwards' })
          .finished.then(() => { l.style.strokeDasharray = ''; l.style.strokeDashoffset = ''; }).catch(() => {});
      });
      const ref = lines[0];
      dots.forEach((d) => {
        const at = ref ? reachAt(fractionAtX(ref, Number(d.getAttribute('cx')))) : 0;
        d.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 120, delay: at, fill: 'forwards', easing: 'ease-out' })
          .finished.then(() => { d.style.opacity = ''; }).catch(() => {});
      });
      cols.forEach((c, i) => {
        M.animate(c, { scaleY: [0, 1] }, { spring: 'settle', delay: i * M.tokens.stagger.bar }).then(() => M.reset(c));
      });
    };
  }

  // Ranked lists: the track is there from the start, the fill grows from the left.
  function rankEntrance(list) {
    const fills = Array.from(list.querySelectorAll('.hbar-track > span, .occ-track > span'));
    fills.forEach((f) => M.set(f, { scaleX: 0 }));
    return () => fills.forEach((f, i) => {
      M.animate(f, { scaleX: [0, 1] }, { timing: { curve: M.tokens.ease.out.curve, duration: 500 }, delay: i * M.tokens.stagger.rank })
        .then(() => M.reset(f));
    });
  }

  function mixEntrance(bar) {
    bar.style.clipPath = 'inset(0 100% 0 0 round 99px)';
    return () => {
      bar.animate([{ clipPath: 'inset(0 100% 0 0 round 99px)' }, { clipPath: 'inset(0 0% 0 0 round 99px)' }],
        { duration: 500, easing: 'cubic-bezier(0, 0, 0.2, 1)' })
        .finished.finally(() => { bar.style.clipPath = ''; });
    };
  }

  // Donut: the ring sweeps from 0 to its real shares over 800 ms; the centre counts in parallel.
  let sweepRegistered = false;
  function donutSweep(donut) {
    if (!M || M.reduced()) return;
    if (!sweepRegistered) {
      try {
        CSS.registerProperty({ name: '--sweep', syntax: '<number>', inherits: false, initialValue: '100' });
      } catch (e) { /* already registered, or unsupported */ }
      sweepRegistered = true;
    }
    if (!window.CSS || !CSS.supports('mask', 'conic-gradient(#000 10%, transparent 0)') &&
        !CSS.supports('-webkit-mask', 'conic-gradient(#000 10%, transparent 0)')) return;
    donut.setAttribute('data-sweep', '');
    donut.animate([{ '--sweep': 0 }, { '--sweep': 100 }], { duration: 800, easing: 'cubic-bezier(0.33, 1, 0.68, 1)' })
      .finished.finally(() => donut.removeAttribute('data-sweep'));
  }

  // ════════════════════════════════════════════════════════════════════════
  // Period switch: in place, no remount of the page
  // ════════════════════════════════════════════════════════════════════════

  function initPeriod() {
    const toggle = root.querySelector('[data-custom-toggle]');
    const form = root.querySelector('[data-custom-form]');
    if (toggle && form) {
      toggle.addEventListener('click', () => {
        form.hidden = !form.hidden;
        toggle.setAttribute('aria-expanded', String(!form.hidden));
        if (!form.hidden) form.querySelector('input').focus();
      });
      form.addEventListener('submit', (event) => {
        const url = form.getAttribute('action') + '?' + new URLSearchParams(new FormData(form)).toString();
        if (!swapPeriod(url, toggle)) return;
        event.preventDefault();
      });
    }
    root.addEventListener('click', (event) => {
      const link = event.target.closest('[data-period-link]');
      if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button) return;
      if (link.classList.contains('is-active') && !root.classList.contains('is-loading')) { event.preventDefault(); return; }
      if (swapPeriod(link.href, link)) event.preventDefault();
    });
    window.addEventListener('popstate', (event) => {
      if (event.state && event.state.oneReports) swapPeriod(location.href, null, true);
    });
    if (root.querySelector('[data-report-body]')) {
      history.replaceState(Object.assign({}, history.state, { oneReports: true }), '');
    }
  }

  let swapToken = 0;
  function swapPeriod(url, chip, fromHistory) {
    const body = root.querySelector('[data-report-body]');
    if (!body || !window.fetch || !window.DOMParser) {
      root.classList.add('is-loading');
      return false; // plain navigation
    }
    const token = ++swapToken;
    const prev = M ? snapshot(body) : null;

    // The chosen chip is active at once; the pill slides to it while the data loads.
    if (chip) setActiveChip(chip);
    root.classList.add('is-loading');

    let outgoing = Promise.resolve();
    if (M) {
      const exit = { timing: { curve: M.tokens.ease.exit.curve, duration: 140 } };
      outgoing = Promise.all(Array.from(body.querySelectorAll(NUMBER_SEL)).map((el) =>
        M.animate(el, { y: [0, -M.tokens.distance.number], opacity: [1, 0] }, exit)));
    }

    fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } })
      .then((r) => {
        if (!r.ok || new URL(r.url).pathname !== new URL(url, location.href).pathname) throw new Error('nav');
        return r.text();
      })
      .then((html) => outgoing.then(() => html))
      .then((html) => {
        if (token !== swapToken) return; // a newer period was picked meanwhile
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const next = doc.querySelector('[data-reports]');
        const nextBody = next && next.querySelector('[data-report-body]');
        if (!nextBody) throw new Error('nav');

        syncChrome(next);
        body.replaceWith(nextBody);
        if (!fromHistory) history.pushState({ oneReports: true }, '', url);
        root.classList.remove('is-loading');
        initChannels(nextBody);

        if (M) {
          const T = M.tokens;
          nextBody.querySelectorAll(NUMBER_SEL).forEach((el) =>
            M.animate(el, { y: [T.distance.number, 0], opacity: [0, 1] }, { spring: 'settle', per: { opacity: { curve: T.ease.enter.curve, duration: 180 } } })
              .then(() => M.reset(el)));
        }
        enter(nextBody, prev);
      })
      .catch(() => { if (token === swapToken) location.href = url; });
    return true;
  }

  function setActiveChip(chip) {
    const group = chip.closest('.chips');
    if (!group) return;
    group.querySelectorAll('.chip').forEach((c) => c.classList.toggle('is-active', c === chip));
    if (group.__pill) group.__pill.sync();
  }

  // Everything outside the body that depends on the period.
  function syncChrome(next) {
    const pick = (sel) => [root.querySelector(sel), next.querySelector(sel)];

    const [chips, nextChips] = pick('.period-bar .chips');
    if (chips && nextChips) {
      const nextActive = nextChips.querySelector('.chip.is-active');
      const href = nextActive ? nextActive.getAttribute('href') : null;
      const custom = !!(nextActive && nextActive.hasAttribute('data-custom-toggle'));
      chips.querySelectorAll('.chip').forEach((c) => {
        const on = href ? c.getAttribute('href') === href : (custom && c.hasAttribute('data-custom-toggle'));
        c.classList.toggle('is-active', on);
      });
      if (chips.__pill) chips.__pill.sync();
    }
    const [label, nextLabel] = pick('.period-label');
    if (label && nextLabel) label.innerHTML = nextLabel.innerHTML;
    const [form, nextForm] = pick('[data-custom-form]');
    if (form && nextForm) {
      nextForm.querySelectorAll('input[name]').forEach((i) => {
        const mine = form.querySelector(`input[name="${i.name}"]`);
        if (mine) mine.value = i.value;
      });
    }
    const [hero, nextHero] = pick('.hero p');
    if (hero && nextHero) hero.textContent = nextHero.textContent;

    // Error banner between the period bar and the body.
    const alertNow = root.querySelector(':scope > .alert-error');
    const alertNext = next.querySelector(':scope > .alert-error');
    if (alertNow) alertNow.remove();
    if (alertNext) root.querySelector('[data-report-body]').before(document.importNode(alertNext, true));
  }

  // ════════════════════════════════════════════════════════════════════════
  // Interactions (delegated, so they survive a period swap)
  // ════════════════════════════════════════════════════════════════════════

  function initBars() {
    root.addEventListener('click', (event) => {
      const bar = event.target.closest('[data-bars] .bar');
      if (!bar) return;
      const bars = bar.closest('[data-bars]');
      const tip = bars.parentElement.querySelector('[data-chart-tip]');
      bars.querySelectorAll('.bar.is-selected').forEach((b) => {
        if (b === bar) return;
        b.classList.remove('is-selected');
        if (M) M.animate(b.firstElementChild, { scale: 1 }, { spring: 'settle' }).then(() => M.reset(b.firstElementChild));
      });
      bar.classList.add('is-selected');
      bars.classList.add('has-selection');
      if (M) M.animate(bar.firstElementChild, { scale: 1.03 }, { spring: 'settle' });
      if (tip) {
        tip.textContent = bar.dataset.tip;
        if (M) M.animate(tip, { y: [4, 0], opacity: [0, 1] }, { timing: { curve: M.tokens.ease.enter.curve, duration: 120 } });
      }
    });
  }

  const LEI = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
  const UNITS = { reservations: 'rezervări', nights: 'nopți', revenue: 'Lei' };

  function initChannels(scope) {
    const box = scope.querySelector('[data-channels]');
    if (!box) return;
    const legend = box.querySelector('[data-legend]');
    const donut = box.querySelector('[data-donut]');
    if (!legend || !donut) return;
    const valueEl = box.querySelector('[data-donut-value]');
    const render = (metric, animate) => {
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
      const before = M ? (M.parseNumber(valueEl.textContent) || { value: 0 }).value : 0;
      valueEl.textContent = LEI.format(total);
      box.querySelector('[data-donut-unit]').textContent = UNITS[metric];
      if (M && animate) {
        donutSweep(donut);
        M.countUp(valueEl, { from: animate === 'enter' ? 0 : before, duration: 800 });
      }
    };
    box.querySelectorAll('[data-channel-metric]').forEach((input) => {
      input.addEventListener('change', () => render(input.value, 'switch'));
    });
    const checked = box.querySelector('[data-channel-metric]:checked');
    render(checked ? checked.value : 'reservations', false);

    // First sweep when the donut scrolls into view.
    if (M && !M.reduced()) {
      if ('IntersectionObserver' in window) {
        donut.setAttribute('data-sweep', '');
        donut.style.setProperty('--sweep', '0');
        const io = new IntersectionObserver((entries) => {
          if (!entries[0].isIntersecting) return;
          io.disconnect();
          donut.style.removeProperty('--sweep');
          render(checked ? checked.value : 'reservations', 'enter');
        }, { threshold: 0.25 });
        io.observe(donut);
      } else {
        render(checked ? checked.value : 'reservations', 'enter');
      }
    }
  }

  function initSheet() {
    const sheet = root.querySelector('[data-apt-sheet]');
    const body = root.querySelector('[data-sheet-body]');
    if (!sheet || !body || typeof sheet.showModal !== 'function') return;
    root.addEventListener('click', (event) => {
      const row = event.target.closest('[data-apt-table] [data-apt]');
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
