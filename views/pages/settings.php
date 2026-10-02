<?php
/** @var array $report */
use One\System\HealthCheck;

$statusLabel = ['ok' => 'Conectat', 'warn' => 'Tabele lipsă', 'error' => 'Eroare', 'missing' => 'Neconfigurat'];
$statusBadge = ['ok' => 'badge-success', 'warn' => 'badge-warning', 'error' => 'badge-critical', 'missing' => 'badge-critical'];
?>
<div class="stack">
  <div class="hero">
    <h1>Setări</h1>
    <p>Starea conexiunilor de care depinde ONE. Doar citire — nimic nu se modifică de aici.</p>
  </div>

  <h2 class="section-title">Baze de date</h2>
  <?php foreach ($report['databases'] as $name => $db): ?>
    <div class="card stack-sm">
      <div class="row">
        <span class="dot dot-<?= h($db['status']) ?>"></span>
        <span class="grow">
          <span class="list-title"><?= h(HealthCheck::DB_LABELS[$name]) ?></span>
          <span class="list-sub" style="display:block"><?= h($db['database'] ?? "config/database-$name.php") ?></span>
        </span>
        <span class="badge <?= $statusBadge[$db['status']] ?>"><?= h($statusLabel[$db['status']]) ?></span>
      </div>
      <?php if (!empty($db['tables'])): ?>
        <dl class="kv">
          <?php foreach ($db['tables'] as $table => $rows): ?>
            <dt><?= h($table) ?></dt>
            <dd><?= in_array($table, $db['missing'] ?? [], true) ? '<span style="color:var(--critical)">lipsește</span>' : '~' . number_format((int) $rows, 0, ',', '.') . ' rânduri' ?></dd>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
      <?php if ($db['status'] !== 'ok'): ?>
        <p class="hint"><?= h($db['message']) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (in_array($report['databases']['cleaning']['status'], ['ok', 'warn'], true)): ?>
    <h2 class="section-title">Menajere</h2>
    <div class="card stack-sm">
      <dl class="kv">
        <dt>În config/app.php</dt>
        <dd><?= h(implode(', ', $report['maids']['configured'])) ?: '—' ?></dd>
        <dt>În cleaning_records (90 zile)</dt>
        <dd><?= h(implode(', ', $report['maids']['stored'])) ?: '—' ?></dd>
      </dl>
      <?php if ($report['maids']['unmatched']): ?>
        <div class="alert alert-warning"><?= icon('alert', 'icon icon-sm') ?>
          <span>Nume din Housekeeping care nu apar în config: <strong><?= h(implode(', ', $report['maids']['unmatched'])) ?></strong>. Adaugă-le în <code>maids</code> ca să poată primi cont.</span>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h2 class="section-title">Integrări</h2>
  <div class="list">
    <?php foreach ($report['integrations'] as $int): ?>
      <div class="list-item">
        <span class="dot <?= $int['present'] ? 'dot-ok' : 'dot-warn' ?>"></span>
        <span class="grow"><span class="list-title"><?= h($int['label']) ?></span><br><span class="list-sub"><?= h($int['file']) ?></span></span>
        <span class="badge <?= $int['present'] ? 'badge-success' : 'badge-warning' ?>"><?= $int['present'] ? 'Prezent' : 'Lipsește' ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($report['reports'] !== null): ?>
    <h2 class="section-title">Rapoarte</h2>
    <div class="card row">
      <span class="dot <?= $report['reports']['stale'] ? 'dot-warn' : 'dot-ok' ?>"></span>
      <span class="grow">
        <span class="list-title"><?= $report['reports']['last'] ? 'Ultimul calcul: ' . h(local_time($report['reports']['last'])) : 'Niciun calcul încă' ?></span><br>
        <span class="list-sub"><?= $report['reports']['stale'] ? 'Cronul orar bin/reports-cron.php nu rulează — vezi ONE-INSTRUCTIONS §6.5' : 'Cronul orar rulează normal' ?></span>
      </span>
    </div>
  <?php endif; ?>

  <h2 class="section-title">Server</h2>
  <div class="card">
    <dl class="kv">
      <?php foreach ($report['environment'] as $env): ?>
        <dt><?= h($env['label']) ?></dt>
        <dd style="color:<?= $env['ok'] ? 'inherit' : 'var(--critical)' ?>"><?= h($env['value']) ?></dd>
      <?php endforeach; ?>
      <?php if ($report['sessions']): ?>
        <dt>Utilizatori activi</dt><dd><?= (int) $report['sessions']['users'] ?></dd>
        <dt>Sesiuni deschise</dt><dd><?= (int) $report['sessions']['active'] ?></dd>
      <?php endif; ?>
    </dl>
  </div>
</div>
