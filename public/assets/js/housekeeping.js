/* SmartStay ONE — Housekeeping for staff: Check-out allocation + Intermediară.
   Port of housekeeping/public/index.php + intermediate.php. Access is enforced server-side;
   data-can-edit only mirrors it. Maids never load this file (they get their own list). */
(function () {
  'use strict';

  const root = document.querySelector('[data-housekeeping]');
  if (!root) return;

  const TAB = root.dataset.tab;
  const CAN_EDIT = root.dataset.canEdit === '1';
  const SELF = root.dataset.self || null; // a maid's own name: she can only take free apartments for herself
  const $list = root.querySelector('[data-list]');
  const $error = root.querySelector('[data-error]');

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function icon(name) { return `<svg class="icon icon-sm" aria-hidden="true"><use href="#i-${name}"/></svg>`; }
  function toast(m) { window.ONE.toast(m); }
  function showError(msg) { $error.hidden = !msg; $error.textContent = msg || ''; }
  function skeletons(n) {
    return Array.from({ length: n }, () =>
      '<div class="card res-skeleton"><div class="skeleton" style="width:40%;height:18px"></div><div class="skeleton" style="width:70%;height:14px"></div></div>').join('');
  }
  function empty(title, text) {
    return `<div class="card empty"><span class="tile-icon" data-module="housekeeping">${icon('housekeeping')}</span><h2>${esc(title)}</h2><p>${esc(text)}</p></div>`;
  }
  function fmt(d) { const p = String(d).split('-'); return p.length === 3 ? `${p[2]}.${p[1]}` : d; }

  let loader = null;
  let lastLoad = 0;
  const refreshBtn = root.querySelector('[data-refresh]');
  refreshBtn.addEventListener('click', () => {
    refreshBtn.classList.add('is-spinning');
    setTimeout(() => refreshBtn.classList.remove('is-spinning'), 700);
    loader && loader();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && loader && Date.now() - lastLoad > 60000) loader();
  });

  // ── Check-out: select apartments → tap a maid ───────────────────────────
  function initCheckout() {
    const selected = new Set();
    const $bar = root.querySelector('[data-assign-bar]');
    const $sel = root.querySelector('[data-selected]');
    let rows = [];

    function syncBar() {
      if (!$bar) return;
      $sel.textContent = selected.size;
      $bar.hidden = selected.size === 0;
    }

    const mine = (r) => !!SELF && r.assigned.length > 0 && r.assigned.every((m) => m === SELF);
    const pickable = (r) => CAN_EDIT && r.submissions === 0 && (!SELF || r.assigned.length === 0);

    function status(r) {
      if (r.submissions >= 2) return '<span class="badge badge-success">' + icon('check') + 'Finalizat</span>';
      if (r.submissions === 1) return '<span class="badge badge-success">' + icon('check') + 'Checklist trimis</span>';
      if (mine(r)) return '<span class="badge badge-violet">' + icon('check') + 'Al tău</span>';
      if (r.assigned.length) return `<span class="badge badge-violet">${esc(r.assigned.join(', '))}</span>`;
      return '<span class="badge badge-warning">Nealocat</span>';
    }

    function card(r) {
      const on = selected.has(r.apartment);
      const canPick = pickable(r);
      const canChecklist = r.assigned.length && (!SELF || mine(r));
      const sub = r.checkOutTime ? `${icon('door')} Check-out azi · ${esc(r.checkOutTime)}` : `${icon('calendar')} Alocat azi`;
      return `<article class="card hk-card${on ? ' is-selected' : ''}${canPick ? ' is-selectable' : ''}${SELF && r.assigned.length && !mine(r) ? ' is-taken' : ''}" data-apt="${esc(r.apartment)}"
          ${canPick ? 'role="button" tabindex="0" aria-pressed="' + on + '"' : ''}>
        <span class="apt-badge apt-badge-violet"><span>Apt</span><strong>${esc(r.apartment)}</strong></span>
        <span class="grow">
          <span class="list-title">${SELF ? 'Apartament ' + esc(r.apartment) : esc(r.guest)}</span>
          <span class="list-sub">${sub}</span>
          <span class="hk-status">${status(r)}</span>
        </span>
        <span class="hk-side">
          ${canChecklist ? `<a class="link-btn" href="/housekeeping/checklist/${encodeURIComponent(r.apartment)}">Checklist</a>` : ''}
          ${canPick ? `<span class="pick" aria-hidden="true">${icon('check')}</span>` : ''}
        </span>
      </article>`;
    }

    function render() {
      $list.innerHTML = rows.length
        ? rows.map(card).join('')
        : empty('Niciun apartament de curățat azi', 'Nu sunt check-out-uri programate pentru astăzi.');
      syncBar();
    }

    function load() {
      lastLoad = Date.now();
      showError('');
      $list.innerHTML = skeletons(4);
      return window.ONE.api('/api/housekeeping/checkouts', { timeout: 30000 })
        .then((data) => {
          rows = data.checkouts || [];
          if (SELF) rows.sort((a, b) => (mine(b) - mine(a)) || (pickable(b) - pickable(a)));
          // Drop selections that are no longer pickable.
          [...selected].forEach((a) => { if (!rows.some((r) => r.apartment === a && pickable(r))) selected.delete(a); });
          render();
        })
        .catch((e) => { $list.innerHTML = ''; showError('Nu s-au putut încărca apartamentele: ' + e.message); });
    }
    loader = load;

    function togglePick(el) {
      const apt = el.dataset.apt;
      selected.has(apt) ? selected.delete(apt) : selected.add(apt);
      el.classList.toggle('is-selected', selected.has(apt));
      el.setAttribute('aria-pressed', selected.has(apt));
      syncBar();
    }

    $list.addEventListener('click', (event) => {
      if (event.target.closest('a')) return;
      const el = event.target.closest('.hk-card.is-selectable');
      if (el) togglePick(el);
    });
    $list.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      const el = event.target.closest('.hk-card.is-selectable');
      if (el) { event.preventDefault(); togglePick(el); }
    });

    if ($bar) {
      $bar.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-assign]');
        if (!btn || !selected.size) return;
        const buttons = $bar.querySelectorAll('[data-assign]');
        buttons.forEach((b) => { b.disabled = true; });
        window.ONE.api('/api/housekeeping/assign', { method: 'POST', body: { maid: btn.dataset.assign, apartments: [...selected] } })
          .then((res) => { toast(res.message); selected.clear(); return load(); })
          .catch((e) => toast(e.message))
          .finally(() => buttons.forEach((b) => { b.disabled = false; }));
      });
    }

    load();
  }

  // ── Intermediară: in-house guests → 30 RON flat cleaning ────────────────
  function initIntermediate() {
    const $maid = root.querySelector('[data-maid-select]');
    let rows = [];
    let done = {};
    let rate = 30;

    function card(g) {
      const by = done[g.apartment];
      return `<article class="card hk-inter" data-apt="${esc(g.apartment)}">
        <div class="hk-card">
          <span class="apt-badge apt-badge-violet"><span>Apt</span><strong>${esc(g.apartment)}</strong></span>
          <span class="grow">
            <span class="list-title">${esc(g.guest)}</span>
            <span class="list-sub">${icon('calendar')} ${esc(fmt(g.checkIn))} → ${esc(fmt(g.checkOut))}</span>
          </span>
          <span class="badge badge-violet tabular">${rate} RON</span>
        </div>
        ${by
          ? `<div class="strip strip-success">${icon('check')}<span>Înregistrată azi · <strong>${esc(by)}</strong></span></div>`
          : (CAN_EDIT ? `<button type="button" class="btn btn-violet btn-block btn-sm" data-create="${esc(g.apartment)}" data-res="${esc(g.reservationId)}">Creează curățenie intermediară</button>` : '')}
      </article>`;
    }

    function render() {
      $list.innerHTML = rows.length
        ? rows.map(card).join('')
        : empty('Niciun oaspete cazat', 'Nu există apartamente ocupate în acest moment.');
    }

    function load() {
      lastLoad = Date.now();
      showError('');
      $list.innerHTML = skeletons(4);
      return window.ONE.api('/api/housekeeping/active-guests', { timeout: 40000 })
        .then((data) => { rows = data.guests || []; done = data.done || {}; rate = data.rate || 30; render(); })
        .catch((e) => { $list.innerHTML = ''; showError('Eroare la încărcare: ' + e.message); });
    }
    loader = load;

    $list.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-create]');
      if (!btn) return;
      if (!$maid || !$maid.value) { toast('Selectează mai întâi menajera.'); $maid && $maid.focus(); return; }
      btn.disabled = true;
      btn.textContent = 'Se înregistrează…';
      window.ONE.api('/api/housekeeping/intermediate', {
        method: 'POST', body: { maid: $maid.value, apartment: btn.dataset.create, reservationId: btn.dataset.res },
      })
        .then((res) => { toast(res.message); done[btn.dataset.create] = res.maid; render(); })
        .catch((e) => { toast(e.message); btn.disabled = false; btn.textContent = 'Creează curățenie intermediară'; });
    });

    load();
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.ONE) return;
    TAB === 'intermediate' ? initIntermediate() : initCheckout();
  });
})();
