<?php
declare(strict_types=1);

// Recomputes the cached reports (report_cache). Run hourly from cPanel → Cron Jobs:
//   7 * * * * /usr/local/bin/php /home/smartconcept/one.smartstay.ro/bin/reports-cron.php >> /home/smartconcept/one.smartstay.ro/storage/logs/cron.log 2>&1
// Safe to run by hand: php bin/reports-cron.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use One\Reports\OperationsReport;
use One\Reports\ReportCache;
use One\Stays;

$started = microtime(true);
$stamp = date('Y-m-d H:i:s');

try {
    $report = OperationsReport::cached(0, true);   // fresh Previo read, always rewritten
    $history = count(Stays::year((int) date('Y'), true));   // Prezentare: KPI, trend lunar, per apartament
    $pruned = ReportCache::prune(30);
} catch (Throwable $e) {
    error_log('[ONE] reports-cron: ' . $e->getMessage());
    fwrite(STDERR, "[$stamp] ✖ " . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "[%s] ✔ operations: %d/%d libere azi · ocupare 30 nopți %d%% · %d rezervări în 30 zile · istoric %d · %d rânduri vechi șterse · %.1fs\n",
    $stamp,
    count($report['today']['free']),
    $report['roster']['total'],
    $report['occupancy']['avgPast'],
    $report['channels']['total'],
    $history,
    $pruned,
    microtime(true) - $started
);
