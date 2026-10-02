<?php
/** @var array $user @var ?array $target @var array $data @var array<string,string> $errors @var array<string,string> $maids */
use One\Auth\Access;
use One\Auth\Auth;

$isEdit = $target !== null;
$isSelf = $isEdit && (int) $target['id'] === (int) $user['id'];
$action = $isEdit ? '/users/' . (int) $target['id'] : '/users';
$csrf = Auth::csrfToken();

$roleHelp = [
    'admin'   => 'Tot, inclusiv utilizatori și setări.',
    'manager' => 'Toate modulele și rapoartele. Fără utilizatori și setări.',
    'maid'    => 'Curățenie și Inventar; Rezervări doar citire; în Rapoarte doar curățeniile ei.',
    'user'    => 'Doar modulele bifate mai jos.',
];
$phoneDisplay = $data['phone'] ? '+' . $data['phone'] : '';

$err = static function (string $key) use ($errors): string {
    return isset($errors[$key]) ? '<span class="field-error">' . h($errors[$key]) . '</span>' : '';
};
?>
<div class="stack">
  <div class="hero"><h1><?= $isEdit ? h($target['name']) : 'Utilizator nou' ?></h1>
    <?php if ($isEdit): ?>
      <p>Ultima autentificare: <?= h(local_time($target['last_login_at'])) ?></p>
    <?php endif; ?>
  </div>

  <?php if (isset($errors['_'])): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert', 'icon icon-sm') ?><span><?= h($errors['_']) ?></span></div>
  <?php elseif ($errors): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert', 'icon icon-sm') ?><span>Verifică câmpurile marcate.</span></div>
  <?php endif; ?>

  <form method="post" action="<?= h($action) ?>" class="stack" data-user-form data-submit-once novalidate>
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="card stack">
      <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
        <label class="label" for="name">Nume</label>
        <input class="input" id="name" name="name" value="<?= h($data['name']) ?>" autocomplete="off" required maxlength="100">
        <?= $err('name') ?>
      </div>
      <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
        <label class="label" for="email">Email</label>
        <input class="input" id="email" name="email" type="email" value="<?= h($data['email'] ?? '') ?>" autocomplete="off" autocapitalize="none" inputmode="email">
        <?= $err('email') ?>
      </div>
      <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
        <label class="label" for="phone">Telefon</label>
        <input class="input" id="phone" name="phone" type="tel" value="<?= h($phoneDisplay) ?>" autocomplete="off" inputmode="tel" placeholder="07xx xxx xxx">
        <?= $err('phone') ?: '<span class="hint">Autentificarea merge cu emailul sau cu telefonul.</span>' ?>
      </div>
    </div>

    <div class="card stack">
      <div class="field<?= isset($errors['role']) ? ' has-error' : '' ?>">
        <span class="label">Rol</span>
        <div class="segmented" role="radiogroup">
          <?php foreach (Access::ROLE_LABELS as $role => $label): ?>
            <input type="radio" id="role-<?= h($role) ?>" name="role" value="<?= h($role) ?>" <?= $data['role'] === $role ? 'checked' : '' ?>
                   <?= $isSelf && $role !== 'admin' ? 'disabled' : '' ?>>
            <label for="role-<?= h($role) ?>"><?= h($label) ?></label>
          <?php endforeach; ?>
        </div>
        <?php foreach ($roleHelp as $role => $help): ?>
          <span class="hint" data-for-role="<?= h($role) ?>" hidden><?= h($help) ?></span>
        <?php endforeach; ?>
        <?php if ($isSelf): ?><span class="hint">Nu îți poți schimba propriul rol.</span><?php endif; ?>
        <?= $err('role') ?>
      </div>

      <div class="field<?= isset($errors['maid_ref']) ? ' has-error' : '' ?>" data-for-role="maid" hidden>
        <label class="label" for="maid_ref">Menajera</label>
        <select class="select" id="maid_ref" name="maid_ref">
          <option value="">Alege…</option>
          <?php foreach ($maids as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= ($data['maid_ref'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('maid_ref') ?: '<span class="hint">Leagă contul de curățeniile ei din Housekeeping.</span>' ?>
      </div>

      <div data-for-role="user" hidden>
        <span class="label">Acces pe module</span>
        <div class="stack-sm" style="margin-top:10px">
          <?php foreach (Access::MODULES as $module):
              $current = $data['permissions'][$module] ?? 'none'; ?>
            <div class="perm-row">
              <span class="row" style="gap:8px"><?= icon($module, 'icon icon-sm') ?><strong style="font-weight:600"><?= h(Access::LABELS[$module]) ?></strong></span>
              <div class="segmented">
                <?php foreach (['none' => 'Fără', 'view' => 'Vizualizare', 'edit' => 'Editare'] as $level => $label): ?>
                  <input type="radio" id="perm-<?= h($module . '-' . $level) ?>" name="perm[<?= h($module) ?>]" value="<?= h($level) ?>" <?= $current === $level ? 'checked' : '' ?>>
                  <label for="perm-<?= h($module . '-' . $level) ?>"><?= h($label) ?></label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?= $err('perm') ?>
      </div>
    </div>

    <button class="btn btn-primary btn-block" type="submit">
      <span class="spinner"></span><span><?= $isEdit ? 'Salvează modificările' : 'Creează contul' ?></span>
    </button>
    <?php if (!$isEdit): ?>
      <p class="hint" style="text-align:center">Primești o parolă temporară pe care o dai persoanei. La prima intrare își alege parola ei.</p>
    <?php endif; ?>
  </form>

  <?php if ($isEdit): ?>
    <h2 class="section-title">Acțiuni</h2>
    <div class="list">
      <form method="post" action="/users/<?= (int) $target['id'] ?>/reset" data-confirm="Resetezi parola pentru <?= h($target['name']) ?>? Va fi deconectat de pe toate dispozitivele.">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <button type="submit" class="list-item">
          <span class="tile-icon"><?= icon('key') ?></span>
          <span class="grow"><span class="list-title">Resetează parola</span><br><span class="list-sub">Generează o parolă temporară nouă</span></span>
        </button>
      </form>
      <?php if (!$isSelf): ?>
        <?php $active = (int) $target['active']; ?>
        <form method="post" action="/users/<?= (int) $target['id'] ?>/toggle"
              data-confirm="<?= $active ? 'Dezactivezi contul lui ' . h($target['name']) . '? Va fi deconectat imediat.' : 'Reactivezi contul lui ' . h($target['name']) . '?' ?>">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
          <button type="submit" class="list-item" style="color:<?= $active ? 'var(--critical)' : 'var(--success)' ?>">
            <span class="tile-icon" style="background:<?= $active ? 'var(--critical-soft)' : 'var(--success-soft)' ?>;color:inherit"><?= icon($active ? 'user-x' : 'user-check') ?></span>
            <span class="grow"><span class="list-title"><?= $active ? 'Dezactivează contul' : 'Reactivează contul' ?></span><br>
              <span class="list-sub"><?= $active ? 'Deconectare imediată de pe toate dispozitivele' : 'Poate intra din nou cu parola lui' ?></span></span>
          </button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
