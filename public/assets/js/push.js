/* SmartStay ONE — notificări push (admin, manager, menajeră). Loaded on every page for those roles.
   1. On open: if notifications are already allowed, (re)subscribe this device silently and keep the server in sync.
   2. If they were never asked: a bottom prompt "Activează notificările" (a tap is required on iPhone).
   3. Contul meu / Jurnal: the [data-push] card — turn on / test / turn off on this device.
   iPhone: only inside the app installed on the home screen (iOS 16.4+). */
(function () {
  'use strict';

  const prompt = document.querySelector('[data-push-prompt]');
  const card = document.querySelector('[data-push]');
  if (!prompt) return;

  const KEY = prompt.dataset.key;
  const LATER_KEY = 'one-push-later';
  const SYNC_KEY = 'one-push-synced';
  const LATER_DAYS = 3;

  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const usable = supported && (!isIOS || standalone);

  function store(op, key, value) {
    try { return op === 'get' ? localStorage.getItem(key) : localStorage.setItem(key, value); } catch (e) { return null; }
  }

  function keyBytes(b64u) {
    const pad = '='.repeat((4 - (b64u.length % 4)) % 4);
    const raw = atob((b64u + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
  }

  function sameKey(sub) {
    const k = sub.options && sub.options.applicationServerKey;
    if (!k) return true;
    const a = new Uint8Array(k);
    const b = keyBytes(KEY);
    return a.length === b.length && a.every((x, i) => x === b[i]);
  }

  async function registration() {
    await navigator.serviceWorker.register('/sw.js');
    return navigator.serviceWorker.ready;
  }

  async function save(sub) {
    const json = sub.toJSON();
    const res = await window.ONE.api('/api/push/subscribe', { method: 'POST', body: { endpoint: json.endpoint, keys: json.keys } });
    store('set', SYNC_KEY, json.endpoint + '|' + new Date().toDateString());
    if (card) card.dataset.devices = res.devices;
    return res;
  }

  /** Subscribes this device (permission must already be granted) and tells the server. */
  async function subscribe(force) {
    const reg = await registration();
    let sub = await reg.pushManager.getSubscription();
    if (sub && !sameKey(sub)) { await sub.unsubscribe(); sub = null; } // keys rotated on the server
    const fresh = !sub;
    sub = sub || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(KEY) });
    // Once a day is enough to repair a subscription the server dropped (Apple/Google expire them).
    if (force || fresh || store('get', SYNC_KEY) !== sub.endpoint + '|' + new Date().toDateString()) await save(sub);
    return sub;
  }

  async function enable() {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      window.ONE.toast('Notificările n-au fost permise. Le poți activa oricând din Contul meu.');
      return false;
    }
    await subscribe(true);
    window.ONE.toast('Notificările sunt active pe acest telefon.');
    return true;
  }

  // ── Bottom prompt on app open ───────────────────────────────────────────
  function maybePrompt() {
    if (!usable || Notification.permission !== 'default' || card) return; // the card does the job on its own page
    const later = Number(store('get', LATER_KEY) || 0);
    if (later && Date.now() - later < LATER_DAYS * 86400000) return;
    prompt.hidden = false;
    requestAnimationFrame(() => prompt.classList.add('is-in'));
  }

  function hidePrompt() {
    prompt.classList.remove('is-in');
    setTimeout(() => { prompt.hidden = true; }, 220);
  }

  prompt.querySelector('[data-push-prompt-yes]').addEventListener('click', async (event) => {
    event.currentTarget.disabled = true;
    try { await enable(); } catch (e) { window.ONE.toast('Nu s-au putut activa: ' + (e.message || e)); }
    hidePrompt();
  });
  prompt.querySelector('[data-push-prompt-later]').addEventListener('click', () => {
    store('set', LATER_KEY, String(Date.now()));
    hidePrompt();
  });

  // ── Card (Contul meu, Jurnal) ───────────────────────────────────────────
  function initCard() {
    if (!card || card.dataset.configured !== '1') return;
    const $status = card.querySelector('[data-push-status]');
    const $on = card.querySelector('[data-push-on]');
    const $off = card.querySelector('[data-push-off]');
    const $test = card.querySelector('[data-push-test]');

    function show(text, buttons) {
      $status.textContent = text;
      $on.hidden = !buttons.includes('on');
      $off.hidden = !buttons.includes('off');
      $test.hidden = !buttons.includes('test');
    }

    async function refresh() {
      if (isIOS && !standalone) return show('Pe iPhone, notificările merg doar din aplicația ONE instalată pe ecranul principal.', []);
      if (!supported) return show('Browserul acesta nu suportă notificări push.', []);
      if (Notification.permission === 'denied') return show('Blocate pentru ONE în setările telefonului. Permite-le acolo, apoi revino.', []);
      const sub = await (await registration()).pushManager.getSubscription();
      const others = Number(card.dataset.devices) - (sub ? 1 : 0);
      if (sub) return show('Active pe acest telefon' + (others > 0 ? ` · încă ${others} dispozitiv(e)` : '') + '.', ['test', 'off']);
      show('Oprite pe acest telefon' + (others > 0 ? ` · active pe ${others} alt(e) dispozitiv(e)` : '') + '.', ['on']);
    }

    $on.addEventListener('click', async () => {
      $on.disabled = true;
      try { await enable(); } catch (e) { window.ONE.toast('Nu s-au putut activa: ' + (e.message || e)); }
      $on.disabled = false;
      refresh();
    });

    $off.addEventListener('click', async () => {
      $off.disabled = true;
      try {
        const sub = await (await registration()).pushManager.getSubscription();
        if (sub) {
          const res = await window.ONE.api('/api/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint } });
          card.dataset.devices = res.devices;
          await sub.unsubscribe();
        }
        store('set', SYNC_KEY, '');
        window.ONE.toast('Notificările au fost oprite pe acest telefon.');
      } catch (e) {
        window.ONE.toast(e.message || 'Eroare.');
      }
      $off.disabled = false;
      refresh();
    });

    $test.addEventListener('click', () => {
      $test.disabled = true;
      window.ONE.api('/api/push/test', { method: 'POST', body: {} })
        .then((res) => window.ONE.toast(res.message))
        .catch((e) => window.ONE.toast(e.message))
        .finally(() => { $test.disabled = false; });
    });

    refresh().catch(() => show('Eroare la verificarea notificărilor.', []));
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.ONE) return;
    // Already allowed (and not switched off on purpose from the card): keep this device subscribed.
    if (usable && Notification.permission === 'granted' && store('get', SYNC_KEY) !== '') {
      subscribe(false).catch(() => {});
    }
    initCard();
    setTimeout(maybePrompt, 1200); // let the page settle first
  });
})();
