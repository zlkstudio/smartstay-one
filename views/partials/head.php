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
<script nonce="<?= h(csp_nonce()) ?>">
(function () {
  var store = null;
  try { store = window.localStorage; } catch (e) {}
  // Theme before first paint: saved choice, else the phone's setting.
  var theme = store && store.getItem('one-theme');
  if (!theme) theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', theme);
<?php if ($installGate): ?>
  // First visit in a browser tab → install page. Never inside the installed app.
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (store && !standalone && !store.getItem('one-install-done')) {
    location.replace('/install?next=' + encodeURIComponent(location.pathname + location.search));
  }
<?php endif; ?>
})();
</script>
<script src="<?= h(asset('assets/js/app.js')) ?>" defer></script>
