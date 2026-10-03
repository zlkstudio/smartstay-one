<?php
/** Notificări push pe dispozitivul curent (Contul meu, Jurnal). Logica: assets/js/push.js. @var array $user */
use One\Notify\PushSubscriptions;
use One\Notify\WebPush;

$isMaidUser = $user['role'] === 'maid';
?>
<div class="card stack-sm" data-push
     data-configured="<?= WebPush::isConfigured() ? '1' : '0' ?>"
     data-devices="<?= PushSubscriptions::countFor((int) $user['id']) ?>">
  <div class="row">
    <span class="tile-icon"><?= icon('bell') ?></span>
    <div class="grow">
      <div class="card-title">Notificări push</div>
      <div class="list-sub" data-push-status><?= WebPush::isConfigured() ? 'Se verifică…' : 'Neconfigurate pe server (php bin/push-keys.php).' ?></div>
    </div>
  </div>
  <p class="faint push-help"><?= $isMaidUser
      ? 'Afli imediat când un apartament rămâne fără lenjerii.'
      : 'Checklist-uri trimise, apartamente cu lenjerii pe roșu, „Necesar” și „Tehnic” din Inventar. Nu primești notificări pentru propriile acțiuni.' ?></p>
  <div class="push-actions">
    <button type="button" class="btn btn-primary btn-sm" data-push-on hidden><?= icon('bell') ?><span>Activează pe acest telefon</span></button>
    <button type="button" class="btn btn-secondary btn-sm" data-push-test hidden><?= icon('send') ?><span>Trimite un test</span></button>
    <button type="button" class="btn btn-ghost btn-sm" data-push-off hidden>Dezactivează</button>
  </div>
</div>
