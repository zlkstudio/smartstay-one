<?php
/**
 * Housekeeping: Check-out allocation + Intermediară (staff). A maid gets the same Check-out list,
 * limited to herself ($selfMaid): she takes free apartments and opens her checklists.
 * Data via assets/js/housekeeping.js. @var array $user @var string $tab @var bool $canEdit @var array $maids @var ?string $selfMaid
 */
use One\Controllers\HousekeepingController;
?>
<?php $firstName = explode(' ', trim($user['name']))[0]; ?>
<div class="stack" data-housekeeping data-tab="<?= h($tab) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>"
     <?php if ($selfMaid !== null): ?>data-self="<?= h($maids[$selfMaid]) ?>"<?php endif; ?>>
  <?php if (isset($_GET['welcome'])): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Parola e setată. Bun venit în SmartStay ONE!</span></div>
  <?php endif; ?>
  <?php if (isset($_GET['done'])): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Checklist trimis pentru apartamentul <?= h((string) $_GET['done']) ?>. Mulțumim!</span></div>
  <?php endif; ?>

  <div class="hero row-between">
    <div class="grow">
      <div class="eyebrow"><?= h(ro_date()) ?></div>
      <h1><?= $selfMaid !== null ? 'Bună, ' . h($firstName) : ($tab === 'checkout' ? 'Apartamente de curățat' : 'Curățenie intermediară') ?></h1>
      <?php if ($tab === 'intermediate'): ?>
        <p class="muted">Apartamente cu oaspeți cazați acum · 30 RON fix.</p>
      <?php endif; ?>
    </div>
    <button type="button" class="icon-btn icon-btn-surface" data-refresh aria-label="Reîmprospătează"><?= icon('refresh') ?></button>
  </div>

  <?php if ($selfMaid === null): ?>
    <nav class="tabs tabs-violet" aria-label="Secțiuni Curățenie">
      <?php foreach (HousekeepingController::TABS as $key => $t): ?>
        <a href="<?= h($t['path']) ?>" class="tab<?= $key === $tab ? ' is-active' : '' ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= h($t['label']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

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
        <span class="label"><?= $selfMaid !== null ? 'Preiei curățenia' : 'Alocă curățenia' ?></span>
        <span class="badge badge-violet"><span data-selected>0</span> selectate</span>
      </div>
      <div class="maid-grid">
        <?php foreach ($maids as $key => $name): ?>
          <button type="button" class="btn btn-violet btn-sm" data-assign="<?= h($key) ?>"><?= $selfMaid !== null ? 'Preiau' : h($name) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
