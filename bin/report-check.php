<?php
declare(strict_types=1);

// Compares Rapoarte numbers with Previo (Overview / Hotelgroup overview) for calibration.
// Prints only aggregates — no guest data. Usage: php bin/report-check.php [year] [--all]
// --all: counts 40 and Daily too (like Previo), so RN / OCC / ADR are directly comparable.
//
// If ONE's ADR is consistently ~9–11% above Previo's, Previo's price includes VAT:
// set 'reports' => ['vat_rate' => 0.11] (or 0.09) in config/app.php and run again.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use One\Reports\Analytics;
use One\Reports\OperationsReport;
use One\Reports\Period;
use One\Stays;

$all = in_array('--all', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => $a !== '--all'));
$year = (int) ($args[0] ?? date('Y'));
$today = date('Y-m-d');
try {
    $ops = OperationsReport::cached(0, true);
    $stays = Stays::between("$year-01-01", "$year-12-31", true);
} catch (Throwable $e) {
    fwrite(STDERR, '✖ ' . $e->getMessage() . "\n");
    exit(1);
}
$roster = $ops['roster']['apartments'];
if ($all) {
    foreach ($stays as $s) {
        if (\One\Properties::isReportExcluded($s['apartment']) && !in_array($s['apartment'], $roster, true)) {
            $roster[] = $s['apartment'];
        }
    }
}
$a = new Analytics($stays, $roster, "$year-01-01", OperationsReport::vatRate());
$f = static fn(?float $v, int $d = 1): string => $v === null ? '—' : number_format($v, $d, ',', '.');

echo "\nSmartStay ONE " . ONE_VERSION . ' · ' . count($roster) . ' apartamente (' . implode(', ', $roster) . ')'
    . ($all ? ' · inclusiv 40 / Daily (ca Previo)' : '') . ' · TVA scăzut: ' . (OperationsReport::vatRate() > 0 ? OperationsReport::vatRate() * 100 . '%' : 'nu') . "\n\n";

$k = $a->kpis($today, $today);
printf("Azi        ocupate %d/%d · ocupare %s%% · ADR %s · RevPAR %s · venit %s\n",
    $k['occupied'], $k['total'], $f($k['occupancy']), $f($k['adr']), $f($k['revpar']), $f($k['revenue'], 0));
$m = substr($today, 0, 8) . '01';
$k = $a->kpis($m, $today);
printf("MTD        ocupate %d/%d · ocupare %s%% · ADR %s · RevPAR %s · venit %s\n\n",
    $k['occupied'], $k['total'], $f($k['occupancy']), $f($k['adr']), $f($k['revpar']), $f($k['revenue'], 0));

printf("%-6s %6s %8s %9s %9s %12s\n", 'Luna', 'RN', 'OCC%', 'ADR', 'RevPAR', 'Venit');
$mo = $a->monthly($year, $today);
foreach ($mo['months'] as $row) {
    $x = $row['kpis'];
    printf("%-6s %6d %8s %9s %9s %12s\n", Period::monthShort($row['month']), $x['occupied'], $f($x['occupancy']), $f($x['adr']), $f($x['revpar']), $f($x['revenue']));
}
$x = $mo['total'];
printf("%-6s %6d %8s %9s %9s %12s\n", 'Total', $x['occupied'], $f($x['occupancy']), $f($x['adr']), $f($x['revpar']), $f($x['revenue']));
echo "\nRezervări fără preț: " . $a->unpricedCount() . "\n";
echo "Compară cu Previo → Manager reports → Hotelgroup overview (Accommodation, RON). RN-ul diferă dacă Previo include 40 / Daily.\n\n";
