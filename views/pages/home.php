<?php
/** @var array $user @var list<string> $modules @var ?array $health */
use One\Auth\Access;

$descriptions = [
    'reservations' => 'Check-in azi și mâine, taxe, Nuki, WhatsApp',
    'housekeeping' => 'Check-out, intermediare, checklist',
    'inventory'    => 'Lenjerii, prosoape, necesar',
    'reports'      => 'Ocupare, canale, venituri',
];
$firstName = explode(' ', trim($user['name']))[0];
?>
<div class="stack">
  <?php if (isset($_GET['welcome'])): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Parola e setată. Bun venit în SmartStay ONE!</span></div>
  <?php endif; ?>

  <div class="hero">
    <div class="eyebrow"><?= h(ro_date()) ?></div>
    <h1><?= h(greeting()) ?>, <?= h($firstName) ?></h1>
  </div>

  <?php if ($health !== null): ?>
    <a href="/settings" class="card card-link row">
      <span class="dot <?= $health['ok'] === $health['total'] ? 'dot-ok' : 'dot-warn' ?>"></span>
      <span class="grow">
        <span class="list-title">Stare sistem · <?= (int) $health['ok'] ?>/<?= (int) $health['total'] ?> baze în regulă</span><br>
        <span class="list-sub"><?= $health['problems'] ? h($health['problems'][0]) : 'Toate conexiunile funcționează' ?></span>
      </span>
      <?= icon('chevron', 'icon icon-sm chev') ?>
    </a>
  <?php endif; ?>

  <h2 class="section-title">Module</h2>
  <?php if ($modules): ?>
    <div class="tiles">
      <?php foreach ($modules as $module): ?>
        <a class="tile" href="/<?= h($module) ?>" data-module="<?= h($module) ?>">
          <span class="tile-icon"><?= icon($module) ?></span>
          <span>
            <span class="tile-name"><?= h(Access::LABELS[$module]) ?></span>
            <span class="tile-meta" style="display:block"><?= h($descriptions[$module]) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card empty">
      <span class="tile-icon"><?= icon('lock', 'icon icon-lg') ?></span>
      <h2>Niciun modul activ</h2>
      <p>Contul tău nu are încă acces la vreun modul. Cere administratorului să ți-l activeze.</p>
    </div>
  <?php endif; ?>

  <div class="card row">
    <span class="tile-icon"><?= icon('info') ?></span>
    <p class="muted grow" style="font-size:14.5px">Indicatorii zilei (check-in, taxe, stoc) și rapoartele apar aici pe măsură ce modulele sunt mutate în ONE.</p>
  </div>
</div>
