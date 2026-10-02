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
  function copy(text, label) {
    const done = () => toast(label || 'Copiat');
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, () => window.prompt('Copiază:', text));
    } else {
      window.prompt('Copiază:', text);
    }
  }
  function waUrl(phone, message) {
    return 'https://api.whatsapp.com/send?phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(message);
  }
  function openWa(phone, message) {
    if (!phone) { toast('Telefon lipsă pentru WhatsApp'); return false; }
    window.open(waUrl(phone, message), '_blank', 'noopener');
    return true;
  }
  function greeting(isRo) {
    const h = new Date().getHours();
    if (h >= 5 && h < 12) return isRo ? 'Bună dimineața' : 'Good morning';
    if (h >= 12 && h < 18) return isRo ? 'Bună ziua' : 'Good afternoon';
    return isRo ? 'Bună seara' : 'Good evening';
  }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  // Refresh: header button + coming back to the app after more than a minute.
  let loader = null;
  let lastLoad = 0;
  const refreshBtn = root.querySelector('[data-refresh]');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
      refreshBtn.classList.add('is-spinning');
      setTimeout(() => refreshBtn.classList.remove('is-spinning'), 700);
      loader && loader();
    });
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && loader && Date.now() - lastLoad > 60000) loader();
  });

  // ════════════════════════════════════════════════════════════════════════
  // Astăzi / Mâine
  // ════════════════════════════════════════════════════════════════════════
  function initDay() {
    const state = { rows: [], statuses: {}, filter: 'all', search: '' };
    const $chips = $('[data-chips]');

    const FILTERS = [
      ['all', 'Toate'],
      ['pending-checkin', 'Check-in lipsă'],
      ['tax-unpaid', 'Taxă neplătită'],
      ['complete', 'Complete'],
      ['with-parking', 'Cu parcare'],
    ];

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

    function toggle(field, on, label, hintOn, hintOff, ic) {
      return `<button type="button" class="toggle-card${on ? ' is-on' : ''}" data-action="toggle" data-field="${field}"
        aria-pressed="${on}" ${CAN_EDIT ? '' : 'disabled'}>
        <span class="toggle-card__icon">${icon(ic)}</span>
        <span class="toggle-card__text"><span class="toggle-card__label">${label}</span>
        <span class="toggle-card__hint">${on ? hintOn : hintOff}</span></span>
        <span class="switch" aria-hidden="true"></span></button>`;
    }

    function card(r) {
      const s = st(r.id);
      const nukiLabel = r.hasNuki && r.nukiCode ? `Nuki · ${esc(r.nukiCode)}` : 'Fără Nuki';
      return `<article class="card res-card ${cardClass(s)}" data-id="${esc(r.id)}">
        <div class="res-card__head">
          <div class="grow">
            <h3 class="res-card__name">${esc(r.name)}</h3>
            ${r.phone ? `<button type="button" class="res-card__phone" data-action="copy-phone">${icon('phone')}<span>${esc(r.phone)}</span></button>` : ''}
          </div>
          <div class="apt-badge"><span>Apt</span><strong>${esc(r.apartment || '—')}</strong></div>
        </div>
        <div class="res-card__dates">
          <div><span class="eyebrow">Check-in</span><strong>${esc(r.checkInLabel)}</strong><span class="muted">la ${esc(r.checkInTime)}</span></div>
          <div><span class="eyebrow">Check-out</span><strong>${esc(r.checkOutLabel)}</strong><span class="muted">la ${esc(r.checkOutTime)}</span></div>
        </div>
        ${r.parkingSpot ? `<div class="strip strip-blue">${icon('parking')}<span>Parcare inclusă · <strong>${esc(r.parkingSpot)}</strong></span></div>` : ''}
        ${r.guestCount > 2 ? `<div class="strip strip-warning">${icon('alert')}<span>Atenție: sunt <strong>${r.guestCount}</strong> oaspeți</span></div>` : ''}
        ${r.note ? `<div class="strip strip-neutral">${icon('note')}<span class="pre">${esc(r.note)}</span></div>` : ''}
        <div class="toggle-row">
          ${toggle('city_tax_paid', !!s.city_tax_paid, 'Taxă oraș', 'Plătită', 'Neplătită', 'receipt')}
          ${toggle('checkin_completed', !!s.checkin_completed, 'Check-in form', 'Completat', 'În așteptare', 'check')}
        </div>
        <div class="res-actions">
          <button type="button" class="act act-whatsapp" data-action="welcome" ${r.waPhone ? '' : 'disabled'}>${icon('whatsapp')}WhatsApp</button>
          <button type="button" class="act act-nuki" data-action="nuki" ${CAN_EDIT && r.hasNuki && r.nukiCode ? '' : 'disabled'}
            ${r.hasNuki ? '' : 'title="Apartamentul nu are yală Nuki configurată"'}>${icon('key')}${nukiLabel}</button>
          <button type="button" class="act act-guest" data-action="guest-link">${icon('link')}Guest App</button>
          <button type="button" class="act act-checkin" data-action="checkin-form" ${r.waPhone ? '' : 'disabled'}>${icon('file')}Check-in</button>
        </div>
      </article>`;
    }

    function renderChips() {
      const c = counts();
      $chips.innerHTML = FILTERS.map(([key, label]) =>
        `<button type="button" class="chip${state.filter === key ? ' is-active' : ''}" data-filter="${key}">${label} <span class="count">${c[key] || 0}</span></button>`).join('');
    }

    function render() {
      const shown = state.rows.filter(matches);
      $list.innerHTML = shown.length
        ? shown.map(card).join('')
        : empty('Nimic de afișat', state.rows.length
          ? 'Nu sunt rezervări care să corespundă filtrelor.'
          : (TAB === 'tomorrow' ? 'Nu sunt check-in-uri programate pentru mâine.' : 'Nu sunt check-in-uri programate azi.'));
      renderChips();
      const total = state.rows.length;
      $count.textContent = shown.length === total ? plural(total, 'rezervare', 'rezervări') : `${shown.length} / ${total}`;
    }

    function load() {
      lastLoad = Date.now();
      showError('');
      $list.innerHTML = skeletons(3);
      return window.ONE.api('/api/reservations/list?day=' + (TAB === 'tomorrow' ? 'tomorrow' : 'today'))
        .then((data) => {
          state.rows = data.reservations || [];
          state.statuses = data.statuses || {};
          render();
        })
        .catch((e) => {
          $list.innerHTML = '';
          $count.textContent = '—';
          showError('Nu s-au putut încărca rezervările: ' + e.message);
        });
    }
    loader = load;

    function find(id) { return state.rows.find((r) => r.id === id); }

    function onToggle(btn, id) {
      const field = btn.dataset.field;
      const before = !!st(id)[field];
      const after = !before;
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
          btn.innerHTML = icon('check') + 'Trimis · ' + esc(res.code);
          btn.classList.add('is-sent');
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
      const id = btn.closest('[data-id]').dataset.id;
      const r = find(id);
      if (!r) return;
      switch (btn.dataset.action) {
        case 'toggle':
          if (CAN_EDIT) onToggle(btn, id);
          break;
        case 'copy-phone':
          copy(r.phone, 'Telefon copiat');
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
        case 'checkin-form': {
          const msg = r.isRo
            ? 'Vă rugăm să completați formularul de check-in:\n\nhttps://smartstay.ro/check-in/\n\nDupă completarea formularului, codul de intrare devine *Activ*.\n\nAcesta trebuie completat cât mai curând posibil.'
            : 'Please complete the check-in form:\n\nhttps://smartstay.ro/check-in-en/\n\nAfter completing the form, the entry code becomes *Active*\n\nThe form should be completed as soon as possible';
          if (openWa(r.waPhone, msg)) toast('Mesaj Check-in deschis');
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

    $chips.addEventListener('click', (event) => {
      const chip = event.target.closest('[data-filter]');
      if (!chip) return;
      state.filter = chip.dataset.filter;
      render();
    });

    let timer;
    $search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => { state.search = $search.value; render(); }, 180);
    });

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
            ? `<a class="act ${sent ? 'act-sent' : 'act-whatsapp'}" href="${esc(waUrl(r.waPhone, msg))}" target="_blank" rel="noopener"
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

    $windows.addEventListener('click', (event) => {
      const chip = event.target.closest('[data-window]');
      if (!chip) return;
      state.active = chip.dataset.window;
      $windows.querySelectorAll('[data-window]').forEach((c) => c.classList.toggle('is-active', c === chip));
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
        $count.textContent = plural(rows.length, 'rezervare', 'rezervări');
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
