<?php
/** Full-screen layout without app chrome: login, forced password change, install. */
?>
<!doctype html>
<html lang="ro">
<head>
<?php require ONE_ROOT . '/views/partials/head.php'; ?>
</head>
<body>
<?php require ONE_ROOT . '/views/partials/icons.php'; ?>
<?= $content ?>
<div class="toasts" id="toasts" aria-live="polite"></div>
</body>
</html>
