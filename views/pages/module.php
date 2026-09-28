<?php
/** @var array $user @var string $module @var string $level @var ?string $legacyUrl */
use One\Auth\Access;

$isMaid = $user['role'] === 'maid';
$copy = [
    'reservations' => ['Rezervări', 'Check-in-urile de azi și de mâine, toggle-urile de taxă și formular, coduri Nuki, mesaje WhatsApp și linkuri Guest App.'],
    'housekeeping' => ['Housekeeping', 'Alocarea curățeniilor de check-out, curățeniile intermediare și checklist-ul cu poze.'],
    'inventory'    => ['Inventar', 'Stocul de lenjerii și prosoape pe apartament, cu notițele „Necesar".'],
    'reports'      => ['Rapoarte', 'Ocupare, surse de rezervări, venituri, plata menajerelor și indicatori operaționali.'],
];
[$title, $text] = $copy[$module];
if ($isMaid) {
    $title = 'Lista ta de azi';
    $text = 'Aici vei vedea apartamentele alocate ție și checklist-ul fiecăruia. Până atunci, folosește aplicația de curățenie ca de obicei.';
}
?>
<div class="stack">
  <?php if (isset($_GET['welcome'])): ?>
    <div class="alert alert-success" role="status"><?= icon('check', 'icon icon-sm') ?><span>Parola e setată. Bun venit în SmartStay ONE!</span></div>
  <?php endif; ?>
  <div class="hero row-between">
    <h1><?= h($isMaid ? 'Bună, ' . explode(' ', $user['name'])[0] : $title) ?></h1>
    <?php if (!$isMaid): ?>
      <span class="badge <?= $level === 'edit' ? 'badge-success' : 'badge-warning' ?>">
        <?= $level === 'edit' ? 'Editare' : 'Doar vizualizare' ?>
      </span>
    <?php endif; ?>
  </div>

  <div class="card empty">
    <span class="tile-icon" data-module="<?= h($module) ?>"><?= icon($module, 'icon icon-lg') ?></span>
    <h2><?= h($isMaid ? $title : 'În curând în ONE') ?></h2>
    <p><?= h($text) ?></p>
    <?php if ($legacyUrl): ?>
      <a class="btn btn-secondary" style="margin-top:20px" href="<?= h($legacyUrl) ?>" target="_blank" rel="noopener">
        <?= icon('external', 'icon icon-sm') ?><span>Deschide aplicația actuală</span>
      </a>
    <?php endif; ?>
  </div>
</div>
