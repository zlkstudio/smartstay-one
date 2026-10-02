<?php
/**
 * App shell for signed-in pages.
 * @var array $user  @var string $pageTitle  @var ?string $active  @var string $content
 */
use One\Auth\Access;

$user = $user ?? null;
$active = $active ?? null;
$isMaid = $user && $user['role'] === 'maid';

$navItems = [];
if ($user && !$isMaid && !(int) $user['must_change_password']) {
    $navItems[] = ['key' => 'home', 'href' => '/', 'label' => 'Acasă', 'icon' => 'home'];
    foreach (Access::MODULES as $module) {
        if (Access::can($user, $module)) {
            $navItems[] = [
                'key'   => $module,
                'href'  => '/' . $module,
                'label' => $module === 'housekeeping' ? 'Curățenie' : Access::LABELS[$module],
                'icon'  => $module,
            ];
        }
    }
}
$showNav = count($navItems) > 1;
?>
<!doctype html>
<html lang="ro">
<head>
<?php require ONE_ROOT . '/views/partials/head.php'; ?>
</head>
<body data-page="<?= h($active ?? '') ?>">
<?php require ONE_ROOT . '/views/partials/icons.php'; ?>

<header class="topbar">
  <?php if (!empty($backHref)): ?>
    <a class="icon-btn" href="<?= h($backHref) ?>" aria-label="Înapoi"><?= icon('back') ?></a>
    <div class="topbar-title"><?= h($pageTitle) ?></div>
  <?php else: ?>
    <a href="<?= $isMaid ? '/housekeeping' : '/' ?>" class="logo" aria-label="SmartStay ONE — Acasă">
      <?= logo() ?>
    </a>
    <div class="topbar-title"></div>
  <?php endif; ?>
  <div class="topbar-actions">
    <?php if ($user && Access::can($user, 'settings')): ?>
      <a href="/settings" class="icon-btn desktop-only<?= $active === 'settings' ? ' is-active' : '' ?>" aria-label="Setări" title="Setări · stare sistem"><?= icon('settings') ?></a>
    <?php endif; ?>
    <button type="button" class="icon-btn" data-theme-toggle aria-label="Schimbă tema">
      <span class="show-light"><?= icon('moon') ?></span><span class="show-dark"><?= icon('sun') ?></span>
    </button>
    <?php if ($user): ?>
      <a href="/account" class="avatar role-<?= h($user['role']) ?>" aria-label="Contul meu"><?= h(initials($user['name'])) ?></a>
    <?php endif; ?>
  </div>
</header>

<main class="main<?= $showNav ? '' : ' no-nav' ?>" id="main">
<?= $content ?>
</main>

<?php if ($showNav): ?>
<nav class="bottom-nav" aria-label="Navigare principală">
  <?php foreach ($navItems as $item): ?>
    <a href="<?= h($item['href']) ?>" data-module="<?= h($item['key']) ?>"
       class="nav-item<?= $active === $item['key'] ? ' is-active' : '' ?>"
       <?= $active === $item['key'] ? 'aria-current="page"' : '' ?>>
      <span class="nav-pill"><?= icon($item['icon']) ?></span>
      <span><?= h($item['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="toasts" id="toasts" aria-live="polite"></div>
</body>
</html>
