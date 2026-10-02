<?php
/**
 * Inventar — one card per apartment, stock from the database. Today's reservation strip and
 * the Check-in / Check-out counts are filled by assets/js/inventory.js from Previo.
 * Each card has two faces: stock + Necesar (front), set / box + Tehnic (back).
 * @var array $user @var list<array> $rows @var bool $canEdit @var string $filter
 */
use One\Controllers\InventoryController;
use One\Inventory\InventoryRepository;
use One\Inventory\Stock;

$critical = count(array_filter($rows, static fn(array $r): bool => $r['level'] === 'critical'));
$counts = ['all' => count($rows), 'critical' => $critical, 'checkin' => null, 'checkout' => null];
$stockBadge = ['critical' => 'badge-critical', 'warning' => 'badge-warning', 'ok' => 'badge-success'];
$itemIcons = [
    'lenjerie'          => 'bed',
    'fete_perne_mari'   => 'pillow',
    'prosoape_mari'     => 'towel',
    'prosoape_mici'     => 'towel-sm',
    'prosoape_picioare' => 'mat',
];
$short = ['lenjerie' => 'lenjerie', 'fete_perne_mari' => 'fețe pernă', 'prosoape_mari' => 'prosoape mari',
    'prosoape_mici' => 'mici', 'prosoape_picioare' => 'picioare'];
$batchText = static function (array $deltas) use ($short): string {
    $parts = [];
    foreach ($deltas as $item => $n) {
        $parts[] = abs($n) . ' ' . $short[$item];
    }
    return implode(' · ', $parts);
};
$dis = $canEdit ? '' : ' disabled';
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

        <!-- ── Front: stock ─────────────────────────────────────────── -->
        <div class="inv-face inv-front" data-face="front">
          <div class="inv-head">
            <span class="apt-badge apt-badge-indigo">
              <span><?= $r['depot'] ? 'Depozit' : 'Apt' ?></span><strong><?= h($r['apartment']) ?></strong>
            </span>
            <span class="grow">
              <span class="list-title"><?= $r['depot'] ? 'Depozit central' : 'Apartament ' . h($r['apartment']) ?></span>
              <span class="inv-badges">
                <span class="badge <?= $stockBadge[$r['level']] ?>" data-level-badge><?= h(Stock::LABELS[$r['level']]) ?></span>
                <span class="badge badge-blue" data-tech-badge<?= trim($r['tech']) === '' ? ' hidden' : '' ?>><?= icon('tools', 'icon icon-xs') ?>Tehnic</span>
              </span>
            </span>
            <button type="button" class="icon-btn icon-btn-surface" data-flip aria-label="Întoarce cardul: set, cutie, tehnic"><?= icon('flip', 'icon icon-sm') ?></button>
          </div>

          <div class="inv-res" data-res hidden></div>

          <div class="inv-items">
            <?php foreach (InventoryRepository::ITEMS as $item => $label): ?>
              <div class="counter" data-item="<?= h($item) ?>">
                <span class="counter-icon"><?= icon($itemIcons[$item], 'icon icon-sm') ?></span>
                <span class="counter-label"><?= h($label) ?></span>
                <span class="counter-ctl">
                  <button type="button" class="counter-btn" data-delta="-1" aria-label="<?= h($label) ?> minus unu"<?= $dis ?>><?= icon('minus', 'icon icon-sm') ?></button>
                  <output class="counter-value tabular" data-value><?= (int) $r['items'][$item] ?></output>
                  <button type="button" class="counter-btn" data-delta="1" aria-label="<?= h($label) ?> plus unu"<?= $dis ?>><?= icon('plus', 'icon icon-sm') ?></button>
                </span>
              </div>
            <?php endforeach; ?>
          </div>

          <label class="field">
            <span class="row-between"><span class="label">Necesar</span><span class="hint" data-save-state="note"></span></span>
            <textarea class="input textarea" data-autosave="note" rows="2" maxlength="<?= InventoryRepository::NOTE_MAX ?>"
                      placeholder="Ex.: 2 role hârtie, detergent vase"<?= $canEdit ? '' : ' readonly' ?>><?= h($r['note']) ?></textarea>
          </label>

          <p class="inv-foot faint" data-last<?= $r['last'] ? '' : ' hidden' ?>><?= $r['last']
              ? 'Modificat de ' . h($r['last']['by'] ?? 'cont șters') . ' · ' . h(local_time($r['last']['at'], 'd.m H:i')) : '' ?></p>
        </div>

        <!-- ── Back: set / box + Tehnic ─────────────────────────────── -->
        <div class="inv-face inv-back" data-face="back" hidden>
          <div class="inv-head">
            <span class="apt-badge apt-badge-indigo">
              <span>Tehnic</span><strong><?= h($r['apartment']) ?></strong>
            </span>
            <span class="grow"><span class="list-title">Operații rapide</span><span class="list-sub">Toate cele 5 articole deodată</span></span>
            <button type="button" class="icon-btn icon-btn-surface" data-flip aria-label="Înapoi la stoc"><?= icon('flip', 'icon icon-sm') ?></button>
          </div>

          <button type="button" class="batch-btn batch-set" data-batch="set"<?= $dis ?>>
            <span class="batch-ico"><?= icon('minus') ?></span>
            <span class="grow"><strong>Scade un set</strong><span><?= h($batchText(InventoryRepository::BATCHES['set'])) ?></span></span>
          </button>
          <button type="button" class="batch-btn batch-box" data-batch="box"<?= $dis ?>>
            <span class="batch-ico"><?= icon('box') ?></span>
            <span class="grow"><strong>Adaugă o cutie</strong><span><?= h($batchText(InventoryRepository::BATCHES['box'])) ?></span></span>
          </button>
          <div class="strip strip-neutral batch-undo" data-undo hidden>
            <?= icon('check') ?><span class="grow" data-undo-text></span>
            <button type="button" class="link-btn" data-undo-btn><?= icon('undo', 'icon icon-xs') ?> Anulează</button>
          </div>

          <div class="inv-tech">
            <span class="label row"><?= icon('tools', 'icon icon-sm') ?> Tehnic</span>
            <label class="check-tile">
              <input type="checkbox" data-tv-app<?= $r['tvApp'] ? ' checked' : '' ?><?= $dis ?>>
              <span>TV App</span>
            </label>
            <label class="field">
              <span class="row-between"><span class="sr-only">Note tehnice</span><span></span><span class="hint" data-save-state="tech"></span></span>
              <textarea class="input textarea" data-autosave="tech" rows="3" maxlength="<?= InventoryRepository::NOTE_MAX ?>"
                        placeholder="Ce trebuie făcut din punct de vedere tehnic…"<?= $canEdit ? '' : ' readonly' ?>><?= h($r['tech']) ?></textarea>
            </label>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="card empty" data-empty hidden>
    <span class="tile-icon" data-module="inventory"><?= icon('search', 'icon icon-lg') ?></span>
    <h2>Niciun rezultat</h2>
    <p>Schimbă filtrul sau căutarea.</p>
  </div>
</div>
