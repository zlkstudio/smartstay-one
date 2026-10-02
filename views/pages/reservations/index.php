<?php
/**
 * Rezervări — one shell for the four tabs. Data is loaded by assets/js/reservations.js.
 * @var array $user @var string $tab @var bool $canEdit @var string $date
 */
use One\Controllers\ReservationsController;

$heading = [
    'today'    => ['Astăzi', 'check-in-uri programate'],
    'tomorrow' => ['Mâine', 'check-in-uri programate'],
    'whatsapp' => ['WhatsApp', 'oaspeți de contactat'],
    'link'     => ['Generator link', 'Guest App pentru ultimele 4 zile'],
][$tab];
?>
<div class="stack" data-reservations data-tab="<?= h($tab) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>">

  <div class="hero row-between">
    <div class="grow">
      <div class="eyebrow"><?= h(ro_date(new DateTimeImmutable($date))) ?></div>
      <h1><?= h($heading[0]) ?></h1>
      <p><span class="badge badge-blue tabular" data-count>—</span> <span class="muted"><?= h($heading[1]) ?></span></p>
    </div>
    <?php if ($tab !== 'link'): ?>
      <button type="button" class="icon-btn icon-btn-surface" data-refresh aria-label="Reîmprospătează"><?= icon('refresh') ?></button>
    <?php endif; ?>
  </div>

  <nav class="tabs" aria-label="Secțiuni Rezervări">
    <?php foreach (ReservationsController::TABS as $key => $t): ?>
      <a href="<?= h($t['path']) ?>" class="tab<?= $key === $tab ? ' is-active' : '' ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= h($t['label']) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$canEdit): ?>
    <div class="alert alert-info"><?= icon('info', 'icon icon-sm') ?><span>Ai acces doar de vizualizare: toggle-urile, Nuki și marcajele WhatsApp sunt blocate.</span></div>
  <?php endif; ?>

  <?php if ($tab === 'today' || $tab === 'tomorrow'): ?>
    <div class="search">
      <?= icon('search', 'icon icon-sm') ?>
      <input class="input" type="search" data-search placeholder="Caută nume, telefon sau apartament" autocomplete="off" inputmode="search" aria-label="Caută rezervări">
    </div>
    <div class="chips" data-chips role="tablist"></div>
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
