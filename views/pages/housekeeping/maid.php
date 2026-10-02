<?php
/**
 * A maid's own list for today — only the apartments assigned to her (filtered in the controller).
 * @var array $user @var string $maid @var list<string> $apartments @var array<string,int> $counts
 */
use One\Housekeeping\Checklist;

$firstName = explode(' ', trim($user['name']))[0];
?>
<div class="stack">
  <?php if (isset($_GET['welcome'])): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Parola e setată. Bun venit în SmartStay ONE!</span></div>
  <?php endif; ?>
  <?php if (isset($_GET['done'])): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Checklist trimis pentru apartamentul <?= h((string) $_GET['done']) ?>. Mulțumim!</span></div>
  <?php endif; ?>

  <div class="hero row-between">
    <div class="grow">
    <div class="eyebrow"><?= h(ro_date()) ?></div>
    <h1>Bună, <?= h($firstName) ?></h1>
    <p><?= $apartments
        ? 'Ai ' . count($apartments) . ' apartament' . (count($apartments) === 1 ? '' : 'e') . ' azi. Apasă pe unul pentru checklist.'
        : 'Lista ta de azi' ?></p>
    </div>
    <a class="icon-btn icon-btn-surface" href="/housekeeping" aria-label="Reîmprospătează"><?= icon('refresh') ?></a>
  </div>

  <?php if (!$apartments): ?>
    <div class="card empty">
      <span class="tile-icon" data-module="housekeeping"><?= icon('housekeeping', 'icon icon-lg') ?></span>
      <h2>Nicio curățenie alocată</h2>
      <p>Când îți sunt alocate apartamente, apar aici. Trage în jos sau redeschide aplicația ca să verifici.</p>
    </div>
  <?php else: ?>
    <div class="stack-sm">
      <?php foreach ($apartments as $apt):
          $n = (int) ($counts[$apt] ?? 0);
          $done = $n >= Checklist::MAX_SUBMISSIONS;
      ?>
        <a class="card card-link hk-card" href="/housekeeping/checklist/<?= h($apt) ?>">
          <span class="apt-badge apt-badge-violet"><span>Apt</span><strong><?= h($apt) ?></strong></span>
          <span class="grow">
            <span class="list-title">Apartament <?= h($apt) ?></span>
            <span class="list-sub">
              <?php if ($done): ?>Finalizat<?php elseif ($n === 1): ?>Trimis · verificare finală posibilă<?php else: ?>În așteptare<?php endif; ?>
            </span>
          </span>
          <?php if ($done || $n === 1): ?>
            <span class="badge badge-success"><?= icon('check', 'icon icon-sm') ?><?= $done ? 'Gata' : 'Trimis' ?></span>
          <?php else: ?>
            <span class="badge badge-warning">De făcut</span>
          <?php endif; ?>
          <?= icon('chevron', 'icon icon-sm chev') ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
