<?php
/** First stop in a browser tab. Explains ONE and walks through installing it as an app. */
$next = (string) ($_GET['next'] ?? '/');
if (!str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\') || str_starts_with($next, '/install')) {
    $next = '/';
}
?>
<div class="auth">
  <div class="auth-card stack" id="install" data-next="<?= h($next) ?>">
    <div class="auth-head" style="margin-bottom:8px">
      <img class="install-icon" src="<?= h(asset('assets/img/icon-192.png')) ?>" width="96" height="96" alt="">
      <h1>Instalează SmartStay ONE</h1>
      <p>Pune aplicația pe ecranul principal — se deschide ca o aplicație, fără bară de browser, și rămâi conectat.</p>
    </div>

    <ul class="feature-list card">
      <li><span class="tile-icon"><?= icon('reservations') ?></span>Rezervările zilei, taxe și coduri Nuki</li>
      <li><span class="tile-icon" data-module="housekeeping"><?= icon('housekeeping') ?></span>Curățenie și checklist cu poze</li>
      <li><span class="tile-icon" data-module="inventory"><?= icon('inventory') ?></span>Inventar lenjerii și prosoape</li>
    </ul>

    <!-- Already running as an installed app -->
    <div class="card stack-sm" data-install="installed" hidden>
      <div class="alert alert-success"><?= icon('check', 'icon icon-sm') ?><span>Aplicația e deja instalată pe acest dispozitiv.</span></div>
    </div>

    <!-- Android / Chrome / Edge: native prompt -->
    <div data-install="native" hidden>
      <button type="button" class="btn btn-primary btn-block" data-install-button>
        <?= icon('download') ?><span>Instalează aplicația</span>
      </button>
    </div>

    <!-- Just installed -->
    <div class="card stack-sm" data-install="done" hidden>
      <div class="alert alert-success"><?= icon('check', 'icon icon-sm') ?><span>Gata! Deschide <strong>SmartStay ONE</strong> de pe ecranul principal.</span></div>
    </div>

    <!-- iPhone / iPad in Safari -->
    <div class="card stack" data-install="ios" hidden>
      <div class="card-title">Pe iPhone, în 3 pași</div>
      <ol class="steps">
        <li><span>Apasă <span class="kbd-icon"><?= icon('share-ios', 'icon icon-sm') ?></span> <strong>Distribuie</strong> în bara de jos a Safari</span></li>
        <li><span>Derulează și alege <span class="kbd-icon"><?= icon('plus-square', 'icon icon-sm') ?></span> <strong>Adaugă pe ecranul principal</strong></span></li>
        <li><span>Apasă <strong>Adaugă</strong>, apoi deschide aplicația de pe ecran</span></li>
      </ol>
    </div>

    <!-- Android without the native prompt (older Chrome, Samsung Internet, Firefox) -->
    <div class="card stack" data-install="android" hidden>
      <div class="card-title">Pe Android</div>
      <ol class="steps">
        <li><span>Apasă meniul <strong>⋮</strong> din colțul browserului</span></li>
        <li><span>Alege <strong>Instalează aplicația</strong> sau <strong>Adaugă pe ecranul de pornire</strong></span></li>
        <li><span>Confirmă, apoi deschide aplicația de pe ecran</span></li>
      </ol>
    </div>

    <!-- Inside WhatsApp / Facebook / Instagram: installing is impossible there -->
    <div class="card stack" data-install="inapp" hidden>
      <div class="alert alert-warning"><?= icon('alert', 'icon icon-sm') ?><span>Ai deschis linkul din altă aplicație. Instalarea merge doar din <strong data-browser-name>browser</strong>.</span></div>
      <ol class="steps">
        <li><span>Copiază linkul de mai jos</span></li>
        <li><span>Deschide <strong data-browser-name>browserul</strong> și lipește-l în bara de adrese</span></li>
      </ol>
      <button type="button" class="btn btn-secondary btn-block" data-copy="<?= h(rtrim((string) config('base_url'), '/')) ?>/install">
        <?= icon('copy') ?><span>Copiază linkul</span>
      </button>
    </div>

    <!-- Laptop / desktop -->
    <div class="card stack-sm" data-install="desktop" hidden>
      <p class="muted">ONE e gândit pentru telefon. Deschide <strong><?= h(parse_url((string) config('base_url'), PHP_URL_HOST) ?: 'one.smartstay.ro') ?></strong> pe telefon ca să-l instalezi — sau folosește-l direct aici, în browser.</p>
    </div>

    <button type="button" class="btn btn-ghost btn-block" data-install-continue>Continuă în browser</button>
  </div>
</div>
<script src="<?= h(asset('assets/js/install.js')) ?>" defer></script>
