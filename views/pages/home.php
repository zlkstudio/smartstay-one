<?php
/**
 * @var array $user @var list<string> $modules @var ?array $health
 * @var bool $canReports @var bool $canInventory @var ?list<string> $critical
 */
use One\Auth\Access;

$descriptions = [
    'reservations' => 'Check-in azi și mâine, taxe, Nuki, WhatsApp',
    'housekeeping' => 'Check-out, intermediare, checklist',
    'inventory'    => 'Lenjerii, prosoape, necesar',
    'reports'      => 'Ocupare, canale, plata menajerelor',
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

  <?php if ($canReports): ?>
    <h2 class="section-title">Azi</h2>
    <a href="/reports" class="card card-link stack-sm" data-home-today>
      <div class="stat-grid" data-home-stats>
        <?php foreach (['Libere la noapte', 'Ocupate', 'Check-in', 'Check-out'] as $label): ?>
          <div class="stat"><span class="stat-value"><span class="skeleton" style="display:inline-block;width:42px;height:26px"></span></span><span class="stat-label"><?= h($label) ?></span></div>
        <?php endforeach; ?>
      </div>
      <span class="list-sub" data-home-meta>Se încarcă din Previo…</span>
    </a>
    <?php if ($canInventory): ?>
      <a href="/inventory?filter=critical" class="card card-link row" data-home-stock hidden>
        <span class="dot dot-warn" data-home-stock-dot></span>
        <span class="grow"><span class="list-title" data-home-stock-title></span><br><span class="list-sub" data-home-stock-sub></span></span>
        <?= icon('chevron', 'icon icon-sm chev') ?>
      </a>
    <?php endif; ?>
  <?php elseif ($critical !== null): ?>
    <h2 class="section-title">Azi</h2>
    <a href="/inventory?filter=critical" class="card card-link row">
      <span class="dot <?= $critical ? 'dot-warn' : 'dot-ok' ?>"></span>
      <span class="grow">
        <span class="list-title"><?= $critical ? count($critical) . ' apartamente cu lenjerii pe roșu' : 'Stocul de lenjerii e în regulă' ?></span><br>
        <span class="list-sub"><?= $critical ? h(implode(', ', $critical)) : 'Niciun apartament pe roșu' ?></span>
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

</div>
