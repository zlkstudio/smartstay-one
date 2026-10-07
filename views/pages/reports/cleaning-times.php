<?php
/**
 * Setări · Timpi curățenie (doar admin) — durata reală (din Nuki) față de țintă, pe nopți și studio / apartament.
 * @var string $from @var string $to @var ?string $preset @var ?array $data @var ?string $error
 */
use One\Controllers\ReportsController;
use One\Housekeeping\CleaningTracker;

$fmt = static fn(string $d): string => date('d.m', strtotime($d));
$min = static fn(?float $v): string => $v === null ? '—' : number_format($v, $v == floor($v) ? 0 : 1, ',', '.');
$unitLabel = static fn(string $u): string => $u === 'studio' ? 'Studio' : 'Apartament';
$nightsLabel = static fn(?int $n): string => $n === null ? '? nopți' : ($n === 1 ? '1 noapte' : "$n nopți");
?>
<div class="stack" data-reports data-can-edit="0">
  <div class="hero">
    <div class="eyebrow"><?= h($preset ? ReportsController::TIMES_PRESETS[$preset] : 'Perioadă aleasă') ?></div>
    <h1>Timpi curățenie</h1>
    <p class="tabular"><?= h($fmt($from)) ?> – <?= h(date('d.m.Y', strtotime($to))) ?></p>
  </div>

  <div class="chips chips-teal" role="group" aria-label="Perioadă">
    <?php foreach (ReportsController::TIMES_PRESETS as $key => $label): ?>
      <a class="chip<?= $key === $preset ? ' is-active' : '' ?>" href="/settings/cleaning-times?preset=<?= h($key) ?>"<?= $key === $preset ? ' aria-current="true"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <details class="card period-card"<?= $preset === null ? ' open' : '' ?>>
    <summary class="row-between"><span class="list-title">Altă perioadă</span><?= icon('chevron', 'icon icon-sm chev') ?></summary>
    <form method="get" action="/settings/cleaning-times" class="period-form">
      <label class="field"><span class="label">De la</span><input class="input" type="date" name="from" value="<?= h($from) ?>" required></label>
      <label class="field"><span class="label">Până la</span><input class="input" type="date" name="to" value="<?= h($to) ?>" required></label>
      <button type="submit" class="btn btn-teal btn-sm">Afișează</button>
    </form>
  </details>

  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert', 'icon icon-sm') ?><span><?= h($error) ?></span></div>
  <?php endif; ?>

  <?php if ($data): $t = $data['total']; ?>
    <?php if ($t['count'] === 0): ?>
      <div class="card empty">
        <span class="tile-icon" data-module="reports"><?= icon('reports', 'icon icon-lg') ?></span>
        <h2>Nicio curățenie cronometrată</h2>
        <p><?= $data['since'] === null
            ? 'Cronometrul pornește singur când o menajeră descuie cu codul ei („Ioana Menaj”, „Cristina Menaj”) un apartament cu check-out și se oprește la încuiere.'
            : 'Nu există curățenii încheiate între ' . h($fmt($from)) . ' și ' . h($fmt($to)) . '. Date colectate din ' . h(date('d.m.Y', strtotime($data['since']))) . '.' ?></p>
      </div>
    <?php else: ?>
      <div class="stat-grid stat-grid-2">
        <div class="stat"><span class="stat-value tabular"><?= (int) $t['count'] ?></span><span class="stat-label">Curățenii cronometrate</span></div>
        <div class="stat"><span class="stat-value tabular"><?= h($min($t['avg'])) ?> min</span><span class="stat-label">Durata medie</span></div>
        <div class="stat"><span class="stat-value tabular"><?= 100 - (int) $t['overPct'] ?>%</span><span class="stat-label">În țintă</span></div>
        <div class="stat"><span class="stat-value tabular"><?= h($min($t['median'])) ?> min</span><span class="stat-label">Median</span></div>
      </div>

      <h2 class="section-title">Pe nopți de ședere</h2>
      <div class="card">
        <div class="table-scroll">
          <table class="trend-table tabular">
            <thead><tr><th>Ședere</th><th>Țintă</th><th>Nr.</th><th>Medie</th><th>Median</th><th>Peste</th><th>Propus</th></tr></thead>
            <tbody>
              <?php foreach ($data['matrix'] as $row): ?>
                <tr>
                  <th><?= h($row['label']) ?> <span class="badge <?= $row['unit'] === 'studio' ? 'badge-violet' : 'badge-teal' ?>"><?= h($unitLabel($row['unit'])) ?></span></th>
                  <td><?= (int) $row['target'] ?></td>
                  <td><?= (int) $row['count'] ?: '—' ?></td>
                  <td><?= h($min($row['avg'])) ?></td>
                  <td><?= h($min($row['median'])) ?></td>
                  <td><?= $row['count'] ? (int) $row['overPct'] . '%' : '—' ?></td>
                  <td><?php if ($row['suggest'] !== null): ?><strong><?= (int) $row['suggest'] ?></strong><?php if ($row['suggest'] !== $row['target']): ?> <span class="badge <?= $row['suggest'] > $row['target'] ? 'badge-warning' : 'badge-success' ?>"><?= $row['suggest'] > $row['target'] ? '+' : '−' ?><?= abs($row['suggest'] - $row['target']) ?></span><?php endif; ?><?php else: ?>—<?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint">Minute. „Propus” = medianul rotunjit în sus la 5 min, doar de la 5 curățenii în grupă — baza pentru ajustarea țintelor.</p>
      </div>

      <?php if (count($data['maids']) > 0): ?>
        <h2 class="section-title">Pe menajeră</h2>
        <div class="card">
          <div class="table-scroll">
            <table class="trend-table tabular" style="min-width:0">
              <thead><tr><th>Menajera</th><th>Nr.</th><th>Medie</th><th>Median</th><th>Peste țintă</th></tr></thead>
              <tbody>
                <?php foreach ($data['maids'] as $m): ?>
                  <tr><th><?= h($m['name']) ?></th><td><?= (int) $m['count'] ?></td><td><?= h($min($m['avg'])) ?></td><td><?= h($min($m['median'])) ?></td><td><?= (int) $m['overPct'] ?>%</td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <h2 class="section-title">Ultimele curățenii</h2>
      <ul class="list ct-list">
        <?php foreach ($data['sessions'] as $s): $diff = $s['minutes'] - $s['target']; ?>
          <li class="list-item ct-line">
            <span class="pay-date tabular"><span><?= h($fmt($s['date'])) ?></span><?= h($s['from']) ?></span>
            <span class="grow">
              <span class="list-title tabular">Apt <?= h($s['apartment']) ?> · <?= h($s['maid']) ?></span>
              <span class="list-sub"><?= h($nightsLabel($s['nights'])) ?> · <?= h(mb_strtolower($unitLabel($s['unit']))) ?> · <?= h($s['from']) ?>–<?= h($s['to']) ?></span>
            </span>
            <span class="ct-time tabular">
              <strong><?= (int) $s['minutes'] ?> min</strong>
              <span class="badge <?= $diff > 0 ? 'badge-warning' : 'badge-success' ?>"><?= $diff > 0 ? '+' . $diff : 'țintă ' . (int) $s['target'] ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php
    $notes = [];
    if ($data['open']) { $notes[] = $data['open'] . ' în curs / fără încuiere detectată'; }
    if ($data['outliers']) { $notes[] = $data['outliers'] . ' excluse (sub 5 min sau peste 4 ore)'; }
    if ($data['unknownStay']) { $notes[] = $data['unknownStay'] . ' fără număr de nopți (doar în total)'; }
    if ($data['since'] !== null) { $notes[] = 'date colectate din ' . date('d.m.Y', strtotime($data['since'])); }
    ?>
    <?php if ($notes): ?><p class="hint"><?= h(ucfirst(implode(' · ', $notes))) ?>.</p><?php endif; ?>
    <p class="hint">Ținte: <?php
      $parts = [];
      foreach (CleaningTracker::TARGETS as $max => $tg) {
          $parts[] = ($max === 1 ? '1 noapte' : ($max === PHP_INT_MAX ? '6+ nopți' : ($max - 1) . '–' . $max . ' nopți')) . " {$tg['studio']}/{$tg['apartment']}";
      }
      echo h(implode(' · ', $parts));
    ?> min (studio/apartament).</p>
  <?php endif; ?>
</div>
