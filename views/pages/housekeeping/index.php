<?php
/**
 * Housekeeping for staff (admin / manager / user): Check-out allocation + Intermediară.
 * Data via assets/js/housekeeping.js. @var array $user @var string $tab @var bool $canEdit @var array $maids
 */
use One\Controllers\HousekeepingController;
?>
<div class="stack" data-housekeeping data-tab="<?= h($tab) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>">
  <div class="hero row-between">
    <div class="grow">
      <div class="eyebrow"><?= h(ro_date()) ?></div>
      <h1><?= $tab === 'checkout' ? 'Apartamente de curățat' : 'Curățenie intermediară' ?></h1>
      <p class="muted"><?= $tab === 'checkout'
          ? 'Selectează apartamentele, apoi alege menajera.'
          : 'Apartamente cu oaspeți cazați acum · 30 RON fix.' ?></p>
    </div>
    <button type="button" class="icon-btn icon-btn-surface" data-refresh aria-label="Reîmprospătează"><?= icon('refresh') ?></button>
  </div>

  <nav class="tabs tabs-violet" aria-label="Secțiuni Curățenie">
    <?php foreach (HousekeepingController::TABS as $key => $t): ?>
      <a href="<?= h($t['path']) ?>" class="tab<?= $key === $tab ? ' is-active' : '' ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= h($t['label']) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (isset($_GET['unassigned'])): ?>
    <div class="alert alert-warning"><?= icon('alert', 'icon icon-sm') ?><span>Apartamentul <?= h((string) $_GET['unassigned']) ?> nu e alocat nimănui azi. Alocă-l întâi unei menajere.</span></div>
  <?php endif; ?>
  <?php if (!$canEdit): ?>
    <div class="alert alert-info"><?= icon('info', 'icon icon-sm') ?><span>Ai acces doar de vizualizare.</span></div>
  <?php endif; ?>

  <?php if ($tab === 'intermediate' && $canEdit): ?>
    <label class="field">
      <span class="label">Menajera</span>
      <select class="select" data-maid-select>
        <option value="">Selectează…</option>
        <?php foreach ($maids as $key => $name): ?>
          <option value="<?= h($key) ?>"><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>

  <div class="alert alert-error" data-error hidden role="alert"></div>
  <div class="res-list" data-list aria-live="polite"></div>

  <?php if ($tab === 'checkout' && $canEdit): ?>
    <div class="assign-bar" data-assign-bar hidden>
      <div class="row-between">
        <span class="label">Alocă curățenia</span>
        <span class="badge badge-violet"><span data-selected>0</span> selectate</span>
      </div>
      <div class="maid-grid">
        <?php foreach ($maids as $key => $name): ?>
          <button type="button" class="btn btn-violet btn-sm" data-assign="<?= h($key) ?>"><?= h($name) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
