<?php
/**
 * Rapoarte · Plata menajerelor — per maid, per cleaning, rates recomputed from Housekeeping\Rates.
 * @var string $from @var string $to @var ?string $preset @var ?array $data @var ?string $error
 * @var array $maids @var bool $canEdit @var string $tab
 */
use One\Controllers\ReportsController;
use One\Housekeeping\Rates;

$fmt = static fn(string $d): string => date('d.m', strtotime($d));
$days = ['Dum', 'Lun', 'Mar', 'Mie', 'Joi', 'Vin', 'Sâm'];
$dayName = static fn(string $d): string => $days[(int) date('w', strtotime($d))];
$money = static fn(int $v): string => number_format($v, 0, ',', '.') . ' RON';
?>
<div class="stack" data-reports data-can-edit="<?= $canEdit ? '1' : '0' ?>">
  <div class="hero">
    <div class="eyebrow"><?= h($preset ? ReportsController::PRESETS[$preset] : 'Perioadă aleasă') ?></div>
    <h1>Plata menajerelor</h1>
    <p class="tabular"><?= h($fmt($from)) ?> – <?= h(date('d.m.Y', strtotime($to))) ?></p>
  </div>

  <nav class="tabs tabs-teal" aria-label="Secțiuni Rapoarte">
    <?php foreach (ReportsController::TABS as $key => $t): ?>
      <a href="<?= h($t['path']) ?>" class="tab<?= $key === $tab ? ' is-active' : '' ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= h($t['label']) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="chips chips-teal" role="group" aria-label="Perioadă">
    <?php foreach (ReportsController::PRESETS as $key => $label): ?>
      <a class="chip<?= $key === $preset ? ' is-active' : '' ?>" href="/reports/payments?preset=<?= h($key) ?>"<?= $key === $preset ? ' aria-current="true"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <details class="card period-card"<?= $preset === null ? ' open' : '' ?>>
    <summary class="row-between"><span class="list-title">Altă perioadă</span><?= icon('chevron', 'icon icon-sm chev') ?></summary>
    <form method="get" action="/reports/payments" class="period-form">
      <label class="field"><span class="label">De la</span><input class="input" type="date" name="from" value="<?= h($from) ?>" required></label>
      <label class="field"><span class="label">Până la</span><input class="input" type="date" name="to" value="<?= h($to) ?>" required></label>
      <button type="submit" class="btn btn-teal btn-sm">Afișează</button>
    </form>
  </details>

  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert', 'icon icon-sm') ?><span><?= h($error) ?></span></div>
  <?php endif; ?>

  <?php if ($data): ?>
    <?php if ($data['unknown']): ?>
      <div class="alert alert-warning"><?= icon('alert', 'icon icon-sm') ?>
        <span>Fără tarif definit: <strong><?= h(implode(', ', $data['unknown'])) ?></strong> — plătite cu <?= Rates::FALLBACK ?> RON (implicit, ca în aplicația veche). Verifică dacă sunt studiouri și adaugă-le în <code>src/Housekeeping/Rates.php</code>.</span>
      </div>
    <?php endif; ?>

    <div class="stat-grid stat-grid-2">
      <div class="stat"><span class="stat-value tabular"><?= h($money($data['total'])) ?></span><span class="stat-label">Total de plată</span></div>
      <div class="stat"><span class="stat-value tabular"><?= (int) $data['count'] ?></span><span class="stat-label">Curățenii</span></div>
    </div>

    <?php if (!$data['maids']): ?>
      <div class="card empty">
        <span class="tile-icon" data-module="reports"><?= icon('reports', 'icon icon-lg') ?></span>
        <h2>Nicio curățenie în perioadă</h2>
        <p>Nu există înregistrări între <?= h($fmt($from)) ?> și <?= h($fmt($to)) ?>.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($data['maids'] as $m): ?>
      <details class="card pay-card">
        <summary class="pay-head">
          <span class="avatar role-maid"><?= h(initials($m['name'])) ?></span>
          <span class="grow">
            <span class="list-title"><?= h($m['name']) ?><?= $m['key'] === null ? ' <span class="badge badge-warning">nu e în config</span>' : '' ?></span>
            <span class="list-sub tabular"><?= (int) $m['checkouts'] ?> check-out · <?= (int) $m['intermediates'] ?> <?= $m['intermediates'] === 1 ? 'intermediară' : 'intermediare' ?></span>
          </span>
          <strong class="pay-total tabular"><?= h($money($m['total'])) ?></strong>
        </summary>
        <ul class="pay-lines">
          <?php foreach ($m['lines'] as $l): ?>
            <li class="pay-line" data-line="<?= (int) $l['id'] ?>">
              <span class="pay-date tabular"><span><?= h($dayName($l['date'])) ?></span><?= h($fmt($l['date'])) ?></span>
              <span class="grow">
                <span class="list-title tabular">Apt <?= h($l['apartment']) ?></span>
                <span class="pay-tags">
                  <?php if ($l['type'] === 'intermediate'): ?><span class="badge badge-violet">Intermediar</span><?php endif; ?>
                  <?php if ($l['double']): ?><span class="badge badge-success">✓✓ Checklist x2</span><?php endif; ?>
                  <?php if (!$l['known']): ?><span class="badge badge-warning">tarif implicit</span><?php endif; ?>
                </span>
              </span>
              <span class="tabular pay-rate"><?= (int) $l['rate'] ?> RON</span>
              <?php if ($canEdit): ?>
                <button type="button" class="icon-btn pay-del" data-delete="<?= (int) $l['id'] ?>"
                        data-label="Apt <?= h($l['apartment']) ?> · <?= h($fmt($l['date'])) ?> · <?= h($m['name']) ?>" aria-label="Șterge curățenia"><?= icon('trash', 'icon icon-sm') ?></button>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php
          // WhatsApp message: every cleaning + total. *bold* / _italic_ are WhatsApp markup.
          // The "Checklist x2" and "tarif implicit" flags stay internal — never sent to the maid.
          $wa = ['*Plata curățenii · ' . $m['name'] . '*', '_' . $fmt($from) . ' – ' . $fmt($to) . '.' . date('Y', strtotime($to)) . '_', ''];
          foreach ($m['lines'] as $l) {
              $wa[] = '• ' . $dayName($l['date']) . ' ' . $fmt($l['date']) . ' · Apt ' . $l['apartment']
                  . ($l['type'] === 'intermediate' ? ' (intermediară)' : '') . ' · ' . (int) $l['rate'] . ' RON';
          }
          $wa[] = '';
          $wa[] = 'Check-out: ' . (int) $m['checkouts'] . ($m['intermediates'] ? ' · Intermediare: ' . (int) $m['intermediates'] : '');
          $wa[] = '*Total: ' . $money($m['total']) . '*';
          $wa[] = '';
          $wa[] = 'Mulțumim! 🙏';
          $waText = implode("\n", $wa);
          $waPhone = $m['key'] !== null ? ($phones[$m['key']] ?? '') : '';
          $waUrl = 'https://api.whatsapp.com/send?' . ($waPhone !== '' ? 'phone=' . rawurlencode($waPhone) . '&' : '') . 'text=' . rawurlencode($waText);
        ?>
        <div class="btn-grid-2">
          <a class="btn btn-whatsapp btn-sm" href="<?= h($waUrl) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 'icon icon-sm') ?><span>Trimite pe WhatsApp</span></a>
          <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= h($waText) ?>"><?= icon('copy', 'icon icon-sm') ?><span>Copiază</span></button>
        </div>
        <?php if ($m['key'] !== null && $waPhone === ''): ?>
          <p class="hint">Fără număr: WhatsApp te lasă să alegi contactul. Adaugă telefonul în contul ei de Menajeră.</p>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($canEdit && $maids): ?>
    <h2 class="section-title">Adaugă manual</h2>
    <form class="card stack-sm" data-add-cleaning>
      <div class="add-grid">
        <label class="field"><span class="label">Menajera</span>
          <select class="select" name="maid" required>
            <option value="">Alege…</option>
            <?php foreach ($maids as $key => $name): ?><option value="<?= h($key) ?>"><?= h($name) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span class="label">Apartament</span>
          <input class="input" name="apartment" inputmode="numeric" pattern="\d{1,10}" required placeholder="ex. 400">
        </label>
      </div>
      <div class="add-grid">
        <label class="field"><span class="label">Data</span>
          <input class="input" type="date" name="date" value="<?= h(min($to, date('Y-m-d'))) ?>" max="<?= h(date('Y-m-d')) ?>" required>
        </label>
        <div class="field"><span class="label">Tip</span>
          <div class="segmented">
            <input type="radio" name="type" id="type-co" value="checkout" checked><label for="type-co">Check-out</label>
            <input type="radio" name="type" id="type-int" value="intermediate"><label for="type-int">Intermediar</label>
          </div>
        </div>
      </div>
      <button type="submit" class="btn btn-teal btn-block"><span class="spinner"></span><span>Adaugă în raport</span></button>
    </form>
  <?php endif; ?>
</div>
