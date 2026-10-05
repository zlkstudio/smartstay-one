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

  // ── Cache în memorie (doar pe durata sesiunii, nimic pe disc) ─────────────
  // Datele cu nume / telefoane de oaspeți nu se scriu în localStorage: rămân doar în memoria
  // aplicației deschise. Orice modificare (POST) golește datele și fragmentele din cache.
  var API_TTL = [
    [/^\/api\/reservations\/list/, 120000],
    [/^\/api\/reports\/today/, 300000],
    [/^\/api\/housekeeping\/(checkouts|active-guests)/, 120000],
    [/^\/api\/inventory\/occupancy/, 300000]
  ];
  var HTML_TTL = [
    [/^\/reports\/body/, 300000],
    [/^\/home\/body/, 90000]
  ];
  var PAGE_TTL = 600000;      // pagini fără date (shell + JS): 10 min, reîmprospătate în fundal
  var PAGE_TTL_DYNAMIC = 15000; // pagini cu date pe server (ex. Inventar): doar cât să ajute prefetch-ul
  var apiCache = new Map();   // path → { text, t }
  var apiPending = new Map(); // path → Promise<text>
  var htmlCache = new Map();  // url → { html, url, t, ttl }
  var htmlPending = new Map();
  var bypassUntil = 0;

  function ttlFrom(list, path) {
    for (var i = 0; i < list.length; i++) if (list[i][0].test(path)) return list[i][1];
    return 0;
  }
  function isFresh(entry, ttl) {
    return !!entry && Date.now() - entry.t < ttl && Date.now() > bypassUntil;
  }
  function invalidate() {
    apiCache.clear();
    htmlCache.forEach(function (entry, key) { if (entry.ttl !== PAGE_TTL) htmlCache.delete(key); });
  }
  // Butoanele de reîmprospătare cer mereu date noi.
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-refresh], [data-report-refresh], [data-body-retry]')) bypassUntil = Date.now() + 2500;
  }, true);

  // ── JSON API helper (CSRF, timeout, cache pentru citiri) ────────────────
  var csrf = null;
  function api(path, options) {
    options = options || {};
    var method = (options.method || 'GET').toUpperCase();
    var ttl = method === 'GET' ? ttlFrom(API_TTL, path.split('?')[0]) : 0;
    if (method !== 'GET') invalidate();

    if (ttl) {
      var hit = apiCache.get(path);
      if (isFresh(hit, ttl)) return Promise.resolve(JSON.parse(hit.text));
      if (apiPending.has(path) && Date.now() > bypassUntil) {
        return apiPending.get(path).then(function (text) { return JSON.parse(text); });
      }
    }

    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, options.timeout || 15000);
    var headers = { 'Accept': 'application/json' };
    var ready = Promise.resolve();

    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      ready = csrf ? Promise.resolve() : fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) { csrf = d.csrf || null; });
    }

    var request = ready.then(function () {
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
      return response.text().then(function (text) {
        var data;
        try { data = JSON.parse(text); } catch (e) { throw new Error('Eroare ' + response.status); }
        if (!response.ok || data.ok === false) throw new Error(data.error || 'Eroare ' + response.status);
        if (ttl) apiCache.set(path, { text: text, t: Date.now() });
        return text;
      });
    }, function (error) {
      clearTimeout(timer);
      throw error.name === 'AbortError' ? new Error('Serverul nu răspunde. Verifică conexiunea.') : error;
    });

    if (ttl) {
      apiPending.set(path, request);
      var done = function () { if (apiPending.get(path) === request) apiPending.delete(path); };
      request.then(done, done);
    }
    return request.then(function (text) { return JSON.parse(text); });
  }

  // HTML (pagini întregi și fragmente amânate) cu același cache. Rezolvă { html, url } — url-ul
  // final, după redirect (ex. /login când a expirat sesiunea).
  function fetchHtml(url, opts) {
    opts = opts || {};
    var key = new URL(url, location.href).pathname + new URL(url, location.href).search;
    var hit = htmlCache.get(key);
    if (!opts.force && hit && isFresh(hit, hit.ttl)) return Promise.resolve({ html: hit.html, url: hit.url, cached: true });
    if (!opts.force && htmlPending.has(key) && Date.now() > bypassUntil) return htmlPending.get(key);

    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, opts.timeout || 60000);
    var request = fetch(key, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'text/html' }, signal: controller.signal })
      .then(function (r) {
        clearTimeout(timer);
        return r.text().then(function (html) {
          if (!r.ok) { var err = new Error('Eroare ' + r.status); err.status = r.status; throw err; }
          var finalUrl = new URL(r.url);
          var path = finalUrl.pathname;
          var ttl = ttlFrom(HTML_TTL, path) ||
            (html.indexOf('data-cache="1"') !== -1 ? PAGE_TTL : PAGE_TTL_DYNAMIC);
          var result = { html: html, url: finalUrl.pathname + finalUrl.search, cached: false };
          if (finalUrl.pathname + finalUrl.search === key) htmlCache.set(key, { html: html, url: result.url, t: Date.now(), ttl: ttl });
          return result;
        });
      }, function (error) {
        clearTimeout(timer);
        throw error.name === 'AbortError' ? new Error('Serverul răspunde greu. Încearcă din nou.') : error;
      });
    htmlPending.set(key, request);
    var done = function () { if (htmlPending.get(key) === request) htmlPending.delete(key); };
    request.then(done, done);
    return request;
  }

  window.ONE = { toast: toast, api: api, fetchHtml: fetchHtml, invalidate: invalidate, pageInit: pageInit };

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
    pageInit(document);
  });

  // Rulează la prima încărcare și după fiecare schimbare de pagină fără reîncărcare (shell.js).
  function pageInit(root) {
    root = root || document;
    var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    if (standalone) {
      var hide = document.querySelectorAll('[data-hide-standalone]');
      for (var i = 0; i < hide.length; i++) hide[i].hidden = true;
    }
    initUserForm();
    initUserList();
    initDeferred(root);
  }

  // ── Deferred blocks: the page is sent at once with a skeleton; the slow part
  // (Previo, calcule) comes as an HTML fragment and replaces it: <div data-defer="/home/body">.
  function initDeferred(root) {
    var blocks = (root || document).querySelectorAll('[data-defer]');
    for (var i = 0; i < blocks.length; i++) loadDeferred(blocks[i]);
  }

  function loadDeferred(block) {
    var url = block.getAttribute('data-defer');
    var path = url.split('?')[0];
    fetchHtml(url)
      .then(function (res) {
        if (res.url.split('?')[0] !== path) {   // session expired → login page
          location.href = '/login?expired=1&next=' + encodeURIComponent(location.pathname + location.search);
          throw new Error('redirect');
        }
        return res.html;
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var next = doc.body.firstElementChild;
        if (!next) throw new Error('Răspuns gol de la server.');
        block.replaceWith(document.importNode(next, true));
        document.dispatchEvent(new Event('one:ready'));
      })
      .catch(function (e) {
        if (e.message === 'redirect') return;
        var status = block.querySelector('[data-defer-status]');
        if (status) status.textContent = 'Datele nu s-au putut încărca.';
        var box = document.createElement('div');
        box.className = 'stack-sm';
        box.innerHTML = '<div class="alert alert-error" role="alert"><span></span></div>' +
          '<button type="button" class="btn btn-secondary">Încearcă din nou</button>';
        box.querySelector('span').textContent = e.message;
        box.querySelector('button').setAttribute('data-body-retry', '');
        box.querySelector('button').addEventListener('click', function () { location.reload(); });
        var hero = block.querySelector('.hero');
        if (hero) hero.after(box); else block.prepend(box);
        Array.prototype.forEach.call(block.querySelectorAll('.skeleton'), function (s) { s.style.animation = 'none'; });
        block.removeAttribute('aria-busy');
        document.dispatchEvent(new Event('one:ready'));
      });
  }

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
