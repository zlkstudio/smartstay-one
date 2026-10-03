<?php
/**
 * Jurnal activitate (admin) + push pe acest dispozitiv.
 * @var array $user @var string $filter @var list<array{title:string,body:string,url:string,at:string,action:string}> $rows
 */
use One\Notify\Activity;

$day = null;
$today = local_time(utc_now(), 'Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
?>
<div class="stack">
  <?php require ONE_ROOT . '/views/partials/push-card.php'; ?>

  <nav class="chips" aria-label="Filtru jurnal">
    <?php foreach (Activity::FILTERS as $key => [$label]): ?>
      <a class="chip<?= $key === $filter ? ' is-active' : '' ?>" href="/activity<?= $key === 'all' ? '' : '?f=' . h($key) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <div class="card empty"><h2>Nimic încă</h2><p>Aici apar checklist-urile, modificările din Inventar și celelalte acțiuni ale echipei.</p></div>
  <?php endif; ?>

  <?php foreach ($rows as $row):
      $d = local_time($row['at'], 'Y-m-d');
      if ($d !== $day):
          if ($day !== null) echo '</div>';
          $day = $d;
          $label = $d === $today ? 'Azi' : ($d === $yesterday ? 'Ieri' : local_time($row['at'], 'd.m.Y'));
  ?>
    <h2 class="section-title"><?= h($label) ?></h2>
    <div class="list">
  <?php endif; ?>
      <a class="list-item activity-item" href="<?= h($row['url']) ?>">
        <span class="activity-dot" data-kind="<?= h(explode('.', $row['action'])[0]) ?>"></span>
        <span class="grow">
          <span class="list-title"><?= h($row['title']) ?></span>
          <span class="activity-body"><?= h($row['body']) ?></span>
        </span>
        <span class="faint tabular activity-time"><?= h(local_time($row['at'], 'H:i')) ?></span>
      </a>
  <?php endforeach; ?>
  <?php if ($day !== null) echo '</div>'; ?>
</div>
