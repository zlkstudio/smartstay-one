<?php
/** @var string $pageTitle */
$installGate = $installGate ?? true;
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($pageTitle ?? 'SmartStay ONE') ?> · SmartStay ONE</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#020617" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="SmartStay ONE">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" type="image/png" sizes="32x32" href="<?= h(asset('assets/img/icon-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= h(asset('assets/img/apple-touch-icon.png')) ?>">
<link rel="preload" href="/assets/fonts/jost-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/jost-latin-600-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= h(asset('assets/css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('assets/css/app.css')) ?>">
<?php foreach (($styles ?? []) as $__style): ?>
<link rel="stylesheet" href="<?= h(asset($__style)) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= h(asset('assets/css/motion.css')) ?>">
<script nonce="<?= h(csp_nonce()) ?>">
(function () {
  var store = null;
  try { store = window.localStorage; } catch (e) {}
  // Theme before first paint: saved choice, else the phone's setting.
  var theme = store && store.getItem('one-theme');
  if (!theme) theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', theme);

  // Bottom-tab transition (set by app.js on tap). Runs before first paint: the shared pill resumes
  // from where the previous page left it, and <main> slides in the direction of travel.
  var html = document.documentElement, nav = null;
  html.classList.add('has-motion');
  try { nav = JSON.parse(window.sessionStorage.getItem('one-nav') || 'null'); window.sessionStorage.removeItem('one-nav'); } catch (e) {}
  var seg = function (p) { return '/' + String(p || '').split('/')[1]; };
  var fresh = !!(nav && Date.now() - nav.t < 8000 && seg(nav.path) === seg(location.pathname) && nav.to !== nav.from);
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (fresh && !reduce && typeof nav.dx === 'number' && Math.abs(nav.dx) < 600) {
    html.style.setProperty('--nav-ind-dx', nav.dx.toFixed(1) + 'px');
    html.setAttribute('data-nav-to', String(nav.to));
  }
  window.addEventListener('pagereveal', function (event) {
    if (!event.viewTransition) return;
    if (!fresh) { event.viewTransition.skipTransition(); return; }
    html.setAttribute('data-vt', reduce || nav.from < 0 ? 'fade' : (nav.to > nav.from ? 'right' : 'left'));
    event.viewTransition.finished.finally(function () { html.removeAttribute('data-vt'); });
  });
<?php if ($installGate): ?>
  // First visit in a browser tab → install page. Never inside the installed app.
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (store && !standalone && !store.getItem('one-install-done')) {
    location.replace('/install?next=' + encodeURIComponent(location.pathname + location.search));
  }
<?php endif; ?>
})();
</script>
<script src="<?= h(asset('assets/js/motion.js')) ?>" defer></script>
<script src="<?= h(asset('assets/js/app.js')) ?>" defer></script>
<?php foreach (($scripts ?? []) as $__script): ?>
<script src="<?= h(asset($__script)) ?>" defer></script>
<?php endforeach; ?>
