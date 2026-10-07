<?php
declare(strict_types=1);

// Cronometrează curățeniile din jurnalul Nuki + șterge codul oaspetelui plecat. cPanel → Cron Jobs, la fiecare minut:
//   * * * * * /usr/local/bin/php /home/smartconcept/one.smartstay.ro/bin/cleaning-cron.php >> /home/smartconcept/one.smartstay.ro/storage/logs/cleaning.log 2>&1
// Fără cron merge tot, dar doar cât e deschisă pagina Curățenie (sincronizare la max. 1 minut).
// Rulează doar 07:00–22:59 (nimeni nu face curățenie noaptea; economisește apeluri Nuki).

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use One\Housekeeping\CleaningTracker;

$hour = (int) date('G');
if (($hour < 7 || $hour > 22) && !in_array('--force', $argv, true)) {
    exit(0);
}

$r = CleaningTracker::sync(true);
// Doar ce s-a întâmplat ajunge în log — un minut liniștit nu scrie nimic.
if ($r['opened'] || $r['closed'] || $r['errors'] || !in_array($r['status'], ['ok', 'nothing', 'busy'], true)) {
    printf(
        "[%s] %s · început %d · terminat %d%s\n",
        date('Y-m-d H:i:s'),
        $r['status'],
        $r['opened'],
        $r['closed'],
        $r['errors'] ? ' · Nuki: ' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE) : ''
    );
}
