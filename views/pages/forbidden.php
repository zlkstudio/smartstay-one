<?php /** @var ?array $user */ ?>
<div class="card empty">
  <span class="tile-icon"><?= icon('lock', 'icon icon-lg') ?></span>
  <h2>Nu ai acces la această secțiune</h2>
  <p>Dacă ai nevoie de ea, cere administratorului să îți activeze modulul.</p>
  <a class="btn btn-secondary" style="margin-top:20px" href="<?= h($user ? One\Auth\Access::homePath($user) : '/login') ?>">Înapoi</a>
</div>
