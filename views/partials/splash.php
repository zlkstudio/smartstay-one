<?php
/**
 * Splash la deschiderea aplicației (o dată pe sesiune, doar în layout-ul cu navigare).
 * Vizibil doar când head.php pune html.splash-on. shell.js preîncarcă modulele și mișcă bara.
 */
$__logoLight = asset('assets/img/logo.png');
$__logoDark = asset('assets/img/logo-dark.png');
?>
<div class="splash" id="splash" aria-hidden="true">
  <div class="splash-aura"></div>
  <div class="splash-center">
    <div class="splash-logo" style="--logo-light:url('<?= h($__logoLight) ?>');--logo-dark:url('<?= h($__logoDark) ?>')">
      <?= logo('logo-img splash-logo-img') ?>
      <span class="splash-sheen"></span>
    </div>
    <div class="splash-loader">
      <div class="splash-track"><div class="splash-fill" data-splash-fill><span class="splash-shine"></span></div></div>
      <div class="splash-meta">
        <span class="splash-pct tabular" data-splash-pct>0%</span>
      </div>
    </div>
  </div>
</div>
