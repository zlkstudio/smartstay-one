<?php
/** @var array $user @var list<array> $users @var list<array> $activity @var ?string $notice */
use One\Audit;
use One\Auth\Access;

$counts = ['all' => count($users)];
foreach ($users as $u) {
    $counts[$u['role']] = ($counts[$u['role']] ?? 0) + 1;
}
$maids = config('maids', []);
?>
<div class="stack">
  <div class="hero row-between">
    <h1>Utilizatori</h1>
    <a href="/users/new" class="btn btn-primary btn-sm"><?= icon('plus', 'icon icon-sm') ?><span>Adaugă</span></a>
  </div>

  <?php if ($notice): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span><?= h($notice) ?></span></div>
  <?php endif; ?>

  <div class="input-wrap">
    <input class="input" type="search" placeholder="Caută nume, email, telefon" data-user-search aria-label="Caută utilizatori" autocomplete="off">
  </div>

  <div class="chips" role="tablist">
    <button type="button" class="chip is-active" data-role-filter="all">Toți <span class="count"><?= $counts['all'] ?></span></button>
    <?php foreach (Access::ROLE_LABELS as $role => $label): ?>
      <?php if (!empty($counts[$role])): ?>
        <button type="button" class="chip" data-role-filter="<?= h($role) ?>"><?= h($label) ?> <span class="count"><?= (int) $counts[$role] ?></span></button>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="list" data-user-list>
    <?php foreach ($users as $u):
        $modules = [];
        if ($u['role'] === 'user') {
            foreach ($u['permissions'] as $module => $level) {
                $modules[] = Access::LABELS[$module] . ($level === 'view' ? ' (vizualizare)' : '');
            }
        } elseif ($u['role'] === 'maid') {
            $modules[] = 'Curățenie · ' . ($maids[$u['maid_ref']] ?? $u['maid_ref']);
        } else {
            $modules[] = $u['role'] === 'admin' ? 'Acces complet' : 'Toate modulele';
        }
        $search = mb_strtolower($u['name'] . ' ' . ($u['email'] ?? '') . ' ' . ($u['phone'] ?? ''));
        $inactive = !(int) $u['active'];
    ?>
      <a class="list-item" href="/users/<?= (int) $u['id'] ?>/edit" data-user data-role="<?= h($u['role']) ?>" data-search="<?= h($search) ?>">
        <span class="avatar role-<?= h($u['role']) ?><?= $inactive ? ' is-inactive' : '' ?>"><?= h(initials($u['name'])) ?></span>
        <span class="grow">
          <span class="row" style="gap:8px">
            <span class="list-title"><?= h($u['name']) ?></span>
            <?php if ((int) $u['id'] === (int) $user['id']): ?><span class="badge">Tu</span><?php endif; ?>
          </span>
          <span class="list-sub" style="display:block"><?= h(Access::ROLE_LABELS[$u['role']]) ?> · <?= h(implode(', ', $modules)) ?></span>
        </span>
        <?php if ($inactive): ?>
          <span class="badge badge-critical">Inactiv</span>
        <?php elseif ((int) $u['must_change_password']): ?>
          <span class="badge badge-warning">Nou</span>
        <?php endif; ?>
        <?= icon('chevron', 'icon icon-sm chev') ?>
      </a>
    <?php endforeach; ?>
  </div>
  <p class="muted" data-user-empty hidden style="text-align:center">Niciun utilizator găsit.</p>

  <?php if ($activity): ?>
    <h2 class="section-title">Activitate recentă</h2>
    <div class="list">
      <?php foreach ($activity as $a): ?>
        <div class="list-item">
          <span class="grow">
            <span style="display:block;font-size:14.5px">
              <strong><?= h($a['actor_name'] ?? 'Sistem') ?></strong>
              <?= h(mb_strtolower(Audit::ACTION_LABELS[$a['action']] ?? $a['action'])) ?>
              <?php if ($a['target_name'] && $a['action'] !== 'auth.password_change'): ?><strong><?= h($a['target_name']) ?></strong><?php endif; ?>
            </span>
            <span class="list-sub"><?= h(local_time($a['created_at'])) ?></span>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
