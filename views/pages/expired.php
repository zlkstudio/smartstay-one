<?php
function expired_back_link(): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = parse_url($ref, PHP_URL_HOST);
    $path = (string) parse_url($ref, PHP_URL_PATH);
    return $host === explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0] && str_starts_with($path, '/') ? $path : '/';
}
?>
<div class="card empty">
  <span class="tile-icon"><?= icon('alert', 'icon icon-lg') ?></span>
  <h2>Pagina a expirat</h2>
  <p>Formularul a stat deschis prea mult sau sesiunea s-a schimbat. Reîncarcă și încearcă din nou.</p>
  <a class="btn btn-primary" style="margin-top:20px" href="<?= h(expired_back_link()) ?>">Reîncarcă</a>
</div>
