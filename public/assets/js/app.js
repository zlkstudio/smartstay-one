/* SmartStay ONE — shell behaviour shared by every page. No framework. */
(function () {
  'use strict';

  var store = null;
  try { store = window.localStorage; } catch (e) {}

  // Capture the install prompt as early as possible; install.js picks it up.
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    window.__onePrompt = event;
    document.dispatchEvent(new CustomEvent('one:installable'));
  });

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function () {});
    });
  }

  // ── Toasts ──────────────────────────────────────────────────────────────
  function toast(message, ms) {
    var box = document.getElementById('toasts');
    if (!box) return;
    var el = document.createElement('div');
    el.className = 'toast';
    el.textContent = message;
    box.appendChild(el);
    setTimeout(function () {
      el.classList.add('is-leaving');
      setTimeout(function () { el.remove(); }, 220);
    }, ms || 2600);
  }

  // ── JSON API helper for Stage 2 modules (CSRF, timeout, no cache) ──────
  var csrf = null;
  function api(path, options) {
    options = options || {};
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, options.timeout || 15000);
    var headers = { 'Accept': 'application/json' };
    var method = (options.method || 'GET').toUpperCase();
    var ready = Promise.resolve();

    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      ready = csrf ? Promise.resolve() : fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) { csrf = d.csrf || null; });
    }

    return ready.then(function () {
      if (csrf) headers['X-CSRF-Token'] = csrf;
      return fetch(path, {
        method: method,
        headers: headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal
      });
    }).then(function (response) {
      clearTimeout(timer);
      if (response.status === 401) {
        location.href = '/login?expired=1&next=' + encodeURIComponent(location.pathname);
        throw new Error('Sesiune expirată');
      }
      return response.json().then(function (data) {
        if (!response.ok || data.ok === false) throw new Error(data.error || 'Eroare ' + response.status);
        return data;
      });
    }, function (error) {
      clearTimeout(timer);
      throw error.name === 'AbortError' ? new Error('Serverul nu răspunde. Verifică conexiunea.') : error;
    });
  }

  window.ONE = { toast: toast, api: api };

  // ── Theme ───────────────────────────────────────────────────────────────
  function syncThemeColor() {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var metas = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < metas.length; i++) {
      metas[i].setAttribute('content', dark ? '#020617' : '#f8fafc');
      metas[i].removeAttribute('media');
    }
  }

  document.addEventListener('click', function (event) {
    var themeBtn = event.target.closest('[data-theme-toggle]');
    if (themeBtn) {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      if (store) store.setItem('one-theme', next);
      syncThemeColor();
      return;
    }

    var pwBtn = event.target.closest('[data-toggle-password]');
    if (pwBtn) {
      var input = document.getElementById(pwBtn.getAttribute('data-toggle-password'));
      var reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      pwBtn.querySelector('.pw-show').hidden = reveal;
      pwBtn.querySelector('.pw-hide').hidden = !reveal;
      pwBtn.setAttribute('aria-label', reveal ? 'Ascunde parola' : 'Arată parola');
      return;
    }

    var copyBtn = event.target.closest('[data-copy]');
    if (copyBtn) {
      var text = copyBtn.getAttribute('data-copy');
      var done = function () { toast('Copiat'); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
      } else {
        fallbackCopy(text);
        done();
      }
    }
  });

  function fallbackCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); } catch (e) {}
    area.remove();
  }

  // ── Forms: one submit only, spinner on the button ───────────────────────
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.hasAttribute('data-submit-once')) return;
    if (form.dataset.submitting) { event.preventDefault(); return; }
    form.dataset.submitting = '1';
    var button = form.querySelector('button[type="submit"]');
    if (button) button.classList.add('is-loading');
  });

  // Confirmation for destructive buttons: <button data-confirm="…">
  document.addEventListener('submit', function (event) {
    var submitter = event.submitter;
    var message = (submitter && submitter.getAttribute('data-confirm')) || event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      delete event.target.dataset.submitting;
    }
  }, true);

  // Back-forward cache: re-enable forms when returning to a page.
  window.addEventListener('pageshow', function () {
    var forms = document.querySelectorAll('form[data-submitting]');
    for (var i = 0; i < forms.length; i++) {
      delete forms[i].dataset.submitting;
      var b = forms[i].querySelector('.is-loading');
      if (b) b.classList.remove('is-loading');
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    syncThemeColor();

    var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    if (standalone) {
      var hide = document.querySelectorAll('[data-hide-standalone]');
      for (var i = 0; i < hide.length; i++) hide[i].hidden = true;
    }

    initUserForm();
    initUserList();
  });

  // ── Users: form shows maid / permission fields per role ────────────────
  function initUserForm() {
    var form = document.querySelector('[data-user-form]');
    if (!form) return;
    var roleInputs = form.querySelectorAll('input[name="role"]');
    function sync() {
      var checked = form.querySelector('input[name="role"]:checked');
      var role = checked ? checked.value : '';
      var groups = form.querySelectorAll('[data-for-role]');
      for (var i = 0; i < groups.length; i++) {
        groups[i].hidden = groups[i].getAttribute('data-for-role') !== role;
      }
    }
    for (var i = 0; i < roleInputs.length; i++) roleInputs[i].addEventListener('change', sync);
    sync();
  }

  // ── Users: live search + role chips ────────────────────────────────────
  function initUserList() {
    var list = document.querySelector('[data-user-list]');
    if (!list) return;
    var search = document.querySelector('[data-user-search]');
    var chips = document.querySelectorAll('[data-role-filter]');
    var empty = document.querySelector('[data-user-empty]');
    var role = 'all';

    function apply() {
      var q = (search.value || '').toLowerCase().trim();
      var items = list.querySelectorAll('[data-user]');
      var shown = 0;
      for (var i = 0; i < items.length; i++) {
        var matchRole = role === 'all' || items[i].getAttribute('data-role') === role;
        var matchText = !q || items[i].getAttribute('data-search').indexOf(q) !== -1;
        items[i].hidden = !(matchRole && matchText);
        if (!items[i].hidden) shown++;
      }
      if (empty) empty.hidden = shown > 0;
    }

    search.addEventListener('input', apply);
    for (var i = 0; i < chips.length; i++) {
      chips[i].addEventListener('click', function () {
        role = this.getAttribute('data-role-filter');
        for (var j = 0; j < chips.length; j++) chips[j].classList.toggle('is-active', chips[j] === this);
        apply();
      });
    }
  }
})();
