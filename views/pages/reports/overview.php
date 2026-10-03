<?php
/**
 * Rapoarte · Prezentare — operational today + KPIs, occupancy, channels, per apartment,
 * monthly trend, reservation movement and guests for one period (?p= or ?from=&to=).
 * Every number comes from One\Reports\Analytics (see ReportsController::overviewData).
 * @var ?array $report @var ?array $page @var One\Reports\Period $period
 * @var ?string $error @var bool $canEdit @var string $tab
 */
use One\Controllers\ReportsController;
use One\Reports\OperationsReport;
use One\Reports\Period;

$num = static function (?float $v, int $dec = 0): string {
    if ($v === null) {
        return '—';
    }
    $s = number_format($v, $dec, ',', '.');
    return $dec > 0 ? preg_replace('/,0+$/', '', $s) : $s;
};
$lei = static fn(?float $v, int $dec = 0): string => $v === null ? '—' : $num($v, $dec) . ' Lei';
$pct = static fn(?float $v, int $dec = 1): string => $v === null ? '—' : $num($v, $dec) . '%';
$fix = static fn(?float $v, int $dec = 1): string => $v === null ? '—' : number_format($v, $dec, ',', '.');
$nopti = static fn(int $n): string => $n === 1 ? '1 noapte' : $n . ' nopți';
$day = static fn(string $d): string => date('j', strtotime($d)) . ' ' . Period::monthShort((int) date('n', strtotime($d)));
$channel = static fn(?string $k): string => OperationsReport::CHANNELS[$k ?? 'google']['label'] ?? 'Direct / altele';

/** Delta pill: relative % for money, percentage points for occupancy. */
$delta = static function (?float $now, ?float $before, string $vs, bool $points = false) use ($num): string {
    if ($now === null || $before === null || (!$points && abs($before) < 0.0001)) {
        return '<span class="delta">fără comparație</span>';
    }
    $d = $points ? $now - $before : ($now - $before) * 100 / abs($before);
    $cls = abs($d) < 0.05 ? '' : ($d > 0 ? ' is-up' : ' is-down');
    $txt = ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . $num(abs($d), 1) . ($points ? ' pp' : '%');
    return '<span class="delta' . $cls . '">' . h($txt . ' ' . $vs) . '</span>';
};
$qs = static fn(string $key): string => '/reports' . ($key === 'today' ? '' : '?p=' . rawurlencode($key));
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

  <div class="period-bar" data-period-bar>
    <div class="chips chips-teal" role="navigation" aria-label="Perioada raportului">
      <?php foreach (Period::PRESETS as $key => $label): ?>
        <a class="chip<?= $period->key === $key ? ' is-active' : '' ?>" href="<?= h($qs($key)) ?>" data-period-link><?= h($label) ?></a>
      <?php endforeach; ?>
      <button type="button" class="chip<?= $period->key === 'custom' ? ' is-active' : '' ?>" data-custom-toggle aria-expanded="false">Interval</button>
    </div>
    <form class="period-custom" method="get" action="/reports" data-custom-form hidden>
      <label class="field"><span class="label">De la</span><input class="input" type="date" name="from" value="<?= h($period->from) ?>" required></label>
      <label class="field"><span class="label">Până la</span><input class="input" type="date" name="to" value="<?= h($period->to) ?>" required></label>
      <button type="submit" class="btn btn-secondary btn-sm">Aplică</button>
    </form>
    <p class="period-label"><?= h($period->label) ?><?php if ($page && $page['compare']): ?> · <span class="faint"><?= h($period->compareLabel) ?></span><?php endif; ?></p>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert', 'icon icon-sm') ?><span><?= h($error) ?> Plata menajerelor funcționează în continuare.</span></div>
  <?php endif; ?>

  <div class="report-body stack" data-report-body>
  <?php if ($report): ?>
    <?php $t = $report['today']; $total = (int) $report['roster']['total']; ?>

    <!-- 1 · Operațional azi -->
    <h2 class="section-title">Operațional azi</h2>
    <div class="stat-grid stat-grid-ops">
      <div class="stat"><span class="stat-value tabular"><?= count($t['free']) ?><small>/<?= $total ?></small></span><span class="stat-label">Libere la noapte</span></div>
      <div class="stat"><span class="stat-value tabular"><?= (int) $t['occupied'] ?></span><span class="stat-label">Ocupate</span></div>
      <div class="stat"><span class="stat-value tabular"><?= (int) $t['checkIns'] ?></span><span class="stat-label">Check-in</span></div>
      <div class="stat"><span class="stat-value tabular"><?= (int) $t['checkOuts'] ?></span><span class="stat-label">Check-out</span></div>
    </div>
    <?php if (isset($t['arrivalsDone'])): ?>
      <div class="ops-line tabular">
        <span>În casă <strong><?= (int) $t['ongoing'] ?></strong></span>
        <span>Sosiri <strong><?= (int) $t['arrivalsDone'] ?>/<?= (int) $t['checkIns'] ?></strong></span>
        <span>Plecări <strong><?= (int) $t['departuresDone'] ?>/<?= (int) $t['checkOuts'] ?></strong></span>
      </div>
    <?php endif; ?>
    <?php if ($t['free']): ?>
      <div class="card stack-sm">
        <span class="label">Apartamente libere în seara asta</span>
        <div class="apt-chips">
          <?php foreach ($t['free'] as $apt): ?><span class="badge badge-teal tabular"><?= h($apt) ?></span><?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($page): $k = $page['kpis']; $c = $page['compare']; $vs = $page['compare'] ? $period->compareLabel : ''; ?>

    <!-- 2 · Indicatori -->
    <h2 class="section-title">Indicatori · <?= h($period->label) ?></h2>
    <div class="kpi-grid">
      <div class="kpi"><span class="kpi-value tabular"><?= h($pct($k['occupancy'])) ?></span><span class="kpi-label">Ocupare</span><?= $delta($k['occupancy'], $c['occupancy'] ?? null, $vs, true) ?></div>
      <div class="kpi"><span class="kpi-value tabular"><?= h($lei($k['adr'], 1)) ?></span><span class="kpi-label">ADR</span><?= $delta($k['adr'], $c['adr'] ?? null, $vs) ?></div>
      <div class="kpi"><span class="kpi-value tabular"><?= h($lei($k['revpar'], 1)) ?></span><span class="kpi-label">RevPAR</span><?= $delta($k['revpar'], $c['revpar'] ?? null, $vs) ?></div>
      <div class="kpi"><span class="kpi-value tabular"><?= h($lei($k['revenue'])) ?></span><span class="kpi-label">Venit<?= $page['vatRemoved'] ? ' fără TVA' : '' ?></span><?= $delta($k['revenue'], $c['revenue'] ?? null, $vs) ?></div>
    </div>
    <div class="kpi-secondary tabular">
      <span><strong><?= (int) $k['occupied'] ?></strong> nopți ocupate</span>
      <span><strong><?= (int) $k['available'] ?></strong> disponibile</span>
      <span><strong><?= (int) $k['guestNights'] ?></strong> <?= $k['nights'] === 1 ? 'oaspeți' : 'înnoptări oaspeți' ?></span>
      <?php if ($page['mtd']): $m = $page['mtd']; ?>
        <span>Până azi: <strong><?= h($pct($m['occupancy'])) ?></strong> · ADR <strong><?= h($lei($m['adr'], 1)) ?></strong> · <strong><?= h($lei($m['revenue'])) ?></strong></span>
      <?php endif; ?>
    </div>

    <?php
      $bars = $page['chart']['bars'];
      $weekly = count($bars) && $bars[0]['from'] !== $bars[0]['to'];
      $when = static fn(array $b): string => $b['from'] === $b['to'] ? $day($b['from']) : $day($b['from']) . ' – ' . $day($b['to']);
      $axis = static function () use ($bars, $page, $period, $day): string {
          if (!$bars) {
              return '';
          }
          $html = '<div class="row-between faint bars-axis axis-rel"><span>' . h($day($bars[0]['from'])) . '</span>';
          if ($period->isToday()) {
              $ti = array_search($page['today'], array_column($bars, 'from'), true);
              $html .= '<span class="axis-today" style="left:' . round((($ti === false ? 0 : $ti) + 0.5) * 100 / count($bars), 2) . '%">azi</span>';
          }
          return $html . '<span>' . h($day(end($bars)['to'])) . '</span></div>';
      };
      $maxRevBar = max(1.0, ...array_map(static fn(array $b): float => $b['revenue'], $bars ?: [['revenue' => 0.0]]));
      $chartRev = array_sum(array_column($bars, 'revenue'));
      $chartOcc = array_sum(array_column($bars, 'occupied'));
    ?>

    <!-- 3 · Ocupare -->
    <section class="rsec stack-sm">
    <h2 class="section-title">Ocupare<?= $weekly ? ' · pe săptămâni' : '' ?></h2>
    <div class="card stack-sm">
      <?php if ($period->isToday()): $occ = $report['occupancy']; ?>
        <div class="row-between">
          <div><span class="stat-value tabular"><?= (int) $occ['avgPast'] ?>%</span><span class="stat-label">ultimele 30 de nopți</span></div>
          <div class="text-right"><span class="stat-value stat-value-muted tabular"><?= (int) $occ['avgNext'] ?>%</span><span class="stat-label">următoarele <?= OperationsReport::NEXT_DAYS ?> (rezervat)</span></div>
        </div>
      <?php else: ?>
        <div><span class="stat-value tabular"><?= h($pct($k['occupancy'])) ?></span><span class="stat-label"><?= h($period->label) ?></span></div>
      <?php endif; ?>
      <?php if (!$bars): ?>
        <p class="muted">Nu am date Previo pentru intervalul ăsta.</p>
      <?php else: ?>
        <div class="bars" data-bars role="img" aria-label="Ocupare, <?= h($period->label) ?>">
          <?php foreach ($bars as $b):
            $tip = $when($b) . ' · ' . $b['occupied'] . '/' . $b['total'] . ' nopți · ' . $pct($b['pct'], 0)
                . ' · ADR ' . $lei($b['adr'], 1) . ' · RevPAR ' . $lei($b['revpar'], 1) . ' · ' . $lei($b['revenue']); ?>
            <button type="button" class="bar<?= $b['from'] > $page['today'] ? ' is-future' : '' ?><?= $b['from'] <= $page['today'] && $b['to'] >= $page['today'] ? ' is-today' : '' ?>" data-tip="<?= h($tip) ?>" title="<?= h($tip) ?>" aria-label="<?= h($tip) ?>">
              <span style="height:<?= max(2, (int) round($b['pct'])) ?>%"></span>
            </button>
          <?php endforeach; ?>
        </div>
        <?= $axis() ?>
        <p class="chart-tip tabular" data-chart-tip aria-live="polite">Atinge o bară pentru detalii.</p>
      <?php endif; ?>
    </div>
    </section>

    <!-- 4 · Venit -->
    <section class="rsec stack-sm">
    <h2 class="section-title">Venit<?= $weekly ? ' · pe săptămâni' : ' · pe nopți' ?></h2>
    <div class="card stack-sm">
      <div class="row-between">
        <div><span class="stat-value tabular"><?= h($lei($chartRev)) ?></span><span class="stat-label"><?= $period->isToday() ? '30 de nopți în urmă + 14 rezervate' : h($period->label) ?></span></div>
        <div class="text-right"><span class="stat-value stat-value-muted tabular"><?= h($lei($chartOcc ? $chartRev / $chartOcc : null)) ?></span><span class="stat-label">ADR mediu</span></div>
      </div>
      <?php if ($bars): ?>
        <div class="bars bars-blue" data-bars role="img" aria-label="Venit, <?= h($period->label) ?>">
          <?php foreach ($bars as $b):
            $tip = $when($b) . ' · venit ' . $lei($b['revenue']) . ' · ADR ' . $lei($b['adr'], 1) . ' · ' . $b['occupied'] . ' nopți'; ?>
            <button type="button" class="bar<?= $b['from'] > $page['today'] ? ' is-future' : '' ?><?= $b['from'] <= $page['today'] && $b['to'] >= $page['today'] ? ' is-today' : '' ?>" data-tip="<?= h($tip) ?>" title="<?= h($tip) ?>" aria-label="<?= h($tip) ?>">
              <span style="height:<?= max(2, (int) round($b['revenue'] * 100 / $maxRevBar)) ?>%"></span>
            </button>
          <?php endforeach; ?>
        </div>
        <?= $axis() ?>
        <p class="chart-tip tabular" data-chart-tip aria-live="polite">Atinge o bară pentru detalii.</p>
      <?php endif; ?>
    </div>
    </section>

    <!-- 5 · Canale -->
    <?php $ch = $page['channels'];
      $rows = array_filter($ch['rows'], static fn(array $r): bool => $r['reservations'] > 0);
      uasort($rows, static fn(array $x, array $y): int => $y['reservations'] <=> $x['reservations']); ?>
    <section class="rsec stack-sm">
    <h2 class="section-title">Canale · <?= h($page['wide']['label']) ?></h2>
    <div class="card stack-sm" data-channels>
      <?php if ($ch['total'] === 0): ?>
        <p class="muted">Niciun check-in în perioada asta.</p>
      <?php else: ?>
        <div class="segmented" role="radiogroup" aria-label="Metrica pentru canale">
          <?php foreach (['reservations' => 'Rezervări', 'nights' => 'Nopți', 'revenue' => 'Venit'] as $m => $label): ?>
            <input type="radio" name="ch-metric" id="ch-<?= $m ?>" value="<?= $m ?>"<?= $m === 'reservations' ? ' checked' : '' ?> data-channel-metric>
            <label for="ch-<?= $m ?>"><?= h($label) ?></label>
          <?php endforeach; ?>
        </div>
        <div class="channels">
          <div class="donut" data-donut role="img" aria-label="Distribuția pe canale">
            <span class="donut-hole"><strong class="tabular" data-donut-value><?= (int) $ch['total'] ?></strong><span data-donut-unit>rezervări</span></span>
          </div>
          <ul class="legend" data-legend>
            <?php foreach ($rows as $key => $row): ?>
              <li data-color="<?= h(OperationsReport::CHANNELS[$key]['color']) ?>" data-reservations="<?= (int) $row['reservations'] ?>" data-nights="<?= (int) $row['nights'] ?>" data-revenue="<?= h((string) round($row['revenue'], 2)) ?>">
                <span class="swatch" style="background:<?= h(OperationsReport::CHANNELS[$key]['color']) ?>"></span>
                <span class="grow"><span class="list-title"><?= h(OperationsReport::CHANNELS[$key]['label']) ?></span>
                  <span class="list-sub tabular"><?= (int) $row['reservations'] ?> rez. · <?= (int) $row['nights'] ?> nopți · <?= h($lei($row['revenue'])) ?></span></span>
                <strong class="tabular" data-share></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
    </section>

    <!-- 6 · ADR pe canal -->
    <?php $adrRows = array_filter($rows, static fn(array $r): bool => $r['adr'] !== null);
      uasort($adrRows, static fn(array $x, array $y): int => $y['adr'] <=> $x['adr']);
      $maxAdr = max(1.0, ...array_values(array_map(static fn(array $r): float => (float) $r['adr'], $adrRows ?: [['adr' => 0.0]]))); ?>
    <section class="rsec stack-sm">
    <h2 class="section-title">ADR pe canal · <?= h($page['wide']['label']) ?></h2>
    <div class="card">
      <?php if (!$adrRows): ?>
        <p class="muted">Nu am date Previo pentru intervalul ăsta.</p>
      <?php else: ?>
        <ul class="hbars">
          <?php foreach ($adrRows as $key => $row): ?>
            <li>
              <span class="hbar-label"><?= h(OperationsReport::CHANNELS[$key]['label']) ?></span>
              <span class="hbar-track"><span style="width:<?= round($row['adr'] * 100 / $maxAdr, 1) ?>%;background:<?= h(OperationsReport::CHANNELS[$key]['color']) ?>"></span></span>
              <strong class="hbar-value tabular"><?= h($lei($row['adr'])) ?></strong>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    </section>

    <!-- 7 · Zile ale săptămânii -->
    <?php $wk = $page['weekdays']; $bestWk = max(array_column($wk, 'pct') ?: [0]); ?>
    <section class="rsec stack-sm">
    <h2 class="section-title">Ocupare pe zile · <?= h($page['wide']['label']) ?></h2>
    <div class="card stack-sm">
      <div class="wk-bars" role="img" aria-label="Ocupare pe zilele săptămânii">
        <?php foreach ($wk as $w): ?>
          <div class="wk<?= $w['pct'] > 0 && $w['pct'] === $bestWk ? ' is-best' : '' ?>" title="<?= h($w['label'] . ' · ' . $w['occupied'] . '/' . $w['total'] . ' nopți · ADR ' . $lei($w['adr'], 1)) ?>">
            <span class="wk-pct tabular"><?= h($pct($w['pct'], 0)) ?></span>
            <span class="wk-col"><span style="height:<?= max(2, (int) round($w['pct'])) ?>%"></span></span>
            <span class="wk-label"><?= h($w['label']) ?></span>
            <span class="wk-adr tabular"><?= h($num($w['adr'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="faint wk-foot">Sub fiecare zi: ADR în Lei</p>
    </div>
    </section>

    <!-- 8 · Durata șederilor -->
    <?php $los = $page['stayLength']; $maxLos = max(1, ...array_values($los['buckets'])); ?>
    <section class="rsec stack-sm">
    <h2 class="section-title">Durata șederilor · <?= h($page['wide']['label']) ?></h2>
    <div class="card stack-sm">
      <?php if ($los['reservations'] === 0): ?>
        <p class="muted">Nu am date Previo pentru intervalul ăsta.</p>
      <?php else: ?>
        <div class="row-between">
          <div><span class="stat-value tabular"><?= h($num($los['average'], 1)) ?></span><span class="stat-label">nopți în medie</span></div>
          <div class="text-right"><span class="stat-value stat-value-muted tabular"><?= (int) $los['reservations'] ?></span><span class="stat-label">rezervări</span></div>
        </div>
        <ul class="hbars">
          <?php foreach ($los['buckets'] as $label => $n): if ($label === 'câteva ore' && $n === 0) continue; ?>
            <li>
              <span class="hbar-label"><?= h(is_numeric($label) || str_contains($label, '–') || str_contains($label, '+') ? $label . ((string) $label === '1' ? ' noapte' : ' nopți') : $label) ?></span>
              <span class="hbar-track"><span style="width:<?= round($n * 100 / $maxLos, 1) ?>%"></span></span>
              <strong class="hbar-value tabular"><?= (int) $n ?></strong>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    </section>

    <!-- 9 · Apartamente -->
    <?php $aptRows = $page['apartments']; $maxAptRev = max(1.0, ...array_map(static fn(array $r): float => $r['kpis']['revenue'], $aptRows ?: [['kpis' => ['revenue' => 0.0]]])); ?>
    <h2 class="section-title">Apartamente · <?= h($period->label) ?></h2>
    <div class="card apt-table" data-apt-table>
      <?php if (!$aptRows): ?>
        <p class="muted apt-empty">Niciun apartament ocupat în perioada asta.</p>
      <?php else: ?>
        <div class="apt-row apt-head" aria-hidden="true">
          <span>Ap.</span><span>Venit</span><span class="desk">Ocupare</span><span class="desk">Nopți</span><span class="desk">ADR</span><span class="desk">RevPAR</span><span class="desk">Canal principal</span>
        </div>
        <?php foreach ($aptRows as $row): $ak = $row['kpis']; ?>
          <button type="button" class="apt-row" data-apt="<?= h($row['apartment']) ?>" aria-label="Detalii apartament <?= h($row['apartment']) ?>">
            <span class="apt-no tabular"><?= h($row['apartment']) ?></span>
            <span class="occ-cell"><span class="occ-track rev-track"><span style="width:<?= round($ak['revenue'] * 100 / $maxAptRev, 1) ?>%"></span></span><strong class="tabular"><?= h($lei($ak['revenue'])) ?></strong></span>
            <span class="desk tabular"><?= h($pct($ak['occupancy'], 0)) ?></span>
            <span class="desk tabular"><?= (int) $ak['occupied'] ?></span>
            <span class="desk tabular"><?= h($lei($ak['adr'])) ?></span>
            <span class="desk tabular"><?= h($lei($ak['revpar'])) ?></span>
            <span class="desk"><?= $row['channel'] ? h($channel($row['channel'])) : '—' ?></span>
            <span class="mob-sub tabular">ocupare <?= h($pct($ak['occupancy'], 0)) ?> · <?= h($nopti((int) $ak['occupied'])) ?> · ADR <?= h($lei($ak['adr'])) ?><?= $row['channel'] ? ' · ' . h($channel($row['channel'])) : '' ?></span>
          </button>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php foreach ($page['details'] as $apt => $d): ?>
      <template data-apt-detail="<?= h((string) $apt) ?>">
        <div class="sheet-head row-between">
          <h2>Apartament <?= h((string) $apt) ?></h2>
          <button type="button" class="icon-btn" data-sheet-close aria-label="Închide"><?= icon('x') ?></button>
        </div>
        <div class="sheet-windows">
          <?php foreach ($d['windows'] as $label => $w): if ($w['total'] === 0) continue; ?>
            <div class="sheet-window">
              <span class="label"><?= h($label) ?></span>
              <div class="sheet-kpis tabular">
                <span><strong><?= h($pct($w['occupancy'], 0)) ?></strong>ocupare</span>
                <span><strong><?= h($lei($w['adr'])) ?></strong>ADR</span>
                <span><strong><?= h($lei($w['revpar'])) ?></strong>RevPAR</span>
                <span><strong><?= h($lei($w['revenue'])) ?></strong>venit</span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <span class="label">Ultimele 30 de nopți</span>
        <div class="mini-bars" role="img" aria-label="Nopți ocupate în ultimele 30">
          <?php foreach ($d['nights'] as $n): ?><span class="<?= $n['occupied'] ? 'is-on' : '' ?>" title="<?= h($day($n['date']) . ($n['occupied'] ? ' · ocupat' : ' · liber')) ?>"></span><?php endforeach; ?>
        </div>
        <?php if ($d['recent']): ?>
          <span class="label">Ultimele rezervări</span>
          <ul class="sheet-list">
            <?php foreach ($d['recent'] as $r): ?>
              <li>
                <span class="swatch" style="background:<?= h(OperationsReport::CHANNELS[$r['platform']]['color'] ?? '#7c3aed') ?>"></span>
                <span class="grow"><span class="list-title"><?= h($day($r['checkIn'])) ?> – <?= h($day($r['checkOut'])) ?></span>
                  <span class="list-sub"><?= h($channel($r['platform'])) ?> · <?= $r['nights'] ? h($nopti((int) $r['nights'])) : 'câteva ore' ?><?= $r['option'] ? ' · plată la check-out' : '' ?></span></span>
                <strong class="tabular"><?= h($lei($r['revenue'])) ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </template>
    <?php endforeach; ?>

    <!-- 10 · Trend lunar -->
    <?php
      $mo = $page['monthly'];
      $activeMonths = array_values(array_filter($mo['months'], static fn(array $m): bool => $m['kpis']['total'] > 0));
      $W = 360; $H = 150; $pad = 18; $cnt = max(1, count($activeMonths)); $colW = ($W - 2 * $pad) / $cnt;
      $maxRev = max(1.0, ...array_map(static fn(array $m): float => $m['kpis']['revenue'], $activeMonths ?: [['kpis' => ['revenue' => 0.0]]]));
      $maxMoney = max(1.0, ...array_map(static fn(array $m): float => (float) ($m['kpis']['adr'] ?? 0), $activeMonths ?: [['kpis' => ['adr' => 0.0]]]));
      $xAt = static fn(int $i): float => $pad + $colW * ($i + 0.5);
      $line = static function (string $key, float $max) use ($activeMonths, $xAt, $H): string {
          $pts = [];
          foreach ($activeMonths as $i => $m) {
              $pts[] = round($xAt($i), 1) . ',' . round($H - 20 - (((float) ($m['kpis'][$key] ?? 0)) / $max) * ($H - 34), 1);
          }
          return implode(' ', $pts);
      };
    ?>
    <h2 class="section-title">Trend lunar · <?= (int) date('Y') ?></h2>
    <div class="trend-grid">
      <div class="card stack-sm">
        <div class="row faint trend-legend"><span><i class="lg-col"></i>venit</span><span><i class="lg-line"></i>ocupare %</span></div>
        <svg class="trend" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Venit lunar și ocupare">
          <?php foreach ($activeMonths as $i => $m): $h = ($m['kpis']['revenue'] / $maxRev) * ($H - 34); ?>
            <rect class="trend-col<?= $m['current'] ? ' is-current' : '' ?>" x="<?= round($pad + $colW * $i + 3, 1) ?>" y="<?= round($H - 20 - $h, 1) ?>" width="<?= round(max(2, $colW - 6), 1) ?>" height="<?= round(max(0, $h), 1) ?>" rx="3">
              <title><?= h(Period::monthShort($m['month']) . ': ' . $lei($m['kpis']['revenue']) . ' · ' . $pct($m['kpis']['occupancy'])) ?></title>
            </rect>
            <text class="trend-axis" x="<?= round($xAt($i), 1) ?>" y="<?= $H - 5 ?>" text-anchor="middle"><?= h(Period::monthShort($m['month'])) ?></text>
          <?php endforeach; ?>
          <polyline class="trend-line" points="<?= h($line('occupancy', 100.0)) ?>"/>
        </svg>
      </div>
      <div class="card stack-sm">
        <div class="row faint trend-legend"><span><i class="lg-line"></i>ADR</span><span><i class="lg-line lg-teal"></i>RevPAR</span></div>
        <svg class="trend" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="ADR și RevPAR lunar">
          <?php foreach ($activeMonths as $i => $m): ?>
            <text class="trend-axis" x="<?= round($xAt($i), 1) ?>" y="<?= $H - 5 ?>" text-anchor="middle"><?= h(Period::monthShort($m['month'])) ?></text>
          <?php endforeach; ?>
          <polyline class="trend-line" points="<?= h($line('adr', $maxMoney)) ?>"/>
          <polyline class="trend-line trend-line-teal" points="<?= h($line('revpar', $maxMoney)) ?>"/>
          <?php foreach ($activeMonths as $i => $m): ?>
            <circle class="trend-dot" cx="<?= round($xAt($i), 1) ?>" cy="<?= round($H - 20 - (((float) ($m['kpis']['adr'] ?? 0)) / $maxMoney) * ($H - 34), 1) ?>" r="2.6"><title><?= h(Period::monthShort($m['month']) . ': ADR ' . $lei($m['kpis']['adr'], 1) . ' · RevPAR ' . $lei($m['kpis']['revpar'], 1)) ?></title></circle>
          <?php endforeach; ?>
        </svg>
      </div>
    </div>
    <div class="card">
      <div class="table-scroll">
        <table class="trend-table tabular">
          <thead><tr><th>Luna</th><th>RN</th><th>OCC</th><th>ADR</th><th>RevPAR</th><th>Venit</th></tr></thead>
          <tbody>
            <?php foreach ($activeMonths as $m): $mk = $m['kpis']; ?>
              <tr class="<?= $m['current'] ? 'is-current' : '' ?>">
                <th><?= h(Period::monthShort($m['month'])) ?><?= $m['partial'] ? ' <span class="badge badge-teal">parțial</span>' : '' ?></th>
                <td><?= (int) $mk['occupied'] ?></td><td><?= h($fix($mk['occupancy'])) ?>%</td>
                <td><?= h($fix($mk['adr'])) ?></td><td><?= h($fix($mk['revpar'])) ?></td><td><?= h($num($mk['revenue'])) ?></td>
              </tr>
            <?php endforeach; $tt = $mo['total']; ?>
          </tbody>
          <tfoot><tr><th>Total</th><td><?= (int) $tt['occupied'] ?></td><td><?= h($fix($tt['occupancy'])) ?>%</td><td><?= h($fix($tt['adr'])) ?></td><td><?= h($fix($tt['revpar'])) ?></td><td><?= h($num($tt['revenue'])) ?></td></tr></tfoot>
        </table>
      </div>
    </div>

    <!-- 11 · Oaspeți -->
    <?php $g = $page['guests']; $gTotal = max(1, $g['domestic'] + $g['foreign'] + $g['unknown']); ?>
    <section class="rsec stack-sm">
    <h2 class="section-title">Oaspeți · <?= h($period->label) ?></h2>
    <div class="card stack-sm">
      <?php if ($g['reservations'] === 0): ?>
        <p class="muted">Nu am date Previo pentru intervalul ăsta.</p>
      <?php else: ?>
        <div class="row-between">
          <div><span class="stat-value tabular"><?= (int) $g['guests'] ?></span><span class="stat-label">oaspeți</span></div>
          <div class="text-right"><span class="stat-value stat-value-muted tabular"><?= (int) $g['reservations'] ?></span><span class="stat-label">rezervări</span></div>
        </div>
        <div class="mix-bar" role="img" aria-label="Domestici <?= (int) $g['domestic'] ?>, străini <?= (int) $g['foreign'] ?>">
          <span class="mix-dom" style="width:<?= round($g['domestic'] * 100 / $gTotal, 1) ?>%"></span>
          <span class="mix-for" style="width:<?= round($g['foreign'] * 100 / $gTotal, 1) ?>%"></span>
          <span class="mix-unk" style="width:<?= round($g['unknown'] * 100 / $gTotal, 1) ?>%"></span>
        </div>
        <div class="row faint mix-legend tabular">
          <span><i class="mix-dom"></i>domestici <?= (int) $g['domestic'] ?></span>
          <span><i class="mix-for"></i>străini <?= (int) $g['foreign'] ?></span>
          <?php if ($g['unknown'] > 0): ?><span><i class="mix-unk"></i>fără țară <?= (int) $g['unknown'] ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    </section>
  <?php endif; ?>
  </div>

  <dialog class="sheet" data-apt-sheet aria-label="Detalii apartament"><div class="sheet-body stack-sm" data-sheet-body></div></dialog>
</div>
