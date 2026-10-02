/* SmartStay ONE — Inventar. Port of inventory/assets/js/main.js.
   Stock is rendered by PHP; this file adds the +/- counters (serialised per item, optimistic),
   the "Necesar" autosave, filters, search and today's Previo status. Access is enforced
   server-side — data-can-edit only mirrors it. */
(function () {
  'use strict';

  const root = document.querySelector('[data-inventory]');
  if (!root) return;

  const CAN_EDIT = root.dataset.canEdit === '1';
  const LEVEL = {
    critical: ['badge-critical', 'Critic'],
    warning: ['badge-warning', 'Stoc redus'],
    ok: ['badge-success', 'OK'],
  };
  const $list = root.querySelector('[data-list]');
  const $empty = root.querySelector('[data-empty]');
  const $search = root.querySelector('[data-search]');
  const $occErr = root.querySelector('[data-occupancy-error]');
  const cards = () => Array.from($list.querySelectorAll('.inv-card'));

  let filter = root.dataset.filter || 'all';
  let occupancy = {};
  let occupancyLoaded = false;

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function icon(name) { return `<svg class="icon icon-sm" aria-hidden="true"><use href="#i-${name}"/></svg>`; }
  function toast(m) { window.ONE.toast(m); }
  function fmtDate(d) { const p = String(d).split('-'); return p.length === 3 ? `${p[2]}.${p[1]}` : d; }

  // ── Status from Previo ──────────────────────────────────────────────────
  function statusOf(card) {
    if (card.dataset.depot === '1') return null;
    return occupancy[card.dataset.apt] || null;
  }

  function renderStrip(card) {
    const box = card.querySelector('[data-res]');
    const s = statusOf(card);
    const parts = [];
    if (s && s.checkOut) {
      parts.push(`<div class="strip strip-indigo">${icon('door')}<span><strong>Check-out azi · ${esc(s.checkOut.time)}</strong>` +
        `<span class="strip-sub">${esc(s.checkOut.guest)}</span></span></div>`);
    }
    if (s && s.checkIn) {
      parts.push(`<div class="strip strip-teal">${icon('calendar')}<span><strong>Check-in azi · ${esc(s.checkIn.time)}</strong>` +
        `<span class="strip-sub">${esc(s.checkIn.guest)}</span></span></div>`);
    }
    if (s && s.staying && !s.checkIn && !s.checkOut) {
      parts.push(`<div class="strip strip-neutral">${icon('guests')}<span><strong>Oaspete cazat</strong>` +
        `<span class="strip-sub">${esc(s.staying.guest)} · până pe ${esc(fmtDate(s.staying.until))}</span></span></div>`);
    }
    box.innerHTML = parts.join('');
    box.hidden = parts.length === 0;
  }

  // Free / turnover first (lowest linen on top), in-house guests after, depot last.
  function bucket(card) {
    if (card.dataset.depot === '1') return 2;
    const s = statusOf(card);
    return s && s.staying && !s.checkIn && !s.checkOut ? 1 : 0;
  }
  function resort() {
    const sorted = cards().sort((a, b) =>
      bucket(a) - bucket(b)
      || (bucket(a) === 0 ? Number(a.dataset.linen) - Number(b.dataset.linen) : 0)
      || a.dataset.apt.localeCompare(b.dataset.apt, 'ro', { numeric: true }));
    sorted.forEach((c) => $list.appendChild(c));
  }

  function loadOccupancy() {
    $occErr.hidden = true;
    return window.ONE.api('/api/inventory/occupancy', { timeout: 40000 })
      .then((data) => {
        occupancy = data.apartments || {};
        occupancyLoaded = true;
        cards().forEach(renderStrip);
        resort();
        updateCounts();
        apply();
      })
      .catch((e) => {
        $occErr.hidden = false;
        $occErr.textContent = 'Statusul rezervărilor nu s-a putut încărca (' + e.message + '). Stocul se poate modifica normal.';
      });
  }

  // ── Filters, search, counts ─────────────────────────────────────────────
  function matches(card) {
    const s = statusOf(card);
    if (filter === 'critical' && card.dataset.stock !== 'critical') return false;
    if (filter === 'checkin' && !(s && s.checkIn)) return false;
    if (filter === 'checkout' && !(s && s.checkOut)) return false;
    const q = ($search.value || '').trim().toLowerCase();
    if (!q) return true;
    const guests = s ? [s.checkIn, s.checkOut, s.staying].filter(Boolean).map((x) => x.guest).join(' ') : '';
    return (card.dataset.apt + ' ' + guests).toLowerCase().includes(q);
  }

  function apply() {
    let shown = 0;
    cards().forEach((c) => { c.hidden = !matches(c); if (!c.hidden) shown++; });
    $empty.hidden = shown > 0 || cards().length === 0;
  }

  function updateCounts() {
    const all = cards();
    const set = (key, n) => { const el = root.querySelector(`[data-count="${key}"]`); if (el) el.textContent = n; };
    set('all', all.length);
    set('critical', all.filter((c) => c.dataset.stock === 'critical').length);
    if (occupancyLoaded) {
      set('checkin', all.filter((c) => { const s = statusOf(c); return s && s.checkIn; }).length);
      set('checkout', all.filter((c) => { const s = statusOf(c); return s && s.checkOut; }).length);
    }
  }

  root.querySelectorAll('[data-filter-chip]').forEach((chip) => {
    chip.addEventListener('click', () => {
      filter = chip.dataset.filterChip;
      root.querySelectorAll('[data-filter-chip]').forEach((c) => {
        const on = c === chip;
        c.classList.toggle('is-active', on);
        c.setAttribute('aria-pressed', on);
      });
      apply();
    });
  });
  let searchTimer = null;
  $search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 120); });

  // ── Counters: optimistic, one request at a time per apartment + item ────
  const chains = new Map();
  const pending = new Map();

  function setLevel(card, level) {
    if (!LEVEL[level] || card.dataset.stock === level) return;
    card.dataset.stock = level;
    const badge = card.querySelector('[data-level-badge]');
    badge.className = 'badge ' + LEVEL[level][0];
    badge.textContent = LEVEL[level][1];
    card.classList.remove('is-flash');
    void card.offsetWidth; // restart the animation
    card.classList.add('is-flash');
    updateCounts();
  }

  function stamp(card) {
    const el = card.querySelector('[data-last]');
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    el.textContent = `Modificat de tine · ${pad(now.getDate())}.${pad(now.getMonth() + 1)} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
    el.hidden = false;
  }

  $list.addEventListener('click', (event) => {
    const btn = event.target.closest('.counter-btn');
    if (!btn || !CAN_EDIT) return;
    const card = btn.closest('.inv-card');
    const counter = btn.closest('.counter');
    const item = counter.dataset.item;
    const delta = Number(btn.dataset.delta);
    const out = counter.querySelector('[data-value]');
    const key = card.dataset.apt + '|' + item;

    const current = Number(out.textContent) || 0;
    if (delta < 0 && current <= 0) return;
    const shown = current + delta;
    out.textContent = shown;
    out.classList.remove('is-bump');
    void out.offsetWidth;
    out.classList.add('is-bump');
    if (item === 'lenjerie') card.dataset.linen = shown;
    pending.set(key, (pending.get(key) || 0) + 1);

    const run = () => window.ONE.api('/api/inventory/adjust', {
      method: 'POST', body: { apartment: card.dataset.apt, item, delta },
    }).then((res) => {
      const left = pending.get(key) - 1;
      pending.set(key, left);
      // Only the last answer in the queue decides what is shown (it reflects every tap).
      if (left === 0) {
        out.textContent = res.value;
        if (item === 'lenjerie') { card.dataset.linen = res.value; setLevel(card, res.level); }
      }
      stamp(card);
    }, (e) => {
      pending.set(key, pending.get(key) - 1);
      out.textContent = Math.max(0, (Number(out.textContent) || 0) - delta);
      if (item === 'lenjerie') card.dataset.linen = out.textContent;
      toast('Nu s-a salvat: ' + e.message);
    });

    const chain = (chains.get(key) || Promise.resolve()).then(run, run);
    chains.set(key, chain);
  });

  // ── Necesar (front) + Tehnic (back): autosave 800 ms after typing, and on blur ──
  const SAVE = {
    note: { url: '/api/inventory/note', field: 'note', error: 'Nota nu s-a salvat: ' },
    tech: { url: '/api/inventory/tech', field: 'tech', error: 'Nota tehnică nu s-a salvat: ' },
  };
  const saveTimers = new Map();
  function autosave(area) {
    const card = area.closest('.inv-card');
    const kind = area.dataset.autosave;
    const state = card.querySelector(`[data-save-state="${kind}"]`);
    clearTimeout(saveTimers.get(area));
    saveTimers.delete(area);
    if (area.value === area.dataset.saved) return;
    const value = area.value;
    state.textContent = 'Se salvează…';
    window.ONE.api(SAVE[kind].url, { method: 'POST', body: { apartment: card.dataset.apt, [SAVE[kind].field]: value } })
      .then(() => {
        area.dataset.saved = value;
        state.textContent = 'Salvat';
        stamp(card);
        if (kind === 'tech') card.querySelector('[data-tech-badge]').hidden = value.trim() === '';
        setTimeout(() => { if (state.textContent === 'Salvat') state.textContent = ''; }, 2000);
      })
      .catch((e) => { state.textContent = ''; toast(SAVE[kind].error + e.message); });
  }
  root.querySelectorAll('[data-autosave]').forEach((area) => {
    area.dataset.saved = area.value;
    if (!CAN_EDIT) return;
    area.addEventListener('input', () => {
      clearTimeout(saveTimers.get(area));
      saveTimers.set(area, setTimeout(() => autosave(area), 800));
    });
    area.addEventListener('blur', () => { if (saveTimers.has(area)) autosave(area); });
  });

  // ── Flip: 2D scaleX (3D transforms freeze scrolling on iOS WebKit) ─────────
  function flip(card) {
    if (card.classList.contains('is-flipping')) return;
    const front = card.querySelector('[data-face="front"]');
    const back = card.querySelector('[data-face="back"]');
    // Unsaved typing on the face we leave is saved first.
    card.querySelectorAll('[data-autosave]').forEach((a) => { if (saveTimers.has(a)) autosave(a); });
    card.classList.add('is-flipping', 'flip-out');
    setTimeout(() => {
      const toBack = back.hidden;
      front.hidden = toBack;
      back.hidden = !toBack;
      card.classList.toggle('is-back', toBack);
      card.classList.replace('flip-out', 'flip-in');
      setTimeout(() => card.classList.remove('is-flipping', 'flip-in'), 170);
    }, 150);
  }

  // ── Set / box (back) with undo ──────────────────────────────────────────
  const undoTimers = new Map();
  function showValues(card, values, level) {
    Object.entries(values).forEach(([item, v]) => {
      const out = card.querySelector(`.counter[data-item="${item}"] [data-value]`);
      if (out) out.textContent = v;
    });
    card.dataset.linen = values.lenjerie;
    setLevel(card, level);
    updateCounts();
  }
  function batch(card, op, deltas, buttons) {
    buttons.forEach((b) => { b.disabled = true; });
    const body = { apartment: card.dataset.apt, op };
    if (deltas) body.deltas = deltas;
    return window.ONE.api('/api/inventory/batch', { method: 'POST', body })
      .then((res) => {
        showValues(card, res.values, res.level);
        stamp(card);
        toast(res.message);
        const undo = card.querySelector('[data-undo]');
        clearTimeout(undoTimers.get(card));
        if (op === 'undo') { undo.hidden = true; return; }
        const changed = Object.values(res.applied).some((n) => n !== 0);
        if (!changed) { undo.hidden = true; return; }
        undo.dataset.deltas = JSON.stringify(res.applied);
        undo.querySelector('[data-undo-text]').textContent = op === 'set' ? 'Set scăzut' : 'Cutie adăugată';
        undo.hidden = false;
        undoTimers.set(card, setTimeout(() => { undo.hidden = true; }, 8000));
      })
      .catch((e) => toast('Nu s-a salvat: ' + e.message))
      .finally(() => buttons.forEach((b) => { b.disabled = false; }));
  }

  $list.addEventListener('click', (event) => {
    const flipBtn = event.target.closest('[data-flip]');
    if (flipBtn) { flip(flipBtn.closest('.inv-card')); return; }
    if (!CAN_EDIT) return;
    const card = event.target.closest('.inv-card');
    if (!card) return;
    const batchBtn = event.target.closest('[data-batch]');
    if (batchBtn) {
      batch(card, batchBtn.dataset.batch, null, Array.from(card.querySelectorAll('[data-batch], [data-undo-btn]')));
      return;
    }
    if (event.target.closest('[data-undo-btn]')) {
      const undo = card.querySelector('[data-undo]');
      batch(card, 'undo', JSON.parse(undo.dataset.deltas || '{}'), Array.from(card.querySelectorAll('[data-batch], [data-undo-btn]')));
    }
  });

  // TV App: saved on tap, rolled back on error.
  $list.addEventListener('change', (event) => {
    const box = event.target.closest('[data-tv-app]');
    if (!box || !CAN_EDIT) return;
    const card = box.closest('.inv-card');
    box.disabled = true;
    window.ONE.api('/api/inventory/tech', { method: 'POST', body: { apartment: card.dataset.apt, tvApp: box.checked } })
      .then(() => { stamp(card); toast(box.checked ? 'TV App bifat' : 'TV App debifat'); })
      .catch((e) => { box.checked = !box.checked; toast('Nu s-a salvat: ' + e.message); })
      .finally(() => { box.disabled = false; });
  });

  // ── Refresh ─────────────────────────────────────────────────────────────
  const refreshBtn = root.querySelector('[data-refresh]');
  refreshBtn.addEventListener('click', () => {
    refreshBtn.classList.add('is-spinning');
    setTimeout(() => refreshBtn.classList.remove('is-spinning'), 700);
    loadOccupancy();
  });

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.ONE) return;
    apply();
    loadOccupancy();
  });
})();
