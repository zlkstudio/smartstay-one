/* SmartStay ONE — shell: navigare fără reîncărcare, tranziții, bara de jos, splash cu preîncărcare.
   Paginile rămân randate pe server; aici doar le aducem (din cache-ul din memorie când se poate),
   înlocuim <main> și rulăm din nou scriptul paginii. Orice situație neprevăzută → navigare normală. */
(function () {
  'use strict';

  var ONE = window.ONE;
  if (!ONE || !window.fetch || !window.DOMParser || !history.pushState) return;

  var html = document.documentElement;
  function reduced() { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

  // ══════════════════════════════════════════════════════════════════════════
  // 1 · Ciclul de viață al scriptului de pagină
  // Scripturile modulelor (reservations.js, reports.js…) pornesc pe DOMContentLoaded și pun câțiva
  // listeneri pe document/window. Îi ținem minte ca să-i scoatem la plecarea de pe pagină, iar când
  // scriptul e rulat din nou după o schimbare de pagină, DOMContentLoaded îl pornim noi.
  // ══════════════════════════════════════════════════════════════════════════
  var domReady = false;
  var runningPage = false;
  var pageListeners = [];
  document.addEventListener('DOMContentLoaded', function () { domReady = true; });

  function isPageContext() {
    if (runningPage) return true;
    var s = document.currentScript;
    return !!(s && s.hasAttribute && s.hasAttribute('data-page-script'));
  }
  function runPageCallback(fn, event) {
    runningPage = true;
    try { fn.call(document, event); } finally { runningPage = false; }
  }
  [document, window].forEach(function (target) {
    var add = target.addEventListener;
    target.addEventListener = function (type, fn, opts) {
      if (!isPageContext() || typeof fn !== 'function') return add.call(this, type, fn, opts);
      if (type === 'DOMContentLoaded' && this === document) {
        if (domReady) {   // script rulat după o schimbare de pagină: pornește-l după ce se termină
          setTimeout(function () { runPageCallback(fn, new Event('DOMContentLoaded')); }, 0);
          return undefined;
        }
        var wrapped = function (event) { runPageCallback(fn, event); };
        pageListeners.push([this, type, wrapped, opts]);
        return add.call(this, type, wrapped, opts);
      }
      pageListeners.push([this, type, fn, opts]);
      return add.call(this, type, fn, opts);
    };
  });
  function leavePage() {
    pageListeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
    pageListeners = [];
    document.dispatchEvent(new Event('one:leave'));
  }

  // ══════════════════════════════════════════════════════════════════════════
  // 2 · Bara de jos: un singur indicator care alunecă între taburi
  // ══════════════════════════════════════════════════════════════════════════
  var nav = document.querySelector('.bottom-nav');
  var navItems = nav ? Array.prototype.slice.call(nav.querySelectorAll('.nav-item')) : [];
  var glider = null;

  function navIndexFor(pathname) {
    var best = -1, bestLen = -1;
    navItems.forEach(function (item, i) {
      var p = new URL(item.href, location.href).pathname;
      var match = p === '/' ? pathname === '/' : (pathname === p || pathname.indexOf(p + '/') === 0 ||
        pathname.split('/')[1] === p.split('/')[1]);
      if (match && p.length > bestLen) { best = i; bestLen = p.length; }
    });
    return best;
  }
  function currentNavIndex() {
    for (var i = 0; i < navItems.length; i++) if (navItems[i].classList.contains('is-active')) return i;
    return -1;
  }
  function placeGlider(index, instant) {
    if (!glider) return;
    var item = navItems[index];
    if (!item) { glider.classList.remove('is-placed'); return; }
    var pill = item.querySelector('.nav-pill');
    var navBox = nav.getBoundingClientRect();
    var box = pill.getBoundingClientRect();
    if (instant || reduced()) glider.classList.add('no-anim');
    glider.style.transform = 'translate3d(' + (box.left - navBox.left).toFixed(1) + 'px,' + (box.top - navBox.top).toFixed(1) + 'px,0)';
    glider.setAttribute('data-tone', item.getAttribute('data-module') || '');
    glider.classList.add('is-placed');
    if (instant || reduced()) { void glider.offsetWidth; glider.classList.remove('no-anim'); }
  }
  function setNavActive(index) {
    navItems.forEach(function (item, i) {
      item.classList.toggle('is-active', i === index);
      if (i === index) item.setAttribute('aria-current', 'page'); else item.removeAttribute('aria-current');
    });
    placeGlider(index, false);
  }
  if (nav) {
    glider = document.createElement('span');
    glider.className = 'nav-glider';
    glider.setAttribute('aria-hidden', 'true');
    nav.prepend(glider);
    nav.classList.add('has-glider');
    var placeNow = function () { placeGlider(currentNavIndex(), true); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', placeNow); else placeNow();
    window.addEventListener('resize', placeNow);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(placeNow);
  }

  // ══════════════════════════════════════════════════════════════════════════
  // 3 · Navigare fără reîncărcare
  // ══════════════════════════════════════════════════════════════════════════
  var SKIP = /^\/(api|logout|login|install|assets|reports\/body|home\/body|sw\.js|manifest)/;
  var navSeq = 0;
  var histN = 0;
  history.scrollRestoration = 'manual';

  function swappableUrl(url) {
    if (url.origin !== location.origin) return false;
    if (SKIP.test(url.pathname)) return false;
    return !!document.getElementById('main');
  }
  function linkTarget(event) {
    var a = event.target.closest && event.target.closest('a[href]');
    if (!a || a.target && a.target !== '_self' || a.hasAttribute('download') || a.hasAttribute('data-no-swap')) return null;
    if (a.closest('.main.is-old')) return null;
    var url;
    try { url = new URL(a.getAttribute('href'), location.href); } catch (e) { return null; }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
    if (!swappableUrl(url)) return null;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return null; // ancoră pe pagină
    return { a: a, url: url };
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var t = linkTarget(event);
    if (!t) return;
    event.preventDefault();
    if (t.url.pathname + t.url.search === location.pathname + location.search && !busy) {
      window.scrollTo({ top: 0, behavior: reduced() ? 'auto' : 'smooth' });
      return;
    }
    var kind;
    if (t.a.classList.contains('daynav-item') && lastDaynav !== null) {   // Astăzi / Mâine / WhatsApp / Link
      var to = Number(t.a.getAttribute('data-i'));
      kind = to > lastDaynav ? 'right' : to < lastDaynav ? 'left' : 'fade';
    }
    go(t.url, { link: t.a, kind: kind });
  });
  // Sub-taburile Rezervări își mută singure starea activă la click; reținem poziția dinainte.
  var lastDaynav = null;
  document.addEventListener('click', function (event) {
    var active = document.querySelector('#main .daynav-item.is-active');
    lastDaynav = active ? Number(active.getAttribute('data-i')) : null;
  }, true);

  // Atingerea unui link pornește descărcarea înainte de „click” (câștigă ~100 ms).
  document.addEventListener('pointerdown', function (event) {
    var t = linkTarget(event);
    if (t) ONE.fetchHtml(t.url.pathname + t.url.search).catch(function () {});
  }, { passive: true });

  var busy = false;

  function go(url, opts) {
    opts = opts || {};
    var seq = ++navSeq;
    busy = true;
    var fromPath = location.pathname, fromSearch = location.search;
    var toIndex = navIndexFor(url.pathname);
    var fromIndex = currentNavIndex();
    if (toIndex !== -1) setNavActive(toIndex);            // răspuns instant la tap
    var bar = progress.start();

    ONE.fetchHtml(url.pathname + url.search)
      .then(function (res) {
        if (seq !== navSeq) return null;
        var finalUrl = new URL(res.url, location.href);
        if (SKIP.test(finalUrl.pathname)) throw new Error('full');
        var doc = new DOMParser().parseFromString(res.html, 'text/html');
        if (!doc.getElementById('main') || !doc.querySelector('.topbar')) throw new Error('full');
        return ensureStyles(doc).then(function () {
          if (seq !== navSeq) return;
          var kind = opts.kind || transitionKind(fromPath, fromSearch, finalUrl, fromIndex, navIndexFor(finalUrl.pathname));
          swap(doc, finalUrl, kind, opts);
          bar.done();
          busy = false;
          if (res.cached) setTimeout(function () { ONE.fetchHtml(res.url, { force: true }).catch(function () {}); }, 1500);
        });
      })
      .catch(function () {
        if (seq !== navSeq) return;
        location.href = url.href;   // orice problemă: navigare normală
      });
  }

  function transitionKind(fromPath, fromSearch, to, fromIndex, toIndex) {
    if (fromIndex !== -1 && toIndex !== -1 && fromIndex !== toIndex) return toIndex > fromIndex ? 'right' : 'left';
    if (fromPath.split('/')[1] === to.pathname.split('/')[1]) {
      var a = fromPath.split('/').length, b = to.pathname.split('/').length;
      if (b > a) return 'right';
      if (b < a) return 'left';
      return 'fade';
    }
    return toIndex === -1 ? 'right' : 'fade';
  }

  function ensureStyles(doc) {
    var waits = [];
    doc.querySelectorAll('head link[rel="stylesheet"]').forEach(function (link) {
      var href = link.getAttribute('href');
      if (document.querySelector('head link[rel="stylesheet"][href="' + CSS.escape(href) + '"]')) return;
      var el = document.createElement('link');
      el.rel = 'stylesheet';
      el.href = href;
      waits.push(new Promise(function (resolve) {
        el.onload = el.onerror = resolve;
        setTimeout(resolve, 3000);
      }));
      document.head.appendChild(el);
    });
    return Promise.all(waits);
  }

  function swap(doc, finalUrl, kind, opts) {
    var oldMain = document.getElementById('main');
    var y = window.scrollY;
    leavePage();

    // Istoric: ținem minte scroll-ul paginii pe care o părăsim.
    if (opts.push !== false) {
      history.replaceState(Object.assign({}, history.state, { one: 1, n: histN, scroll: y }), '');
      histN += 1;
      history.pushState({ one: 1, n: histN, scroll: 0 }, '', finalUrl.pathname + finalUrl.search + finalUrl.hash);
    }

    // Header, titlu, tab activ.
    var oldTop = document.querySelector('.topbar');
    var newTop = doc.querySelector('.topbar');
    // Header-ul e identic pe majoritatea paginilor: îl înlocuim doar când diferă (altfel logo-ul ar clipi).
    if (oldTop && newTop && oldTop.outerHTML.replace(/\s+/g, ' ') !== newTop.outerHTML.replace(/\s+/g, ' ')) {
      oldTop.replaceWith(document.importNode(newTop, true));
    }
    document.title = doc.title;
    document.body.setAttribute('data-page', doc.body.getAttribute('data-page') || '');
    var activeNew = doc.querySelector('.bottom-nav .nav-item.is-active');
    var idx = -1;
    if (activeNew) {
      var mod = activeNew.getAttribute('data-module');
      navItems.forEach(function (item, i) { if (item.getAttribute('data-module') === mod) idx = i; });
    }
    setNavActive(idx);

    // Pagina veche rămâne fixată exact unde era; cea nouă intră înaintea ei în DOM (ca scripturile
    // să o găsească pe ea), dar desenată deasupra.
    var newMain = document.importNode(doc.getElementById('main'), true);
    var rect = oldMain.getBoundingClientRect();
    oldMain.removeAttribute('id');
    oldMain.setAttribute('aria-hidden', 'true');
    oldMain.inert = true;
    oldMain.classList.add('is-old');
    oldMain.style.top = rect.top + 'px';
    oldMain.style.left = rect.left + 'px';
    oldMain.style.width = rect.width + 'px';
    newMain.classList.add('is-new');
    oldMain.before(newMain);
    html.classList.add('is-swapping');
    window.scrollTo(0, opts.scroll || 0);

    animate(oldMain, newMain, kind).then(function () {
      oldMain.remove();
      newMain.classList.remove('is-new');
      if (!document.querySelector('.main.is-old')) html.classList.remove('is-swapping');
    });

    // Scripturile paginii pornesc după primul cadru al animației (animația rulează pe compositor).
    requestAnimationFrame(function () {
      setTimeout(function () {
        runPageScripts(doc);
        ONE.pageInit(newMain);
        document.dispatchEvent(new Event('one:page'));
      }, 0);
    });
  }

  function runPageScripts(doc) {
    document.querySelectorAll('script[data-page-script]').forEach(function (s) { s.remove(); });
    doc.querySelectorAll('head script[data-page-script][src]').forEach(function (src) {
      var s = document.createElement('script');
      s.src = src.getAttribute('src');
      s.async = false;
      s.setAttribute('data-page-script', '');
      document.body.appendChild(s);
    });
  }

  // Alunecare (aleasă de Romeo în playground): între module pagina nouă alunecă din direcția tabului
  // peste cea veche, care se retrage și se estompează; în același modul fade cu ridicare;
  // Înapoi inversează direcția. Doar transform/opacity.
  var EASE_IN = 'cubic-bezier(0.22, 1, 0.36, 1)';
  var EASE_OUT = 'cubic-bezier(0.4, 0, 0.6, 1)';
  function animate(oldMain, newMain, kind) {
    var a, b;
    if (reduced()) {
      a = oldMain.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 120, fill: 'forwards' });
      b = newMain.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 120 });
    } else if (kind === 'right' || kind === 'left') {
      var dir = kind === 'right' ? 1 : -1;
      a = oldMain.animate([
        { transform: 'translate3d(0,0,0) scale(1)', opacity: 1 },
        { transform: 'translate3d(' + (-dir * 22) + '%,0,0) scale(0.96)', opacity: 0 }
      ], { duration: 340, easing: EASE_OUT, fill: 'forwards' });
      b = newMain.animate([
        { transform: 'translate3d(' + (dir * 45) + '%,0,0)', opacity: 0 },
        { transform: 'translate3d(0,0,0)', opacity: 1 }
      ], { duration: 440, easing: EASE_IN });
    } else {
      a = oldMain.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: EASE_OUT, fill: 'forwards' });
      b = newMain.animate([
        { transform: 'translate3d(0,14px,0)', opacity: 0 },
        { transform: 'translate3d(0,0,0)', opacity: 1 }
      ], { duration: 320, easing: EASE_IN });
    }
    return Promise.all([a.finished, b.finished]).catch(function () {});
  }

  // Înapoi / înainte.
  history.replaceState(Object.assign({}, history.state, { one: 1, n: histN, scroll: window.scrollY }), '');
  window.addEventListener('popstate', function (event) {
    var st = event.state;
    if (!st || !st.one) return;                    // ex. o ancoră (#maine): browserul se descurcă
    var kind = st.n < histN ? 'left' : 'right';
    histN = st.n;
    go(new URL(location.href), { push: false, kind: kind, scroll: st.scroll || 0 });
  });

  // Bară subțire sub header, doar când pagina nu era deja în cache (după 120 ms).
  var progress = (function () {
    var el = null, anim = null, timer = null;
    function ensure() {
      if (el) return el;
      el = document.createElement('div');
      el.className = 'swap-bar';
      el.innerHTML = '<span></span>';
      document.body.appendChild(el);
      return el;
    }
    return {
      start: function () {
        clearTimeout(timer);
        var started = false;
        timer = setTimeout(function () {
          started = true;
          ensure().classList.add('is-on');
          if (anim) anim.cancel();
          anim = el.firstChild.animate([{ transform: 'translate3d(-100%,0,0)' }, { transform: 'translate3d(-12%,0,0)' }],
            { duration: 4000, easing: 'cubic-bezier(0.1, 0.8, 0.2, 1)', fill: 'forwards' });
        }, 120);
        return {
          done: function () {
            clearTimeout(timer);
            if (!started || !el) return;
            if (anim) anim.cancel();
            anim = el.firstChild.animate([{ transform: 'translate3d(-12%,0,0)' }, { transform: 'translate3d(0,0,0)' }],
              { duration: 180, easing: 'ease-out', fill: 'forwards' });
            anim.finished.then(function () { el.classList.remove('is-on'); }).catch(function () {});
          }
        };
      }
    };
  })();

  // ══════════════════════════════════════════════════════════════════════════
  // 4 · Splash: logo animat + preîncărcarea modulelor
  // ══════════════════════════════════════════════════════════════════════════
  // Datele din splash rămân doar în memorie (cache-ul din app.js); nimic nu se scrie pe telefon.
  var MODULE_DATA = {
    reservations: ['/api/reservations/list?day=today'],
    housekeeping: ['/api/housekeeping/checkouts'],
    inventory: ['/api/inventory/occupancy']
  };
  var MIN_SPLASH = 1700;   // cât să se vadă animația logo-ului
  var MAX_SPLASH = 9000;   // după asta intrăm oricum; preîncărcarea continuă în fundal

  function initSplash() {
    var splash = document.getElementById('splash');
    if (!splash || !html.classList.contains('splash-on')) return;
    var fill = splash.querySelector('[data-splash-fill]');
    var pctEl = splash.querySelector('[data-splash-pct]');
    var total = 1, done = 0, finished = false, shown = 0;
    var t0 = performance.now();

    function render() {
      var pct = Math.round((done / total) * 100);
      if (pct < shown) pct = shown;
      shown = pct;
      fill.style.transform = 'translate3d(' + (pct - 100 + (pct < 100 ? 4 : 0)) + '%,0,0)';
      pctEl.textContent = pct + '%';
    }
    function step() {
      done += 1;
      render();
      maybeFinish();
    }
    function addSteps(n) { total += n; render(); }
    function maybeFinish(force) {
      if (finished) return;
      if (!force && done < total) return;
      finished = true;
      done = total; render();
      var wait = Math.max(force ? 0 : 250, MIN_SPLASH - (performance.now() - t0));
      setTimeout(function () {
        splash.classList.add('is-done');
        setTimeout(function () {
          html.classList.remove('splash-on');
          splash.remove();
        }, 520);
      }, wait);
    }
    setTimeout(function () { maybeFinish(true); }, MAX_SPLASH);

    // Pagina curentă: gata când îi vin datele amânate (dacă are), altfel imediat.
    var needsReady = !!document.querySelector('[data-defer], [data-report-body][data-src]');
    if (needsReady) {
      addSteps(1);
      document.addEventListener('one:ready', function once() {
        document.removeEventListener('one:ready', once);
        step();
      });
    }
    // Modulele din bara de jos: pagina, CSS/JS-ul ei și datele lente, câte două deodată.
    var current = currentNavIndex();
    var jobs = navItems.filter(function (item, i) { return i !== current; }).map(function (item) {
      return function () {
        var href = new URL(item.href, location.href);
        return ONE.fetchHtml(href.pathname + href.search).then(function (res) {
          step();
          var doc = new DOMParser().parseFromString(res.html, 'text/html');
          var subtasks = [];
          doc.querySelectorAll('head link[rel="stylesheet"][href], head script[data-page-script][src]').forEach(function (n) {
            subtasks.push(fetch(n.getAttribute('href') || n.getAttribute('src'), { credentials: 'same-origin' }).catch(function () {}));
          });
          var deferred = doc.querySelector('[data-defer]');
          if (deferred) subtasks.push(ONE.fetchHtml(deferred.getAttribute('data-defer')).catch(function () {}));
          var body = doc.querySelector('[data-report-body][data-src]');
          if (body) subtasks.push(ONE.fetchHtml(body.getAttribute('data-src')).catch(function () {}));
          if (doc.querySelector('[data-home-today]')) subtasks.push(ONE.api('/api/reports/today', { timeout: 40000 }).catch(function () {}));
          (MODULE_DATA[item.getAttribute('data-module')] || []).forEach(function (path) {
            subtasks.push(ONE.api(path, { timeout: 40000 }).catch(function () {}));
          });
          addSteps(subtasks.length);
          return Promise.all(subtasks.map(function (p) { return p.then(function () { step(); }); }));
        }, function () { step(); });
      };
    });
    addSteps(jobs.length);
    step();   // pagina curentă e deja pe ecran
    var next = 0;
    function worker() {
      if (next >= jobs.length) return Promise.resolve();
      var job = jobs[next++];
      return job().then(worker, worker);
    }
    worker(); worker();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSplash); else initSplash();
})();
