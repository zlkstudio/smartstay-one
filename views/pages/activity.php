<?php
/**
 * Jurnal activitate (admin) + push pe acest dispozitiv.
 * @var array $user @var string $filter @var list<array{title:string,body:string,url:string,at:string,action:string}> $rows
 * @var array{configured:bool,publicKey:string,devices:int} $push
 */
use One\Notify\Activity;

$day = null;
$today = local_time(utc_now(), 'Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
?>
<div class="stack">
  <div class="card stack-sm" data-push
       data-configured="<?= $push['configured'] ? '1' : '0' ?>"
       data-key="<?= h($push['publicKey']) ?>"
       data-devices="<?= (int) $push['devices'] ?>">
    <div class="row">
      <span class="tile-icon"><?= icon('bell') ?></span>
      <div class="grow">
        <div class="card-title">Notificări push</div>
        <div class="list-sub" data-push-status>Se verifică…</div>
      </div>
    </div>
    <p class="faint push-help">Checklist-uri trimise și orice modificare în Inventar ajung ca notificare pe telefoanele adminilor. Tu nu primești notificări pentru propriile acțiuni.</p>
    <div class="push-actions">
      <button type="button" class="btn btn-primary btn-sm" data-push-on hidden><?= icon('bell') ?><span>Activează pe acest telefon</span></button>
      <button type="button" class="btn btn-secondary btn-sm" data-push-test hidden><?= icon('send') ?><span>Trimite un test</span></button>
      <button type="button" class="btn btn-ghost btn-sm" data-push-off hidden>Dezactivează</button>
    </div>
  </div>

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
