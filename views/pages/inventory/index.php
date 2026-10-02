<?php
/**
 * Inventar — one card per apartment, stock from the database. Today's reservation strip and
 * the Check-in / Check-out counts are filled by assets/js/inventory.js from Previo.
 * @var array $user @var list<array> $rows @var bool $canEdit @var string $filter
 */
use One\Controllers\InventoryController;
use One\Inventory\InventoryRepository;
use One\Inventory\Stock;

$critical = count(array_filter($rows, static fn(array $r): bool => $r['level'] === 'critical'));
$counts = ['all' => count($rows), 'critical' => $critical, 'checkin' => null, 'checkout' => null];
$stockBadge = ['critical' => 'badge-critical', 'warning' => 'badge-warning', 'ok' => 'badge-success'];
?>
<div class="stack" data-inventory data-can-edit="<?= $canEdit ? '1' : '0' ?>" data-filter="<?= h($filter) ?>">
  <div class="hero row-between">
    <div class="grow">
      <div class="eyebrow"><?= h(ro_date()) ?></div>
      <h1>Inventar</h1>
      <p>Lenjerii și prosoape pe apartament</p>
    </div>
    <button type="button" class="icon-btn icon-btn-surface" data-refresh aria-label="Reîmprospătează rezervările"><?= icon('refresh') ?></button>
  </div>

  <div class="search">
    <?= icon('search', 'icon icon-sm') ?>
    <input class="input" type="search" data-search placeholder="Caută apartament sau oaspete" autocomplete="off" aria-label="Caută apartament sau oaspete">
  </div>

  <div class="chips chips-indigo" role="group" aria-label="Filtre">
    <?php foreach (InventoryController::FILTERS as $key => $label): ?>
      <button type="button" class="chip<?= $key === $filter ? ' is-active' : '' ?>" data-filter-chip="<?= h($key) ?>" aria-pressed="<?= $key === $filter ? 'true' : 'false' ?>">
        <?= h($label) ?> <span class="count" data-count="<?= h($key) ?>"><?= $counts[$key] === null ? '·' : (int) $counts[$key] ?></span>
      </button>
    <?php endforeach; ?>
  </div>

  <?php if (!$canEdit): ?>
    <div class="alert alert-info"><?= icon('info', 'icon icon-sm') ?><span>Ai acces doar de vizualizare.</span></div>
  <?php endif; ?>
  <div class="alert alert-warning" data-occupancy-error hidden role="status"></div>

  <?php if (!$rows): ?>
    <div class="card empty">
      <span class="tile-icon" data-module="inventory"><?= icon('inventory', 'icon icon-lg') ?></span>
      <h2>Niciun apartament în inventar</h2>
      <p>Tabela inventar_apartamente e goală.</p>
    </div>
  <?php endif; ?>

  <div class="inv-grid" data-list>
    <?php foreach ($rows as $r): ?>
      <article class="card inv-card" data-apt="<?= h($r['apartment']) ?>" data-stock="<?= h($r['level']) ?>"
               data-depot="<?= $r['depot'] ? '1' : '0' ?>" data-linen="<?= (int) $r['items']['lenjerie'] ?>">
        <div class="inv-head">
          <span class="apt-badge apt-badge-indigo">
            <span><?= $r['depot'] ? 'Depozit' : 'Apt' ?></span><strong><?= h($r['apartment']) ?></strong>
          </span>
          <span class="grow">
            <span class="list-title"><?= $r['depot'] ? 'Depozit central' : 'Apartament ' . h($r['apartment']) ?></span>
            <span class="badge <?= $stockBadge[$r['level']] ?> inv-level" data-level-badge><?= h(Stock::LABELS[$r['level']]) ?></span>
          </span>
        </div>

        <div class="inv-res" data-res hidden></div>

        <div class="inv-items">
          <?php foreach (InventoryRepository::ITEMS as $item => $label): ?>
            <div class="counter<?= $item === 'lenjerie' ? ' counter-main' : '' ?>" data-item="<?= h($item) ?>">
              <span class="counter-label"><?= h($label) ?></span>
              <span class="counter-ctl">
                <button type="button" class="counter-btn" data-delta="-1" aria-label="<?= h($label) ?> minus unu"<?= $canEdit ? '' : ' disabled' ?>><?= icon('minus', 'icon icon-sm') ?></button>
                <output class="counter-value tabular" data-value><?= (int) $r['items'][$item] ?></output>
                <button type="button" class="counter-btn" data-delta="1" aria-label="<?= h($label) ?> plus unu"<?= $canEdit ? '' : ' disabled' ?>><?= icon('plus', 'icon icon-sm') ?></button>
              </span>
            </div>
          <?php endforeach; ?>
        </div>

        <label class="field">
          <span class="row-between"><span class="label">Necesar</span><span class="hint" data-note-state></span></span>
          <textarea class="input textarea" data-note rows="2" maxlength="<?= InventoryRepository::NOTE_MAX ?>"
                    placeholder="Ex.: 2 role hârtie, detergent vase"<?= $canEdit ? '' : ' readonly' ?>><?= h($r['note']) ?></textarea>
        </label>

        <?php if ($r['last']): ?>
          <p class="inv-foot faint" data-last>Modificat de <?= h($r['last']['by'] ?? 'cont șters') ?> · <?= h(local_time($r['last']['at'], 'd.m H:i')) ?></p>
        <?php else: ?>
          <p class="inv-foot faint" data-last hidden></p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="card empty" data-empty hidden>
    <span class="tile-icon" data-module="inventory"><?= icon('search', 'icon icon-lg') ?></span>
    <h2>Niciun rezultat</h2>
    <p>Schimbă filtrul sau căutarea.</p>
  </div>
</div>
