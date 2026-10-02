<?php
/**
 * Rapoarte · Prezentare — today, occupancy (30 days back + 14 ahead), booking channels.
 * @var ?array $report @var ?string $error @var bool $canEdit @var string $tab
 */
use One\Controllers\ReportsController;
use One\Reports\OperationsReport;

$fmtDay = static fn(string $d): string => date('d.m', strtotime($d));
?>
<div class="stack" data-reports>
  <div class="hero row-between">
    <div class="grow">
      <div class="eyebrow"><?= h(ro_date()) ?></div>
      <h1>Rapoarte</h1>
      <?php if ($report): ?>
        <p>Actualizat la <?= h(local_time($report['computedAt'], 'H:i')) ?> · date din Previo</p>
      <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
      <button type="button" class="icon-btn icon-btn-surface" data-report-refresh aria-label="Recalculează acum"><?= icon('refresh') ?></button>
    <?php endif; ?>
  </div>

  <nav class="tabs tabs-teal" aria-label="Secțiuni Rapoarte">
    <?php foreach (ReportsController::TABS as $key => $t): ?>
      <a href="<?= h($t['path']) ?>" class="tab<?= $key === $tab ? ' is-active' : '' ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= h($t['label']) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert', 'icon icon-sm') ?><span><?= h($error) ?> Plata menajerelor funcționează în continuare.</span></div>
  <?php endif; ?>

  <?php if ($report): ?>
    <?php $t = $report['today']; $total = (int) $report['roster']['total']; ?>
    <h2 class="section-title">Azi</h2>
    <div class="stat-grid">
      <div class="stat"><span class="stat-value tabular"><?= count($t['free']) ?><small>/<?= $total ?></small></span><span class="stat-label">Libere la noapte</span></div>
      <div class="stat"><span class="stat-value tabular"><?= (int) $t['occupied'] ?></span><span class="stat-label">Ocupate</span></div>
      <div class="stat"><span class="stat-value tabular"><?= (int) $t['checkIns'] ?></span><span class="stat-label">Check-in</span></div>
      <div class="stat"><span class="stat-value tabular"><?= (int) $t['checkOuts'] ?></span><span class="stat-label">Check-out</span></div>
    </div>
    <?php if ($t['free']): ?>
      <div class="card stack-sm">
        <span class="label">Apartamente libere în seara asta</span>
        <div class="apt-chips">
          <?php foreach ($t['free'] as $apt): ?><span class="badge badge-teal tabular"><?= h($apt) ?></span><?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php if (!empty($t['options'])): ?>
      <p class="muted">Ocupate doar cu opțiune neconfirmată: <?= h(implode(', ', $t['options'])) ?> (numărate ca ocupate, ca în Previo).</p>
    <?php endif; ?>

    <?php $occ = $report['occupancy']; ?>
    <h2 class="section-title">Ocupare</h2>
    <div class="card stack-sm">
      <div class="row-between">
        <div><span class="stat-value tabular"><?= (int) $occ['avgPast'] ?>%</span><span class="stat-label">ultimele 30 de nopți</span></div>
        <div class="text-right"><span class="stat-value stat-value-muted tabular"><?= (int) $occ['avgNext'] ?>%</span><span class="stat-label">următoarele <?= OperationsReport::NEXT_DAYS ?> (rezervat)</span></div>
      </div>
      <div class="bars" role="img" aria-label="Ocupare pe nopți: medie <?= (int) $occ['avgPast'] ?>% în ultimele 30, <?= (int) $occ['avgNext'] ?>% rezervat în următoarele <?= OperationsReport::NEXT_DAYS ?>">
        <?php foreach ($occ['days'] as $d): ?>
          <span class="bar<?= $d['future'] ? ' is-future' : '' ?><?= $d['date'] === $report['date'] ? ' is-today' : '' ?>"
                title="<?= h($fmtDay($d['date']) . ' · ' . $d['occupied'] . '/' . $total . ' · ' . $d['pct'] . '%') ?>">
            <span style="height:<?= max(2, (int) $d['pct']) ?>%"></span>
          </span>
        <?php endforeach; ?>
      </div>
      <div class="row-between faint bars-axis">
        <span><?= h($fmtDay($occ['days'][0]['date'])) ?></span><span>azi</span><span><?= h($fmtDay(end($occ['days'])['date'])) ?></span>
      </div>
      <p class="hint">
        <?= $report['roster']['source'] === 'config'
            ? "Calculat pe cele $total apartamente din config/app.php → apartments."
            : "Calculat pe cele $total apartamente cu rezervări în Previo în ultimele ~90 de zile. Lista exactă se poate fixa în config/app.php → apartments." ?>
      </p>
    </div>

    <?php $ch = $report['channels']; ?>
    <h2 class="section-title">Canale · ultimele 30 de zile</h2>
    <div class="card">
      <?php if ($ch['total'] === 0): ?>
        <p class="muted">Niciun check-in în ultimele 30 de zile.</p>
      <?php else:
        $stops = [];
        $acc = 0.0;
        foreach (OperationsReport::CHANNELS as $key => $meta) {
            $n = $ch['rows'][$key]['reservations'] ?? 0;
            if ($n === 0) continue;
            $start = $acc;
            $acc += $n * 100 / $ch['total'];
            $stops[] = sprintf('%s %.2f%% %.2f%%', $meta['color'], $start, $acc);
        }
      ?>
        <div class="channels">
          <div class="donut" style="background:conic-gradient(<?= h(implode(', ', $stops)) ?>)" role="img"
               aria-label="Rezervări pe canale, ultimele 30 de zile">
            <span class="donut-hole"><strong class="tabular"><?= (int) $ch['total'] ?></strong><span>rezervări</span></span>
          </div>
          <ul class="legend">
            <?php foreach (OperationsReport::CHANNELS as $key => $meta):
              $row = $ch['rows'][$key] ?? ['reservations' => 0, 'nights' => 0];
              if ($row['reservations'] === 0) continue; ?>
              <li>
                <span class="swatch" style="background:<?= h($meta['color']) ?>"></span>
                <span class="grow"><span class="list-title"><?= h($meta['label']) ?></span>
                  <span class="list-sub tabular"><?= (int) $row['reservations'] ?> rezervări · <?= (int) $row['nights'] ?> nopți</span></span>
                <strong class="tabular"><?= (int) round($row['reservations'] * 100 / $ch['total']) ?>%</strong>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <p class="hint" style="margin-top:12px">Canalul e citit din câmpurile și notele Previo; ce nu poate fi identificat apare la „Direct / altele".</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
