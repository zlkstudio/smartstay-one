<?php
/**
 * Rezervări — one shell for the four tabs. Data is loaded by assets/js/reservations.js.
 * @var array $user @var string $tab @var bool $canEdit
 * @var array $tabs  tab-urile vizibile (menajera: doar Astăzi / Mâine)
 * @var bool $compact  card redus pentru menajeră: fără filtre, toggle-uri, acțiuni, notă „doar vizualizare"
 */
use One\Controllers\ReservationsController;

$short = static function (string $ymd): string {
    $d = new DateTimeImmutable($ymd);
    $days = ['Dum', 'Lun', 'Mar', 'Mie', 'Joi', 'Vin', 'Sâm'];
    $months = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    return $days[(int) $d->format('w')] . ', ' . $d->format('j') . ' ' . $months[(int) $d->format('n') - 1];
};
$sub = [
    'today'    => $short(date('Y-m-d')),
    'tomorrow' => $short(date('Y-m-d', strtotime('+1 day'))),
    'whatsapp' => 'Review-uri',
    'link'     => 'Guest App',
];
$tabIcons = ['whatsapp' => 'whatsapp', 'link' => 'link'];
$index = array_search($tab, array_keys($tabs), true);
?>
<div class="stack" data-reservations data-tab="<?= h($tab) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>" data-compact="<?= $compact ? '1' : '0' ?>">

  <div class="res-nav">
    <nav class="daynav" aria-label="Secțiuni Rezervări" style="--i: <?= (int) $index ?>; --n: <?= count($tabs) ?>" data-daynav>
      <span class="daynav-thumb" aria-hidden="true"></span>
      <?php foreach (array_keys($tabs) as $i => $key):
          $t = $tabs[$key];
          $on = $key === $tab;
      ?>
        <a href="<?= h($t['path']) ?>" class="daynav-item<?= $on ? ' is-active' : '' ?>" data-i="<?= $i ?>" <?= $on ? 'aria-current="page"' : '' ?>>
          <span class="daynav-label">
            <?php if (isset($tabIcons[$key])): ?><?= icon($tabIcons[$key], 'icon daynav-icon') ?><?php endif; ?>
            <?= h($t['label']) ?>
            <?php if ($on): ?><span class="daynav-count tabular" data-count>·</span><?php endif; ?>
          </span>
          <span class="daynav-sub"><?= h($sub[$key]) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php if ($tab !== 'link'): ?>
      <button type="button" class="icon-btn icon-btn-surface" data-refresh aria-label="Reîmprospătează"><?= icon('refresh') ?></button>
    <?php endif; ?>
  </div>

  <?php if (!$canEdit && !$compact): ?>
    <div class="alert alert-info"><?= icon('info', 'icon icon-sm') ?><span>Ai acces doar de vizualizare: toggle-urile, Nuki și marcajele WhatsApp sunt blocate.</span></div>
  <?php endif; ?>

  <?php if ($tab === 'today' || $tab === 'tomorrow'): ?>
    <div class="search">
      <?= icon('search', 'icon icon-sm') ?>
      <input class="input" type="search" data-search placeholder="Caută nume, telefon sau apartament" autocomplete="off" inputmode="search" aria-label="Caută rezervări">
    </div>
    <?php if (!$compact): ?><div class="chips" data-chips role="tablist"></div><?php endif; ?>
    <div class="alert alert-error" data-error hidden role="alert"></div>
    <div class="res-list" data-list aria-live="polite"></div>

  <?php elseif ($tab === 'whatsapp'): ?>
    <div class="search">
      <?= icon('search', 'icon icon-sm') ?>
      <input class="input" type="search" data-search placeholder="Caută nume, telefon sau apartament" autocomplete="off" inputmode="search" aria-label="Caută oaspeți">
    </div>
    <div class="chips" role="tablist" data-windows>
      <button type="button" class="chip is-active" data-window="w1">Azi <span class="count" data-window-count="w1">—</span></button>
      <button type="button" class="chip" data-window="w2">Acum 7 zile <span class="count" data-window-count="w2">—</span></button>
      <button type="button" class="chip" data-window="w3">Acum 14 zile <span class="count" data-window-count="w3">—</span></button>
    </div>
    <div class="alert alert-error" data-error hidden role="alert"></div>
    <div class="res-list" data-list aria-live="polite"></div>

  <?php else: /* link */ ?>
    <div class="alert alert-error" data-error hidden role="alert"></div>
    <div class="card stack-sm">
      <label class="field">
        <span class="label">Rezervare</span>
        <select class="select" data-link-select disabled>
          <option value="">Se încarcă rezervările…</option>
        </select>
      </label>
      <div class="segmented" role="radiogroup" aria-label="Format link">
        <input type="radio" name="link-format" id="fmt-dynamic" value="dynamic" checked>
        <label for="fmt-dynamic">Dinamic</label>
        <input type="radio" name="link-format" id="fmt-legacy" value="legacy">
        <label for="fmt-legacy">Legacy</label>
      </div>
      <p class="hint"><strong>Dinamic:</strong> link scurt, datele vin live din Previo. <strong>Legacy:</strong> toate datele în URL (backup pentru linkurile vechi).</p>
    </div>

    <div class="card" data-link-details hidden>
      <dl class="kv" data-link-kv></dl>
    </div>

    <div class="card stack-sm" data-link-result hidden>
      <span class="label">Link Guest App</span>
      <div class="credential"><code class="link-code" data-link-url></code></div>
      <div class="btn-grid">
        <button type="button" class="btn btn-secondary btn-sm" data-link-copy><?= icon('copy', 'icon icon-sm') ?><span>Copiază</span></button>
        <a class="btn btn-secondary btn-sm" data-link-open target="_blank" rel="noopener"><?= icon('external', 'icon icon-sm') ?><span>Deschide</span></a>
        <a class="btn btn-whatsapp btn-sm" data-link-wa target="_blank" rel="noopener"><?= icon('whatsapp', 'icon icon-sm') ?><span>Trimite pe WhatsApp</span></a>
      </div>
    </div>
  <?php endif; ?>
</div>
