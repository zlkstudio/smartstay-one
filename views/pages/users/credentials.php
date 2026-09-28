<?php
/** Shown once, right after create/reset. The temporary password is never stored in clear or shown again.
 * @var array $target @var string $password @var string $reason @var string $loginUrl */
$login = $target['email'] ?? ('+' . $target['phone']);
$first = explode(' ', trim($target['name']))[0];
$message = "Bună, $first! Contul tău SmartStay ONE:\n"
    . "$loginUrl\n"
    . "Utilizator: $login\n"
    . "Parolă temporară: $password\n"
    . "La prima intrare îți alegi parola ta.";
?>
<div class="stack">
  <div class="alert alert-success" role="status">
    <?= icon('check', 'icon icon-sm') ?>
    <span><?= $reason === 'created' ? 'Contul pentru <strong>' . h($target['name']) . '</strong> a fost creat.' : 'Parola pentru <strong>' . h($target['name']) . '</strong> a fost resetată.' ?></span>
  </div>

  <div class="card stack">
    <div>
      <div class="eyebrow">Utilizator</div>
      <div class="credential" style="margin-top:6px"><code style="font-size:16px"><?= h($login) ?></code></div>
    </div>
    <div>
      <div class="eyebrow">Parolă temporară</div>
      <div class="credential" style="margin-top:6px">
        <code><?= h($password) ?></code>
        <button type="button" class="icon-btn" data-copy="<?= h($password) ?>" aria-label="Copiază parola"><?= icon('copy') ?></button>
      </div>
    </div>
    <div class="alert alert-warning"><?= icon('alert', 'icon icon-sm') ?>
      <span>Parola apare doar acum. La prima intrare, <?= h($first) ?> e obligat să își aleagă una nouă.</span>
    </div>
  </div>

  <button type="button" class="btn btn-primary btn-block" data-copy="<?= h($message) ?>">
    <?= icon('copy') ?><span>Copiază mesajul pentru <?= h($first) ?></span>
  </button>
  <a class="btn btn-secondary btn-block" href="/users">Gata</a>
</div>
