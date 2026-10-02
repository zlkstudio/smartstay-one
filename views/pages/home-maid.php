<?php
/**
 * Acasă · Menajeră — overview-ul zilei. Fără nume de oaspeți, fără prețuri pe apartament.
 * @var array $user @var list<array{apartment:string, done:bool, out:?string, in:?string}> $mine
 * @var list<string> $free @var list<array{apartment:string, out:string, in:?string}> $tomorrow
 * @var bool $previoOk @var ?array{count:int, total:int} $week @var ?list<string> $critical @var ?string $error
 */
$firstName = explode(' ', trim($user['name']))[0];
$done = count(array_filter($mine, static fn(array $r): bool => $r['done']));
$left = count($mine) - $done;
$sameDay = static fn(array $list): int => count(array_filter($list, static fn(array $r): bool => !empty($r['in'])));
$money = static fn(int $v): string => number_format($v, 0, ',', '.') . ' RON';
?>
<div class="stack">
  <?php if (isset($_GET['welcome'])): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Parola e setată. Bun venit în SmartStay ONE!</span></div>
  <?php endif; ?>

  <div class="hero">
    <div class="eyebrow"><?= h(ro_date()) ?></div>
    <h1><?= h(greeting()) ?>, <?= h($firstName) ?></h1>
    <p><?php if (!$mine): ?>Nu ai încă apartamente azi.<?php elseif ($left === 0): ?>Ai terminat tot pe azi. Mulțumim!<?php else: ?>Mai ai <?= $left ?> <?= $left === 1 ? 'apartament' : 'apartamente' ?> de făcut azi.<?php endif; ?></p>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert', 'icon icon-sm') ?><span><?= h($error) ?></span></div>
  <?php endif; ?>

  <div class="stat-grid">
    <a class="stat card-link" href="/housekeeping"><span class="stat-value tabular"><?= count($mine) ?></span><span class="stat-label">Ale tale azi</span></a>
    <a class="stat card-link" href="/housekeeping"><span class="stat-value tabular"><?= $done ?></span><span class="stat-label">Făcute azi</span></a>
    <a class="stat card-link" href="/housekeeping"><span class="stat-value tabular"><?= $previoOk ? count($free) : '—' ?></span><span class="stat-label">Libere de preluat</span></a>
    <a class="stat card-link" href="#maine"><span class="stat-value tabular"><?= $previoOk ? count($tomorrow) : '—' ?></span><span class="stat-label">Check-out mâine</span></a>
  </div>

  <h2 class="section-title">Azi</h2>
  <?php if ($mine): ?>
    <div class="list">
      <?php foreach ($mine as $r): ?>
        <a class="list-item" href="<?= $r['done'] ? '/housekeeping' : '/housekeeping/checklist/' . rawurlencode($r['apartment']) ?>">
          <span class="apt-badge apt-badge-violet"><span>Apt</span><strong><?= h($r['apartment']) ?></strong></span>
          <span class="grow">
            <span class="list-title">Apartament <?= h($r['apartment']) ?></span><br>
            <span class="list-sub"><?= $r['out'] ? 'Check-out ' . h($r['out']) : 'Alocat azi' ?><?= $r['in'] ? ' · <strong>sosire ' . h($r['in']) . '</strong>' : '' ?></span>
          </span>
          <?= $r['done']
              ? '<span class="badge badge-success">' . icon('check', 'icon icon-sm') . 'Gata</span>'
              : ($r['in'] ? '<span class="badge badge-warning">Prioritar</span>' : '<span class="badge badge-violet">De făcut</span>') ?>
          <?= icon('chevron', 'icon icon-sm chev') ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <a href="/housekeeping" class="card card-link row">
      <span class="tile-icon" data-module="housekeeping"><?= icon('housekeeping') ?></span>
      <span class="grow"><span class="list-title">Niciun apartament alocat ție azi</span><br>
        <span class="list-sub"><?= $free ? 'Preia din cele libere, în Curățenie.' : 'Verifică mai târziu în Curățenie.' ?></span></span>
      <?= icon('chevron', 'icon icon-sm chev') ?>
    </a>
  <?php endif; ?>

  <?php if ($free): ?>
    <a href="/housekeeping" class="card card-link stack-sm">
      <span class="row-between"><span class="list-title"><?= count($free) ?> <?= count($free) === 1 ? 'apartament liber' : 'apartamente libere' ?> de preluat</span><?= icon('chevron', 'icon icon-sm chev') ?></span>
      <span class="free-chips"><?php foreach ($free as $apt): ?><span class="free-chip"><?= h($apt) ?></span><?php endforeach; ?></span>
    </a>
  <?php endif; ?>

  <h2 class="section-title" id="maine">Mâine</h2>
  <?php if (!$previoOk): ?>
    <div class="alert alert-warning"><?= icon('alert', 'icon icon-sm') ?><span>Rezervările din Previo nu s-au putut încărca acum. Reîncarcă pagina peste un minut.</span></div>
  <?php elseif ($tomorrow): ?>
    <div class="list">
      <div class="list-item"><span class="grow list-sub">
        <?= count($tomorrow) ?> check-out<?= count($tomorrow) === 1 ? '' : '-uri' ?><?= $sameDay($tomorrow) ? ' · ' . $sameDay($tomorrow) . ' cu sosire în aceeași zi' : '' ?>
      </span></div>
      <?php foreach ($tomorrow as $r): ?>
        <div class="list-item">
          <span class="apt-badge"><span>Apt</span><strong><?= h($r['apartment']) ?></strong></span>
          <span class="grow">
            <span class="list-title">Check-out <?= h($r['out']) ?></span><br>
            <span class="list-sub"><?= $r['in'] ? 'Sosește cineva la ' . h($r['in']) : 'Fără sosire în aceeași zi' ?></span>
          </span>
          <?php if ($r['in']): ?><span class="badge badge-warning">Prioritar</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card row"><span class="dot dot-ok"></span><span class="grow list-sub">Niciun check-out mâine.</span></div>
  <?php endif; ?>

  <h2 class="section-title">Săptămâna asta</h2>
  <a href="/reports/payments?preset=this_week" class="card card-link row">
    <span class="tile-icon" data-module="reports"><?= icon('reports') ?></span>
    <span class="grow">
      <?php if ($week !== null): ?>
        <span class="list-title tabular"><?= (int) $week['count'] ?> <?= $week['count'] === 1 ? 'curățenie' : 'curățenii' ?> · <?= h($money((int) $week['total'])) ?></span><br>
        <span class="list-sub">Vezi toate curățeniile tale</span>
      <?php else: ?>
        <span class="list-title">Curățeniile mele</span><br><span class="list-sub">Raportul tău pe perioade</span>
      <?php endif; ?>
    </span>
    <?= icon('chevron', 'icon icon-sm chev') ?>
  </a>

  <?php if ($critical !== null): ?>
    <h2 class="section-title">Inventar</h2>
    <a href="/inventory?filter=critical" class="card card-link row">
      <span class="dot <?= $critical ? 'dot-warn' : 'dot-ok' ?>"></span>
      <span class="grow">
        <span class="list-title"><?= $critical ? count($critical) . ' apartamente cu lenjerii pe roșu' : 'Stocul de lenjerii e în regulă' ?></span><br>
        <span class="list-sub"><?= $critical ? h(implode(', ', $critical)) : 'Niciun apartament pe roșu' ?></span>
      </span>
      <?= icon('chevron', 'icon icon-sm chev') ?>
    </a>
  <?php endif; ?>
</div>
