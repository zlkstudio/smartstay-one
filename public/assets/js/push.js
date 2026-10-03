/* SmartStay ONE — notificări push pe acest dispozitiv (pagina Jurnal, doar admin).
   iPhone: merge doar din aplicația instalată pe ecranul principal (iOS 16.4+), după o atingere. */
(function () {
  'use strict';

  const card = document.querySelector('[data-push]');
  if (!card) return;

  const $status = card.querySelector('[data-push-status]');
  const $on = card.querySelector('[data-push-on]');
  const $off = card.querySelector('[data-push-off]');
  const $test = card.querySelector('[data-push-test]');
  const KEY = card.dataset.key;

  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  function show(text, buttons) {
    $status.textContent = text;
    $on.hidden = !buttons.includes('on');
    $off.hidden = !buttons.includes('off');
    $test.hidden = !buttons.includes('test');
  }

  function keyBytes(b64u) {
    const pad = '='.repeat((4 - (b64u.length % 4)) % 4);
    const raw = atob((b64u + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
  }

  async function registration() {
    return navigator.serviceWorker.register('/sw.js').then(() => navigator.serviceWorker.ready);
  }

  async function refresh() {
    if (card.dataset.configured !== '1') return show('Neconfigurate pe server — rulează php bin/push-keys.php.', []);
    if (isIOS && !standalone) return show('Pe iPhone, notificările merg doar din aplicația ONE instalată pe ecranul principal.', []);
    if (!supported) return show('Browserul acesta nu suportă notificări push.', []);
    if (Notification.permission === 'denied') return show('Blocate pentru ONE în setările telefonului. Permite-le acolo, apoi revino.', []);
    const reg = await registration();
    const sub = await reg.pushManager.getSubscription();
    const others = Number(card.dataset.devices) - (sub ? 1 : 0);
    if (sub) {
      return show('Active pe acest telefon' + (others > 0 ? ` · încă ${others} dispozitiv(e)` : '') + '.', ['test', 'off']);
    }
    show('Oprite pe acest telefon' + (others > 0 ? ` · active pe ${others} alt(e) dispozitiv(e)` : '') + '.', ['on']);
  }

  async function save(sub) {
    const json = sub.toJSON();
    const res = await window.ONE.api('/api/push/subscribe', { method: 'POST', body: { endpoint: json.endpoint, keys: json.keys } });
    card.dataset.devices = res.devices;
  }

  $on.addEventListener('click', async () => {
    $on.disabled = true;
    try {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') { window.ONE.toast('Notificările n-au fost permise.'); return refresh(); }
      const reg = await registration();
      let sub = await reg.pushManager.getSubscription();
      // A subscription made with another server key can't receive our pushes: start over.
      if (sub && sub.options && sub.options.applicationServerKey) {
        const current = new Uint8Array(sub.options.applicationServerKey);
        const wanted = keyBytes(KEY);
        if (current.length !== wanted.length || current.some((b, i) => b !== wanted[i])) { await sub.unsubscribe(); sub = null; }
      }
      sub = sub || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(KEY) });
      await save(sub);
      window.ONE.toast('Notificările sunt active pe acest telefon.');
    } catch (e) {
      window.ONE.toast('Nu s-au putut activa: ' + (e.message || e));
    } finally {
      $on.disabled = false;
      refresh();
    }
  });

  $off.addEventListener('click', async () => {
    $off.disabled = true;
    try {
      const reg = await registration();
      const sub = await reg.pushManager.getSubscription();
      if (sub) {
        const res = await window.ONE.api('/api/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint } });
        card.dataset.devices = res.devices;
        await sub.unsubscribe();
      }
      window.ONE.toast('Notificările au fost oprite pe acest telefon.');
    } catch (e) {
      window.ONE.toast(e.message || 'Eroare.');
    } finally {
      $off.disabled = false;
      refresh();
    }
  });

  $test.addEventListener('click', () => {
    $test.disabled = true;
    window.ONE.api('/api/push/test', { method: 'POST', body: {} })
      .then((res) => window.ONE.toast(res.message))
      .catch((e) => window.ONE.toast(e.message))
      .finally(() => { $test.disabled = false; });
  });

  document.addEventListener('DOMContentLoaded', () => { if (window.ONE) refresh().catch(() => show('Eroare la verificarea notificărilor.', [])); });
})();
