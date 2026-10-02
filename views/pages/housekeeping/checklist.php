<?php
/**
 * Checklist for one apartment, today. Ported from cleaning_form.php + checklist.js.
 * @var string $apartment @var string $maid @var string $date @var int $count @var bool $locked
 * @var bool $canSubmit @var array $sections @var list<string> $photoAreas @var string $csrf
 */
$readOnly = $locked || !$canSubmit;
?>
<div class="stack" data-checklist data-apartment="<?= h($apartment) ?>" data-csrf="<?= h($csrf) ?>">
  <div class="hero">
    <div class="eyebrow">Checklist curățenie</div>
    <h1>Apartament <?= h($apartment) ?></h1>
    <div class="row" style="gap:8px;margin-top:8px;flex-wrap:wrap">
      <span class="badge badge-violet"><?= icon('housekeeping', 'icon icon-sm') ?><?= h($maid) ?></span>
      <span class="badge"><?= icon('calendar', 'icon icon-sm') ?><?= h(date('d.m.Y', strtotime($date))) ?></span>
    </div>
  </div>

  <?php if ($locked): ?>
    <div class="alert alert-success"><?= icon('check', 'icon icon-sm') ?><span>Acest checklist a fost finalizat.</span></div>
  <?php elseif ($count === 1): ?>
    <div class="alert alert-info"><?= icon('info', 'icon icon-sm') ?><span>Verificare finală (a doua trecere).</span></div>
  <?php endif; ?>
  <?php if (!$canSubmit && !$locked): ?>
    <div class="alert alert-info"><?= icon('info', 'icon icon-sm') ?><span>Ai acces doar de vizualizare.</span></div>
  <?php endif; ?>

  <form class="stack" data-checklist-form novalidate>
    <?php foreach ($sections as $key => [$title, $items]): ?>
      <fieldset class="card checklist-section" <?= $readOnly ? 'disabled' : '' ?>>
        <legend class="section-title"><?= h($title) ?></legend>
        <?php foreach ($items as $id => $label): ?>
          <label class="check-row">
            <input type="checkbox" name="checked" value="<?= h($id) ?>">
            <span><?= h($label) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>

    <?php if (!$readOnly): ?>
      <div class="card stack-sm">
        <h2 class="card-title">Fotografii obligatorii</h2>
        <p class="hint">Încarcă <?= count($photoAreas) ?> fotografii din zonele cerute mai jos.</p>
        <?php foreach ($photoAreas as $i => $area): $n = $i + 1; ?>
          <div class="photo-slot" data-photo-slot>
            <label class="photo-pick">
              <input type="file" accept="image/*" capture="environment" data-photo="<?= $n ?>" data-requirement="<?= h($area) ?>" hidden>
              <span class="photo-thumb" data-thumb><?= icon('camera') ?></span>
              <span class="grow">
                <span class="list-title"><?= h($area) ?></span>
                <span class="list-sub" data-photo-status>Apasă pentru a face poza</span>
              </span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="fab-bar fab-bar-bottom">
        <button type="submit" class="btn btn-violet btn-block" data-submit disabled>
          <span class="spinner"></span><span data-submit-label>Trimite checklist</span>
        </button>
      </div>
    <?php else: ?>
      <a class="btn btn-secondary btn-block" href="/housekeeping">Înapoi la listă</a>
    <?php endif; ?>
  </form>
</div>
