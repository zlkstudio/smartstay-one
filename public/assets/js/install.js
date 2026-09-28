/* SmartStay ONE — install page. Picks the right install path per device. */
(function () {
  'use strict';

  var root = document.getElementById('install');
  if (!root) return;

  var ua = navigator.userAgent || '';
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isAndroid = /Android/i.test(ua);
  var inApp = /FBAN|FBAV|Instagram|WhatsApp|Line\/|; wv\)|GSA\//i.test(ua);
  var iosNonSafari = isIOS && /CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var manual = /[?&]manual=1/.test(location.search);

  var next = root.getAttribute('data-next') || '/';
  if (next.charAt(0) !== '/' || next.charAt(1) === '/') next = '/';

  function show(name) {
    var blocks = root.querySelectorAll('[data-install]');
    for (var i = 0; i < blocks.length; i++) {
      blocks[i].hidden = blocks[i].getAttribute('data-install') !== name;
    }
  }

  function markDone() {
    try { localStorage.setItem('one-install-done', String(Date.now())); } catch (e) {}
  }

  function goNext() {
    markDone();
    location.replace(next);
  }

  root.querySelector('[data-install-continue]').addEventListener('click', goNext);

  if (standalone) {
    show('installed');
    if (!manual) goNext();
    return;
  }

  if (inApp) {
    var names = root.querySelectorAll('[data-browser-name]');
    for (var n = 0; n < names.length; n++) names[n].textContent = isIOS ? 'Safari' : 'Chrome';
    show('inapp');
    return;
  }

  // Native prompt (Chrome/Edge/Samsung on Android, Chrome on desktop).
  var nativeButton = root.querySelector('[data-install-button]');
  function useNative(promptEvent) {
    show('native');
    nativeButton.onclick = function () {
      promptEvent.prompt();
      promptEvent.userChoice.then(function (choice) {
        window.__onePrompt = null;
        if (choice.outcome === 'accepted') {
          markDone();
          show('done');
        }
      });
    };
  }

  window.addEventListener('appinstalled', function () {
    markDone();
    show('done');
  });

  if (window.__onePrompt) {
    useNative(window.__onePrompt);
  } else {
    document.addEventListener('one:installable', function () { useNative(window.__onePrompt); });
  }

  if (isIOS) {
    if (iosNonSafari) {
      // Chrome/Firefox on iOS 16.4+ also support "Add to Home Screen" from Share, but Safari is the reliable path.
      var steps = root.querySelector('[data-install="ios"] .card-title');
      steps.textContent = 'Pe iPhone (merge cel mai sigur din Safari)';
    }
    show('ios');
    return;
  }

  // Android without a native prompt within 2.5s → manual menu steps. Desktop → explain.
  show(isAndroid ? '' : 'desktop');
  setTimeout(function () {
    if (!window.__onePrompt && isAndroid) show('android');
  }, 2500);
})();
