/* SmartStay ONE — Rezervări (Astăzi · Mâine · WhatsApp · Link).
   Port of the legacy Reservations app (state.js, ui.js, filters.js, app.js, whatsapp.js,
   generate-link.php). Data is normalised server-side; access is enforced server-side —
   data-can-edit only mirrors it in the UI. */
(function () {
  'use strict';

  const root = document.querySelector('[data-reservations]');
  if (!root) return;

  const TAB = root.dataset.tab;
  const CAN_EDIT = root.dataset.canEdit === '1';
  const COMPACT = root.dataset.compact === '1'; // Menajeră: card redus, fără filtre / toggle-uri / acțiuni
  const $ = (sel) => root.querySelector(sel);
  const $list = $('[data-list]');
  const $count = $('[data-count]');
  const $error = $('[data-error]');
  const $search = $('[data-search]');

  // ── helpers ──────────────────────────────────────────────────────────────
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function icon(name, cls) {
    return `<svg class="icon ${cls || 'icon-sm'}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
  }
  function toast(msg) { window.ONE && window.ONE.toast(msg); }
  function showError(msg) {
    if (!$error) return;
    $error.hidden = !msg;
    $error.textContent = msg || '';
  }
  function skeletons(n) {
    return Array.from({ length: n }, () =>
      '<div class="card res-skeleton"><div class="skeleton" style="width:55%;height:18px"></div>' +
      '<div class="skeleton" style="width:35%;height:14px"></div><div class="skeleton" style="height:56px"></div></div>').join('');
  }
  function empty(title, text) {
    return `<div class="card empty"><span class="tile-icon">${icon('reservations', 'icon-lg')}</span>` +
      `<h2>${esc(title)}</h2><p>${esc(text)}</p></div>`;
  }
  function copy(text, label, onCopied) {
    const done = () => { toast(label || 'Copiat'); if (onCopied) onCopied(); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, () => window.prompt('Copiază:', text));
    } else {
      window.prompt('Copiază:', text);
    }
  }
  const M = window.MOTION;
  function toElement(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html.trim();
    return tpl.content.firstElementChild;
  }
  // Phones (and the installed PWA) open the WhatsApp app directly through whatsapp://.
  // An https link opened from the PWA lands in iOS's in-app browser sheet, which stays
  // behind as a white screen after WhatsApp takes over. Desktop keeps WhatsApp Web.
  const NATIVE_WA = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent)
    || window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  function waUrl(phone, message) {
    const query = 'phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(message);
    return NATIVE_WA ? 'whatsapp://send?' + query : 'https://api.whatsapp.com/send?' + query;
  }
  function openWa(phone, message) {
    if (!phone) { toast('Telefon lipsă pentru WhatsApp'); return false; }
    if (NATIVE_WA) {
      window.location.href = waUrl(phone, message);
    } else {
      window.open(waUrl(phone, message), '_blank', 'noopener');
    }
    return true;
  }
  function greeting(isRo) {
    const h = new Date().getHours();
    if (h >= 5 && h < 12) return isRo ? 'Bună dimineața' : 'Good morning';
    if (h >= 12 && h < 18) return isRo ? 'Bună ziua' : 'Good afternoon';
    return isRo ? 'Bună seara' : 'Good evening';
  }

  // Tab bar: slide the thumb to the tapped tab while the next page loads.
  const daynav = root.querySelector('[data-daynav]');
  if (daynav) {
    daynav.addEventListener('click', (event) => {
      const item = event.target.closest('.daynav-item');
      if (!item || item.classList.contains('is-active') || event.metaKey || event.ctrlKey) return;
      daynav.style.setProperty('--i', item.dataset.i);
      daynav.querySelectorAll('.daynav-item').forEach((el) => el.classList.toggle('is-active', el === item));
    });
  }

  // Refresh: header button, pull-to-refresh, and coming back to the app after more than a minute.
  // The icon turns once on spring.settle (no looping spinner); pull progress drives its rotation.
  let loader = null;
  let lastLoad = 0;
  const refreshBtn = root.querySelector('[data-refresh]');
  const refreshIcon = refreshBtn && refreshBtn.querySelector('.icon');
  function turnIcon() {
    if (!refreshIcon || !M) return;
    const now = M.valueOf(refreshIcon, 'rotate');
    M.animate(refreshIcon, { rotate: (Math.floor(now / 360) + 1) * 360 }, { spring: 'settle' });
  }
  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
      turnIcon();
      loader && loader({ replay: true });
    });
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && loader && Date.now() - lastLoad > 60000) loader({ quiet: true });
  });

  function initPullToRefresh(list) {
    if (!refreshBtn || !M || !('ontouchstart' in window)) return;
    const MAX = 84, TRIGGER = 64, HOLD = 48;
    let y0 = null, pull = 0, dragging = false, busy = false;
    document.documentElement.classList.add('ptr');

    window.addEventListener('touchstart', (e) => {
      y0 = busy || window.scrollY > 0 || e.touches.length > 1 ? null : e.touches[0].clientY;
      pull = 0;
      dragging = false;
    }, { passive: true });

    window.addEventListener('touchmove', (e) => {
      if (y0 === null) return;
      const dy = e.touches[0].clientY - y0;
      if (window.scrollY > 0 || (dy <= 0 && !dragging)) { y0 = null; return; }
      dragging = true;
      pull = Math.min(MAX, Math.max(0, dy) * 0.45);
      if (M.reduced()) return;
      M.set(list, { y: pull });
      if (refreshIcon) M.set(refreshIcon, { rotate: (pull / TRIGGER) * 300 });
    }, { passive: true });

    const release = () => {
      if (!dragging) { y0 = null; return; }
      dragging = false;
      y0 = null;
      if (pull < TRIGGER) {
        M.animate(list, { y: 0 }, { spring: 'settle' }).then(() => M.reset(list));
        if (refreshIcon) M.animate(refreshIcon, { rotate: 0 }, { spring: 'settle' });
        return;
      }
      busy = true;
      M.haptic();
      M.animate(list, { y: HOLD }, { spring: 'settle' });
      turnIcon();
      Promise.resolve(loader && loader({ replay: true, pulled: true })).finally(() => {
        busy = false;
        M.animate(list, { y: 0 }, { spring: 'settle' }).then(() => M.reset(list));
      });
    };
    window.addEventListener('touchend', release, { passive: true });
    window.addEventListener('touchcancel', release, { passive: true });
  }

  // ════════════════════════════════════════════════════════════════════════
  // Astăzi / Mâine
  // ════════════════════════════════════════════════════════════════════════
  function initDay() {
    const state = { rows: [], statuses: {}, filter: 'all', search: '', loaded: false };
    const $chips = $('[data-chips]');
    let pill = null;

    const FILTERS = [
      ['all', 'Toate'],
      ['pending-checkin', 'Check-in lipsă'],
      ['tax-unpaid', 'Taxă neplătită'],
      ['complete', 'Complete'],
      ['with-parking', 'Cu parcare'],
    ];
    const CHECK = '<svg class="icon status-check" aria-hidden="true"><use href="#i-check"/></svg>';

    function st(id) { return state.statuses[id] || {}; }

    function matches(r) {
      const q = state.search.trim().toLowerCase();
      if (q && ![r.name, r.phone, r.apartment, r.id].join(' ').toLowerCase().includes(q)) return false;
      const s = st(r.id);
      switch (state.filter) {
        case 'pending-checkin': return !s.checkin_completed;
        case 'tax-unpaid': return !s.city_tax_paid;
        case 'complete': return !!(s.city_tax_paid && s.checkin_completed);
        case 'with-parking': return !!r.parkingSpot;
        default: return true;
      }
    }

    function counts() {
      const c = { all: state.rows.length, 'pending-checkin': 0, 'tax-unpaid': 0, complete: 0, 'with-parking': 0 };
      state.rows.forEach((r) => {
        const s = st(r.id);
        if (!s.checkin_completed) c['pending-checkin']++;
        if (!s.city_tax_paid) c['tax-unpaid']++;
        if (s.city_tax_paid && s.checkin_completed) c.complete++;
        if (r.parkingSpot) c['with-parking']++;
      });
      return c;
    }

    function cardClass(s) {
      if (s.checkin_completed && s.city_tax_paid) return 'is-complete';
      if (!s.checkin_completed) return 'is-pending';
      return 'is-attention';
    }

    function hintHtml(on, hintOn, hintOff) {
      return (on ? CHECK : '') + `<span>${on ? hintOn : hintOff}</span>`;
    }

    function toggle(field, on, label, hintOn, hintOff, ic) {
      return `<button type="button" class="toggle-card${on ? ' is-on' : ''}" data-action="toggle" data-field="${field}"
        data-hint-on="${hintOn}" data-hint-off="${hintOff}" aria-pressed="${on}" ${CAN_EDIT ? '' : 'disabled'}>
        <span class="toggle-card__icon">${icon(ic)}</span>
        <span class="toggle-card__text"><span class="toggle-card__label">${label}</span>
        <span class="toggle-card__hint">${hintHtml(on, hintOn, hintOff)}</span></span>
        <span class="switch" aria-hidden="true"></span></button>`;
    }

    function phoneButton(r) {
      return r.phone
        ? `<button type="button" class="res-card__phone" data-action="copy-phone">${icon('phone')}<span data-phone-text>${esc(r.phone)}</span></button>`
        : '';
    }

    // Menajeră: nume, telefon, apartament, check-in/out (dată + oră), >2 oaspeți, nota de housekeeping.
    function compactCard(r) {
      return `<article class="card res-card" data-id="${esc(r.id)}">
        <div class="res-card__head">
          <div class="grow">
            <h3 class="res-card__name">${esc(r.name)}</h3>
            ${phoneButton(r)}
          </div>
          <div class="apt-badge"><span>Apt</span><strong>${esc(r.apartment || '—')}</strong></div>
        </div>
        <div class="res-card__dates">
          <div><span class="eyebrow">Check-in</span><strong>${esc(r.checkInLabel)}</strong><span class="muted">la ${esc(r.checkInTime)}</span></div>
          <div><span class="eyebrow">Check-out</span><strong>${esc(r.checkOutLabel)}</strong><span class="muted">la ${esc(r.checkOutTime)}</span></div>
        </div>
        ${r.guestCount > 2 ? `<div class="strip strip-warning" data-alert>${icon('alert')}<span>Atenție: sunt <strong>${r.guestCount}</strong> oaspeți</span></div>` : ''}
        ${r.note ? `<div class="strip strip-neutral">${icon('note')}<span class="pre">${esc(r.note)}</span></div>` : ''}
      </article>`;
    }

    function card(r) {
      if (COMPACT) return compactCard(r);
      const s = st(r.id);
      const nukiLabel = r.hasNuki && r.nukiCode ? `Nuki · ${esc(r.nukiCode)}` : 'Fără Nuki';
      return `<article class="card res-card ${cardClass(s)}" data-id="${esc(r.id)}">
        <div class="res-card__head">
          <div class="grow">
            <h3 class="res-card__name">${esc(r.name)}</h3>
            ${phoneButton(r)}
          </div>
          <div class="apt-badge"><span>Apt</span><strong>${esc(r.apartment || '—')}</strong></div>
        </div>
        <div class="res-card__dates">
          <div><span class="eyebrow">Check-in</span><strong>${esc(r.checkInLabel)}</strong><span class="muted">la ${esc(r.checkInTime)}</span></div>
          <div><span class="eyebrow">Check-out</span><strong>${esc(r.checkOutLabel)}</strong><span class="muted">la ${esc(r.checkOutTime)}</span></div>
        </div>
        ${r.parkingSpot ? `<div class="strip strip-blue" data-alert>${icon('parking')}<span>Parcare inclusă · <strong>${esc(r.parkingSpot)}</strong></span></div>` : ''}
        ${r.guestCount > 2 ? `<div class="strip strip-warning" data-alert>${icon('alert')}<span>Atenție: sunt <strong>${r.guestCount}</strong> oaspeți</span></div>` : ''}
        ${r.note ? `<div class="strip strip-neutral">${icon('note')}<span class="pre">${esc(r.note)}</span></div>` : ''}
        <div class="toggle-row">
          ${toggle('city_tax_paid', !!s.city_tax_paid, 'Taxă oraș', 'Plătită', 'Neplătită', 'receipt')}
          ${toggle('checkin_completed', !!s.checkin_completed, 'Check-in form', 'Completat', 'În așteptare', 'check')}
        </div>
        <div class="res-actions res-actions-3">
          <button type="button" class="act act-whatsapp" data-action="welcome" ${r.waPhone ? '' : 'disabled'}>${icon('whatsapp')}WhatsApp</button>
          <button type="button" class="act act-guest" data-action="guest-link">${icon('link')}Guest App</button>
          <button type="button" class="act act-nuki" data-action="nuki" ${CAN_EDIT && r.hasNuki && r.nukiCode ? '' : 'disabled'}
            ${r.hasNuki ? '' : 'title="Apartamentul nu are yală Nuki configurată"'}>${icon('nuki')}<span class="act-label">${nukiLabel}</span></button>
        </div>
      </article>`;
    }

    // ── Status morph: colour interpolates, hint width follows the label, check draws, one pop ──
    function morphToggle(btn, on, animate) {
      const hint = btn.querySelector('.toggle-card__hint');
      const w0 = hint.getBoundingClientRect().width;
      btn.classList.toggle('is-on', on);
      btn.setAttribute('aria-pressed', String(on));
      hint.innerHTML = hintHtml(on, btn.dataset.hintOn, btn.dataset.hintOff);
      if (!animate || !M || M.reduced()) return;
      const w1 = hint.getBoundingClientRect().width;
      if (Math.abs(w1 - w0) > 0.5) {
        M.animate(hint, { width: [w0, w1] }, { spring: 'settle' }).then(() => { hint.style.width = ''; });
      }
      if (on) {
        const check = hint.querySelector('.status-check');
        if (check) check.classList.add('is-drawing');
        clearTimeout(btn.__pop);
        btn.__pop = setTimeout(() => M.impulse(btn, 'scale', 1.04, 'pop').then(() => M.reset(btn)), 280);
      }
    }

    function applyStatus(el, s, animate) {
      if (COMPACT) return;
      const cls = cardClass(s);
      if (!el.classList.contains(cls)) {
        el.classList.remove('is-complete', 'is-pending', 'is-attention');
        el.classList.add(cls);
      }
      el.querySelectorAll('[data-action="toggle"]').forEach((btn) => {
        const on = !!s[btn.dataset.field];
        if (btn.classList.contains('is-on') !== on) morphToggle(btn, on, animate);
      });
    }

    // ── Chips: rendered once; counts update in place; one pill slides between them ──
    function renderChips() {
      if (!$chips) return;
      const c = counts();
      if (!$chips.querySelector('[data-filter]')) {
        $chips.innerHTML = FILTERS.map(([key, label]) =>
          `<button type="button" class="chip" data-filter="${key}">${label} <span class="count">0</span></button>`).join('');
        if (M) pill = M.chipPill($chips);
      }
      $chips.querySelectorAll('[data-filter]').forEach((chip) => {
        chip.classList.toggle('is-active', chip.dataset.filter === state.filter);
        chip.querySelector('.count').textContent = c[chip.dataset.filter] || 0;
      });
      if (pill) pill.sync();
    }

    // ── Keyed reconcile ────────────────────────────────────────────────
    // mode 'enter'  — first paint / refresh: staggered rise + scale from the top.
    // mode 'update' — filter, search, status, quiet refresh: FLIP for cards that stay,
    //                 exit up for cards that leave, short rise for cards that arrive.
    function signature(r) { return JSON.stringify(r); }

    function render(mode) {
      mode = mode || 'update';
      const shown = state.rows.filter(matches);
      renderChips();
      const total = state.rows.length;
      $count.textContent = shown.length === total ? String(total) : `${shown.length}/${total}`;

      const live = Array.from($list.children).filter((el) => !el.classList.contains('is-leaving'));
      const byId = new Map();
      const first = new Map();
      live.forEach((el) => {
        if (el.dataset.id) byId.set(el.dataset.id, el);
        if (mode === 'update') first.set(el, { top: el.getBoundingClientRect().top, offset: el.offsetTop });
      });

      const next = [];
      const entering = [];
      shown.forEach((r) => {
        const sig = signature(r);
        let el = byId.get(r.id);
        if (el && el.dataset.sig === sig) {
          byId.delete(r.id);
          applyStatus(el, st(r.id), mode === 'update');
        } else {
          el = toElement(card(r));
          el.dataset.sig = sig;
          entering.push(el);
        }
        next.push(el);
      });
      if (!shown.length) {
        const el = toElement(empty('Nimic de afișat', state.rows.length
          ? 'Nu sunt rezervări care să corespundă filtrelor.'
          : (TAB === 'tomorrow' ? 'Nu sunt check-in-uri programate pentru mâine.' : 'Nu sunt check-in-uri programate azi.')));
        next.push(el);
        entering.push(el);
      }

      const leaving = live.filter((el) => !next.includes(el));
      next.forEach((el) => $list.appendChild(el));

      const animated = M && mode !== 'instant';
      // Hand the card back to CSS (press :active) once nothing newer is animating it.
      const release = (el) => () => { if (!el.__motion || !el.__motion.anim) M.reset(el); };
      leaving.forEach((el) => {
        const f = first.get(el);
        if (!animated || !f) { el.remove(); return; }
        el.classList.add('is-leaving');
        el.style.top = f.offset + 'px';
        const y = M.valueOf(el, 'y');
        M.animate(el, { y: y - M.tokens.distance.filter, opacity: 0 },
          { timing: { curve: M.tokens.ease.exit.curve, duration: 140 } }).then(() => el.remove());
      });
      if (!animated) return;

      // Cards that stay: animate from where they were to where they are now.
      next.forEach((el) => {
        const f = first.get(el);
        if (!f) return;
        const dy = f.top - el.getBoundingClientRect().top;
        if (Math.abs(dy) < 0.5) return;
        M.animate(el, { y: [M.valueOf(el, 'y') + dy, 0] }, { spring: 'settle', keepVelocity: true }).then(release(el));
      });

      const viewport = window.innerHeight;
      const T = M.tokens;
      let i = 0;
      entering.forEach((el) => {
        if (el.getBoundingClientRect().top > viewport) return; // below the fold: no animation to watch
        const delay = mode === 'enter'
          ? Math.min(i * T.stagger.card, T.stagger.cardMax)
          : i * T.stagger.filter;
        i++;
        const props = mode === 'enter'
          ? { y: [T.distance.card, 0], scale: [0.985, 1], opacity: [0, 1] }
          : { y: [T.distance.filter, 0], opacity: [0, 1] };
        const opts = mode === 'enter'
          ? { timing: T.ease.enter, delay }
          : { spring: 'settle', per: { opacity: { curve: T.ease.enter.curve, duration: 180 } }, delay };
        M.animate(el, props, opts).then(release(el));
        el.querySelectorAll('[data-alert]').forEach((strip) => {
          strip.style.animationDelay = (delay + 60) + 'ms';
          strip.classList.add('is-mounting');
          strip.addEventListener('animationend', () => {
            strip.classList.remove('is-mounting');
            strip.style.animationDelay = '';
          }, { once: true });
        });
      });
    }

    function load(opts) {
      opts = opts || {};
      lastLoad = Date.now();
      showError('');
      if (!state.loaded && !opts.pulled) {
        $list.innerHTML = skeletons(3);
      }
      return window.ONE.api('/api/reservations/list?day=' + (TAB === 'tomorrow' ? 'tomorrow' : 'today'))
        .then((data) => {
          state.rows = data.reservations || [];
          state.statuses = data.statuses || {};
          const replay = !state.loaded || opts.replay;
          state.loaded = true;
          if (replay) {
            Array.from($list.children).forEach((el) => el.remove());
          }
          render(replay ? 'enter' : 'update');
        })
        .catch((e) => {
          if (!state.loaded) {
            $list.innerHTML = '';
            $count.textContent = '—';
          }
          showError('Nu s-au putut încărca rezervările: ' + e.message);
        });
    }
    loader = load;

    function find(id) { return state.rows.find((r) => r.id === id); }

    function onToggle(btn, id) {
      const field = btn.dataset.field;
      const before = !!st(id)[field];
      const after = !before;
      if (M) M.haptic();
      state.statuses[id] = Object.assign({}, st(id), { [field]: after });
      render();
      window.ONE.api('/api/reservations/status', { method: 'POST', body: { id, field, value: after } })
        .then((res) => {
          state.statuses[id] = Object.assign({}, st(id), res.status);
          render();
          let msg = field === 'city_tax_paid'
            ? (after ? 'Taxă marcată plătită' : 'Taxă marcată neplătită')
            : (after ? 'Check-in completat' : 'Check-in redeschis');
          if (res.guestAppSynced === false) msg += ' · Guest App nesincronizat';
          toast(msg);
        })
        .catch((e) => {
          state.statuses[id] = Object.assign({}, st(id), { [field]: before });
          render();
          toast('Salvarea a eșuat: ' + e.message);
        });
    }

    function onNuki(btn, r) {
      if (btn.disabled) return;
      const html = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span>Se trimite…';
      window.ONE.api('/api/reservations/nuki', { method: 'POST', body: { id: r.id }, timeout: 40000 })
        .then((res) => {
          // Digits roll out, the sent state rolls in (120 ms); the sent state stays — it is information.
          btn.innerHTML = icon('check') + `<span class="act-label">Nuki · ${esc(r.nukiCode)}</span>`;
          btn.classList.add('is-sent');
          if (M) {
            M.swapText(btn.querySelector('.act-label'), 'Trimis · ' + res.code);
            M.haptic();
          } else {
            btn.querySelector('.act-label').textContent = 'Trimis · ' + res.code;
          }
          toast(`${res.message} pentru ${r.name}`);
          setTimeout(() => { btn.disabled = false; }, 3000);
        })
        .catch((e) => {
          btn.disabled = false;
          btn.innerHTML = html;
          toast('Nuki: ' + e.message);
        });
    }

    $list.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-action]');
      if (!btn) return;
      const host = btn.closest('[data-id]');
      if (!host || host.classList.contains('is-leaving')) return;
      const id = host.dataset.id;
      const r = find(id);
      if (!r) return;
      switch (btn.dataset.action) {
        case 'toggle':
          if (CAN_EDIT) onToggle(btn, id);
          break;
        case 'copy-phone':
          copy(r.phone, 'Telefon copiat', () => {
            const label = btn.querySelector('[data-phone-text]');
            if (M && label) {
              M.swapText(label, 'Copiat', { original: r.phone, revertAfter: 900 });
              M.haptic();
            }
          });
          break;
        case 'welcome': {
          const ro = r.isRo;
          const msg = ro
            ? greeting(true) + '\nNe bucurăm ca ati ales Smart Stay pentru sejurul dumneavoastră in Bucuresti!' +
              '\nDacă aveți nevoie de parcare privată, verificati tabul Parking 🅿️\nAveți mai jos detaliile de check-in:'
            : greeting(false) + '\nThank you for choosing Smart Stay for your stay in Bucharest.' +
              '\nIf you need private parking please check the Parking tab 🅿️\nPlease find below the check-in instructions:';
          if (openWa(r.waPhone, msg)) toast('Mesaj Welcome deschis');
          break;
        }
        case 'guest-link':
          copy(r.guestLink, 'Link Guest App copiat');
          break;
        case 'nuki':
          if (CAN_EDIT) onNuki(btn, r);
          break;
      }
    });

    $chips && $chips.addEventListener('click', (event) => {
      const chip = event.target.closest('[data-filter]');
      if (!chip || chip.dataset.filter === state.filter) return;
      state.filter = chip.dataset.filter;
      render();
    });

    let timer;
    $search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => { state.search = $search.value; render(); }, 180);
    });

    initPullToRefresh($list);
    load();
  }

  // ════════════════════════════════════════════════════════════════════════
  // WhatsApp — review / rebooking outreach (check-out azi, -7, -14 zile)
  // ════════════════════════════════════════════════════════════════════════
  const GOOGLE_REVIEW = 'https://g.page/r/CSGPWbHZblKcEAE/review';
  const TRAVELMINIT_REVIEW = 'https://travelminit.ro/apartamente-smart-concept-living-bucuresti';
  const PLATFORMS = {
    booking_com: { label: 'Booking.com', link: null,
      ro: 'Te rugăm frumos să ne lași un review pozitiv pe Booking.com — verifică emailul primit de la ei! 🙏\n\n⭐⭐⭐⭐⭐\n\nFiecare recenzie contează enorm pentru noi.',
      en: 'We\'d love it if you could leave us a positive review on Booking.com — check the email they sent you! 🙏\n\n⭐⭐⭐⭐⭐\n\nEvery review means the world to us.' },
    airbnb: { label: 'Airbnb', link: null,
      ro: 'Te rugăm frumos să ne lași un review pozitiv pe Airbnb — din aplicație sau din emailul primit de la ei! 🙏\n\n⭐⭐⭐⭐⭐\n\nFiecare recenzie contează enorm pentru noi.',
      en: 'We\'d love it if you could leave us a positive review on Airbnb — from the app or the email they sent you! 🙏\n\n⭐⭐⭐⭐⭐\n\nEvery review means the world to us.' },
    expedia: { label: 'Expedia', link: null,
      ro: 'Te rugăm frumos să ne lași un review pozitiv pe Expedia — verifică emailul primit de la ei! 🙏\n\n⭐⭐⭐⭐⭐\n\nFiecare recenzie contează enorm pentru noi.',
      en: 'We\'d love it if you could leave us a positive review on Expedia — check the email from them! 🙏\n\n⭐⭐⭐⭐⭐\n\nEvery review means the world to us.' },
    travelminit: { label: 'TravelMinit', link: TRAVELMINIT_REVIEW },
    google: { label: 'Google', link: GOOGLE_REVIEW },
  };

  function reviewPart(platform, ro) {
    const p = PLATFORMS[platform] || PLATFORMS.google;
    return p.link ? `⭐⭐⭐⭐⭐\n\n${p.link}` : (ro ? p.ro : p.en);
  }
  function outreachMessage(type, platform, ro) {
    const rp = reviewPart(platform, ro);
    if (type === 'w1') {
      return ro
        ? `Bună ziua! 🏠\n\nSperăm că ți-a plăcut sejurul și că totul a fost pe placul tău!\n\nDacă ai avut o experiență frumoasă, te rugăm frumos să ne ajuți cu un review pozitiv — fiecare recenzie contează enorm și ne motivează să fim și mai buni! 🙏\n\n${rp}\n\nDrum bun!\n— Echipa SmartStay`
        : `Hello! 🏠\n\nWe hope you enjoyed your stay and that everything was to your liking!\n\nIf you had a great experience, we'd love it if you could leave us a positive review — every single review means the world to us and motivates us to keep improving! 🙏\n\n${rp}\n\nSafe travels!\n— SmartStay Team`;
    }
    if (type === 'w2') {
      return ro
        ? `Bună! 👋\n\nFiecare review contează enorm — ne ajută să fim descoperiți și să știm că munca noastră este apreciată.\n\nTe rugăm din suflet să ne lași câteva cuvinte: 🙏\n\n${rp}\n\nMulțumim!\n— Echipa SmartStay`
        : `Hi! 👋\n\nEvery review means the world to us — it helps other travelers find us and reminds us that our hard work is appreciated.\n\nWe'd be so grateful if you could leave us a few kind words: 🙏\n\n${rp}\n\nThank you!\n— SmartStay Team`;
    }
    return ro
      ? 'Bună! 😊\n\nÎți mulțumim că ai ales SmartStay! Sperăm să te revedem curând la noi.\n\nPentru viitoarea rezervare directă pe *smartstay.ro* ai:\n\n✅ Cel mai bun preț garantat\n✅ Early Check-in si Late Check-out\n✅ Zero comisioane de platformă\n✅ Parcare privată gratuită (5+ nopți)\n🎁 *10% REDUCERE* cu codul: *STAY10*\n\nwww.SmartStay.ro\n\n— Echipa SmartStay'
      : 'Hi! 😊\n\nThank you for choosing SmartStay! We hope to welcome you back to us soon.\n\nFor your next stay, book directly on *smartstay.ro* and enjoy:\n\n✅ Best price guaranteed\n✅ Early check-in & Late Check-out\n✅ Zero platform fees\n✅ Free private parking (5+ nights)\n🎁 *10% DISCOUNT* with code: *STAY10*\n\nwww.SmartStay.ro\n\n— SmartStay Team';
  }

  function initWhatsApp() {
    const state = { windows: { w1: [], w2: [], w3: [] }, sent: {}, active: 'w1', search: '', loaded: false };
    const $windows = $('[data-windows]');

    function isSent(id, type) { return state.sent[id + '_' + type]; }

    function card(r, type) {
      const p = PLATFORMS[r.platform] || PLATFORMS.google;
      const msg = outreachMessage(type, r.platform, r.isRo);
      const sent = isSent(r.id, type);
      const date = r.checkOut ? r.checkOut.split('-').reverse().slice(0, 2).join('.') : '';
      return `<article class="card wa-card${sent ? ' is-sent' : ''}" data-id="${esc(r.id)}">
        <div class="res-card__head">
          <div class="grow">
            <h3 class="res-card__name">${esc(r.name)}</h3>
            <div class="wa-meta">
              <span class="badge">Apt ${esc(r.apartment)}</span>
              <span class="badge badge-blue">${esc(p.label)}</span>
              <span class="badge ${r.isRo ? '' : 'badge-violet'}">${r.isRo ? 'RO' : 'EN'}</span>
              ${date ? `<span class="muted">${icon('calendar')} ${esc(date)}</span>` : ''}
            </div>
            ${r.phone ? `<div class="muted wa-phone">${esc(r.phone)}</div>` : ''}
            ${type !== 'w3' ? `<div class="wa-target">→ review pe <strong>${esc(p.link ? p.label : p.label + ' (via email)')}</strong></div>` : ''}
          </div>
        </div>
        <div class="res-actions res-actions-2">
          ${r.waPhone
            ? `<a class="act ${sent ? 'act-sent' : 'act-whatsapp'}" href="${esc(waUrl(r.waPhone, msg))}"${NATIVE_WA ? '' : ' target="_blank" rel="noopener"'}
                 data-action="send" data-platform="${esc(r.platform)}">${sent ? icon('check') + 'Trimis · ' + esc(sent.at) + (sent.by ? ' · ' + esc(sent.by.split(' ')[0]) : '') : icon('whatsapp') + 'Trimite'}</a>`
            : '<button type="button" class="act" disabled>Fără telefon</button>'}
          <button type="button" class="act act-guest" data-action="preview">Previzualizare</button>
        </div>
        <div class="wa-preview pre" hidden>${esc(msg)}</div>
      </article>`;
    }

    function render() {
      ['w1', 'w2', 'w3'].forEach((w) => {
        const el = root.querySelector(`[data-window-count="${w}"]`);
        if (el) el.textContent = state.loaded ? state.windows[w].length : '—';
      });
      if (windowPill) windowPill.sync();
      if (!state.loaded) { $list.innerHTML = skeletons(3); return; }
      const q = state.search.trim().toLowerCase();
      const rows = state.windows[state.active].filter((r) =>
        !q || [r.name, r.phone, r.apartment].join(' ').toLowerCase().includes(q));
      const remaining = rows.filter((r) => !isSent(r.id, state.active)).length;
      $count.textContent = remaining;
      $list.innerHTML = rows.length
        ? rows.map((r) => card(r, state.active)).join('')
        : empty('Nicio rezervare', 'Nu sunt oaspeți de contactat în această fereastră.');
    }

    function load() {
      lastLoad = Date.now();
      state.loaded = false;
      showError('');
      render();
      return window.ONE.api('/api/reservations/whatsapp', { timeout: 45000 })
        .then((data) => {
          state.windows = data.windows || { w1: [], w2: [], w3: [] };
          state.sent = data.sent || {};
          state.loaded = true;
          const errs = Object.values(data.errors || {});
          if (errs.length) showError('Unele ferestre nu s-au încărcat: ' + errs[0]);
          render();
        })
        .catch((e) => {
          state.loaded = true;
          render();
          showError('Nu pot încărca rezervările: ' + e.message);
        });
    }
    loader = load;
    initPullToRefresh($list);

    $list.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-action]');
      if (!btn) return;
      const cardEl = btn.closest('[data-id]');
      if (btn.dataset.action === 'preview') {
        const box = cardEl.querySelector('.wa-preview');
        box.hidden = !box.hidden;
        return;
      }
      if (btn.dataset.action === 'send') {
        const id = cardEl.dataset.id;
        const type = state.active;
        if (!CAN_EDIT || isSent(id, type)) return; // link still opens WhatsApp
        setTimeout(() => {
          window.ONE.api('/api/reservations/whatsapp', { method: 'POST', body: { id, type, platform: btn.dataset.platform, sent: true } })
            .then((res) => { Object.assign(state.sent, res.sent || {}); render(); })
            .catch((e) => toast('Nu pot salva „Trimis": ' + e.message));
        }, 400);
      }
    });

    const windowPill = M ? M.chipPill($windows) : null;
    $windows.addEventListener('click', (event) => {
      const chip = event.target.closest('[data-window]');
      if (!chip) return;
      state.active = chip.dataset.window;
      $windows.querySelectorAll('[data-window]').forEach((c) => c.classList.toggle('is-active', c === chip));
      if (windowPill) windowPill.sync();
      render();
    });

    let timer;
    $search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => { state.search = $search.value; render(); }, 150);
    });

    load();
  }

  // ════════════════════════════════════════════════════════════════════════
  // Generator link Guest App (ultimele 4 zile)
  // ════════════════════════════════════════════════════════════════════════
  function initLink() {
    const $select = $('[data-link-select]');
    const $details = $('[data-link-details]');
    const $kv = $('[data-link-kv]');
    const $result = $('[data-link-result]');
    const $url = $('[data-link-url]');
    let rows = [];
    let current = null;

    function format() {
      const checked = root.querySelector('input[name="link-format"]:checked');
      return checked ? checked.value : 'dynamic';
    }

    // Legacy format kept byte-for-byte (old links in guests' phones still parse this way).
    function legacyUrl(r) {
      const base = r.guestLink.split('?')[0];
      const params = new URLSearchParams({
        guestName: r.name,
        checkIn: `${r.checkInDate}T${(r.checkInTime || '').replace(':', '')}:00`,
        checkOut: `${r.checkOutDate}T${(r.checkOutTime || '').replace(':', '')}:00`,
        apartment: r.apartment,
        lang: r.isRo ? 'ro' : 'en',
        code: r.nukiCode || '000000',
      });
      return base + '?' + params.toString();
    }

    function build() {
      if (!current) { $details.hidden = true; $result.hidden = true; return; }
      const rows2 = [
        ['ID rezervare', current.id], ['Oaspete', current.name], ['Apartament', current.apartment],
        ['Check-in', `${current.checkInLabel} · ${current.checkInTime}`],
        ['Check-out', `${current.checkOutLabel} · ${current.checkOutTime}`],
        ['Telefon', current.phone || '—'], ['Cod acces', current.nukiCode || '—'],
      ];
      $kv.innerHTML = rows2.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('');
      $details.hidden = false;

      const url = format() === 'legacy' ? legacyUrl(current) : current.guestLink;
      $url.textContent = url;
      $result.querySelector('[data-link-open]').href = url;
      const wa = $result.querySelector('[data-link-wa]');
      if (current.waPhone) {
        wa.href = waUrl(current.waPhone, `Hello ${current.name}! Here is your SmartStay Guest App link:\n\n${url}`);
        wa.removeAttribute('aria-disabled');
        wa.classList.remove('is-disabled');
      } else {
        wa.removeAttribute('href');
        wa.setAttribute('aria-disabled', 'true');
        wa.classList.add('is-disabled');
      }
      $result.hidden = false;
    }

    $select.addEventListener('change', () => {
      current = rows.find((r) => r.id === $select.value) || null;
      build();
    });
    root.querySelectorAll('input[name="link-format"]').forEach((i) => i.addEventListener('change', build));
    $result.querySelector('[data-link-copy]').addEventListener('click', () => copy($url.textContent, 'Link copiat'));

    window.ONE.api('/api/reservations/recent', { timeout: 30000 })
      .then((data) => {
        rows = data.reservations || [];
        $count.textContent = String(rows.length);
        $select.innerHTML = '<option value="">Alege o rezervare…</option>' + rows.map((r) =>
          `<option value="${esc(r.id)}">${esc(r.name)} · Apt ${esc(r.apartment)} · ${esc(r.checkInLabel)}</option>`).join('');
        $select.disabled = false;
        if (!rows.length) showError('Nu sunt rezervări în ultimele 4 zile.');
      })
      .catch((e) => {
        $select.innerHTML = '<option value="">—</option>';
        showError('Nu s-au putut încărca rezervările: ' + e.message);
      });
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.ONE) return;
    if (TAB === 'whatsapp') initWhatsApp();
    else if (TAB === 'link') initLink();
    else initDay();
  });
})();
