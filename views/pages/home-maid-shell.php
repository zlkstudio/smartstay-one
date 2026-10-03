<?php
/**
 * Acasă · Menajeră — shell trimis imediat: salut + schelet. Ziua (Previo, alocări, inventar)
 * vine din /home/body și înlocuiește tot blocul (app.js, [data-defer]).
 * @var array $user @var string $src
 */
$firstName = explode(' ', trim($user['name']))[0];
?>
<div class="stack" data-defer="<?= h($src) ?>" aria-busy="true">
  <?php if (isset($_GET['welcome'])): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Parola e setată. Bun venit în SmartStay ONE!</span></div>
  <?php endif; ?>

  <div class="hero">
    <div class="eyebrow"><?= h(ro_date()) ?></div>
    <h1><?= h(greeting()) ?>, <?= h($firstName) ?></h1>
    <p data-defer-status>Se încarcă ziua ta…</p>
  </div>

  <div class="stat-grid">
    <?php for ($i = 0; $i < 4; $i++): ?><div class="stat stat-skel"><span class="skeleton" style="width:35%;height:26px"></span><span class="skeleton" style="width:70%;height:13px"></span></div><?php endfor; ?>
  </div>

  <h2 class="section-title">Azi</h2>
  <div class="list">
    <?php for ($i = 0; $i < 2; $i++): ?>
      <div class="list-item"><span class="skeleton" style="width:56px;height:56px;border-radius:14px"></span>
        <span class="grow stack-sm"><span class="skeleton" style="width:55%;height:16px"></span><span class="skeleton" style="width:35%;height:13px"></span></span></div>
    <?php endfor; ?>
  </div>

  <h2 class="section-title">Mâine</h2>
  <div class="card stack-sm"><span class="skeleton" style="width:60%;height:16px"></span><span class="skeleton" style="width:40%;height:13px"></span></div>
</div>
