<?php
/** @var array $user @var bool $forced @var array<string,string> $errors */
use One\Auth\Auth;
use One\Controllers\AuthController;

$field = static function (string $name, string $label, string $autocomplete, array $errors, string $hint = ''): void { ?>
  <div class="field<?= isset($errors[$name]) ? ' has-error' : '' ?>">
    <label class="label" for="pw-<?= h($name) ?>"><?= h($label) ?></label>
    <div class="input-wrap">
      <input class="input" id="pw-<?= h($name) ?>" name="<?= h($name) ?>" type="password"
             autocomplete="<?= h($autocomplete) ?>" required <?= $name === 'current' ? 'autofocus' : '' ?>>
      <button type="button" class="icon-btn" data-toggle-password="pw-<?= h($name) ?>" aria-label="Arată parola">
        <span class="pw-show"><?= icon('eye') ?></span><span class="pw-hide" hidden><?= icon('eye-off') ?></span>
      </button>
    </div>
    <?php if (isset($errors[$name])): ?>
      <span class="field-error"><?= h($errors[$name]) ?></span>
    <?php elseif ($hint !== ''): ?>
      <span class="hint"><?= h($hint) ?></span>
    <?php endif; ?>
  </div>
<?php };

$form = static function () use ($field, $errors, $forced, $user): void { ?>
  <form method="post" action="/account/password" class="card stack" data-submit-once novalidate>
    <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
    <input type="text" name="username" value="<?= h($user['email'] ?? $user['phone'] ?? '') ?>" autocomplete="username" hidden>
    <?php $field('current', $forced ? 'Parola temporară' : 'Parola actuală', 'current-password', $errors); ?>
    <?php $field('new', 'Parola nouă', 'new-password', $errors, 'Minim ' . AuthController::MIN_PASSWORD . ' caractere. O propoziție scurtă e ușor de ținut minte.'); ?>
    <?php $field('confirm', 'Confirmă parola nouă', 'new-password', $errors); ?>
    <button class="btn btn-primary btn-block" type="submit">
      <span class="spinner"></span><span><?= $forced ? 'Setează parola și continuă' : 'Salvează parola' ?></span>
    </button>
    <?php if (!$forced): ?>
      <p class="hint">Vei rămâne conectat aici; celelalte dispozitive vor fi deconectate.</p>
    <?php endif; ?>
  </form>
<?php };
?>
<?php if ($forced): ?>
<div class="auth">
  <div class="auth-card">
    <div class="auth-head">
      <span class="logo logo-lg">
        <span class="logo-word"><span>smart</span><span class="logo-stay">stay</span></span>
        <span class="logo-sep"></span><span class="logo-one">ONE</span>
      </span>
      <h1>Bun venit, <?= h(explode(' ', $user['name'])[0]) ?>!</h1>
      <p>Alege o parolă a ta înainte de a începe.</p>
    </div>
    <?php $form(); ?>
    <form method="post" action="/logout" class="auth-foot">
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <button type="submit" class="btn btn-ghost btn-sm">Ieși din cont</button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="stack">
  <div class="hero"><h1>Schimbă parola</h1></div>
  <?php $form(); ?>
</div>
<?php endif; ?>
