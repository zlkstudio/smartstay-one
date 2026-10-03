<?php
/** @var array $user */
use One\Auth\Access;
use One\Auth\Auth;

$contact = $user['email'] ?? '';
if ($user['phone']) {
    $contact .= ($contact ? ' · ' : '') . '+' . $user['phone'];
}
?>
<div class="stack">
  <?php if (($_GET['ok'] ?? '') === 'password'): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Parola a fost schimbată.</span></div>
  <?php endif; ?>

  <div class="card row">
    <span class="avatar avatar-lg role-<?= h($user['role']) ?>"><?= h(initials($user['name'])) ?></span>
    <div class="grow">
      <div class="card-title"><?= h($user['name']) ?></div>
      <div class="list-sub"><?= h($contact) ?></div>
      <span class="badge badge-blue" style="margin-top:6px"><?= h(Access::ROLE_LABELS[$user['role']]) ?></span>
    </div>
  </div>

  <?php if (Access::can($user, 'users') || Access::can($user, 'settings')): ?>
    <h2 class="section-title">Administrare</h2>
    <div class="list">
      <?php if (Access::can($user, 'users')): ?>
        <a class="list-item" href="/users">
          <span class="tile-icon"><?= icon('users') ?></span>
          <span class="grow"><span class="list-title">Utilizatori</span><br><span class="list-sub">Conturi, roluri, permisiuni</span></span>
          <?= icon('chevron', 'icon icon-sm chev') ?>
        </a>
      <?php endif; ?>
      <?php if (Access::can($user, 'settings')): ?>
        <a class="list-item" href="/activity">
          <span class="tile-icon"><?= icon('bell') ?></span>
          <span class="grow"><span class="list-title">Jurnal și notificări</span><br><span class="list-sub">Checklist-uri, inventar, notificări push</span></span>
          <?= icon('chevron', 'icon icon-sm chev') ?>
        </a>
        <a class="list-item desktop-only" href="/settings">
          <span class="tile-icon"><?= icon('settings') ?></span>
          <span class="grow"><span class="list-title">Setări și stare sistem</span><br><span class="list-sub">Baze de date, integrări, server</span></span>
          <?= icon('chevron', 'icon icon-sm chev') ?>
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (\One\Notify\Notifier::canReceive($user)): ?>
    <h2 class="section-title">Notificări</h2>
    <?php require ONE_ROOT . '/views/partials/push-card.php'; ?>
  <?php endif; ?>

  <h2 class="section-title">Cont</h2>
  <div class="list">
    <a class="list-item" href="/account/password">
      <span class="tile-icon"><?= icon('key') ?></span>
      <span class="grow list-title">Schimbă parola</span>
      <?= icon('chevron', 'icon icon-sm chev') ?>
    </a>
    <button type="button" class="list-item" data-theme-toggle>
      <span class="tile-icon"><span class="show-light"><?= icon('moon') ?></span><span class="show-dark"><?= icon('sun') ?></span></span>
      <span class="grow list-title"><span class="show-light">Temă întunecată</span><span class="show-dark">Temă luminoasă</span></span>
    </button>
    <a class="list-item" href="/install?manual=1" data-hide-standalone>
      <span class="tile-icon"><?= icon('download') ?></span>
      <span class="grow list-title">Instalează aplicația pe telefon</span>
      <?= icon('chevron', 'icon icon-sm chev') ?>
    </a>
  </div>

  <form method="post" action="/logout">
    <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
    <button type="submit" class="btn btn-danger btn-block"><?= icon('logout') ?><span>Deconectare</span></button>
  </form>

  <p class="faint" style="text-align:center;font-size:13px">SmartStay ONE <?= h(ONE_VERSION) ?></p>
</div>
