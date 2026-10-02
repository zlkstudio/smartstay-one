<?php
/** @var ?string $error @var string $identifier @var string $next */
use One\Auth\Auth;
?>
<div class="auth">
  <div class="auth-card">
    <div class="auth-head">
      <span class="logo logo-lg"><?= logo() ?></span>
      <h1>Autentificare</h1>
      <p>Rezervări, curățenie și inventar — într-un singur loc.</p>
    </div>

    <form method="post" action="/login" class="card stack" data-submit-once novalidate>
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <input type="hidden" name="next" value="<?= h($next) ?>">

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert', 'icon icon-sm') ?><span><?= h($error) ?></span></div>
      <?php endif; ?>

      <div class="field">
        <label class="label" for="identifier">Email sau telefon</label>
        <input class="input" id="identifier" name="identifier" type="text" value="<?= h($identifier) ?>"
               autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
               inputmode="email" required <?= $identifier === '' ? 'autofocus' : '' ?>>
      </div>

      <div class="field">
        <label class="label" for="password">Parolă</label>
        <div class="input-wrap">
          <input class="input" id="password" name="password" type="password" autocomplete="current-password" required
                 <?= $identifier !== '' ? 'autofocus' : '' ?>>
          <button type="button" class="icon-btn" data-toggle-password="password" aria-label="Arată parola">
            <span class="pw-show"><?= icon('eye') ?></span><span class="pw-hide" hidden><?= icon('eye-off') ?></span>
          </button>
        </div>
      </div>

      <label class="check">
        <input type="checkbox" name="remember" value="1" checked>
        Ține-mă minte pe acest dispozitiv
      </label>

      <button class="btn btn-primary btn-block" type="submit">
        <span class="spinner"></span><span>Intră în cont</span>
      </button>
    </form>

    <p class="auth-foot">Ai uitat parola? Cere administratorului o resetare.</p>
  </div>
</div>
