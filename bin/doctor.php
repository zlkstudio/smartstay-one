<?php
declare(strict_types=1);

// Health report for the shell — run after every deploy, and in Stage 0 to find the
// Reservations database name.
//
// Usage:
//   php bin/doctor.php                      full report
//   php bin/doctor.php --find-reservations  locate the database holding reservation_status

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use One\Db\Database;
use One\System\HealthCheck;

$opts = getopt('', ['find-reservations']);

if (isset($opts['find-reservations'])) {
    findReservations();
    exit(0);
}

$report = HealthCheck::full();
$icons = ['ok' => '✅', 'warn' => '⚠️ ', 'error' => '❌', 'missing' => '❌'];
$problems = 0;

echo "\nSmartStay ONE " . ONE_VERSION . " — " . config('base_url') . "\n\n";
echo "Baze de date\n";
foreach ($report['databases'] as $name => $db) {
    $problems += $db['status'] === 'ok' ? 0 : 1;
    printf("  %s %-13s %-28s %s\n", $icons[$db['status']], HealthCheck::DB_LABELS[$name], $db['database'] ?? '—', $db['message']);
    foreach ($db['tables'] ?? [] as $table => $rows) {
        $missing = in_array($table, $db['missing'] ?? [], true);
        printf("       %-26s %s\n", $table, $missing ? 'LIPSEȘTE' : '~' . number_format((int) $rows) . ' rânduri');
    }
}

echo "\nMenajere\n";
echo '  config:          ' . implode(', ', array_keys($report['maids']['configured'])) . "\n";
echo '  cleaning_records: ' . (implode(', ', $report['maids']['stored']) ?: '—') . "\n";
if ($report['maids']['unmatched']) {
    $problems++;
    echo '  ⚠️  necunoscute în config: ' . implode(', ', $report['maids']['unmatched']) . "\n";
}

echo "\nIntegrări (Etapa 2)\n";
foreach ($report['integrations'] as $int) {
    printf("  %s %-20s %s\n", $int['present'] ? '✅' : '·', $int['file'], $int['label']);
}

echo "\nServer\n";
foreach ($report['environment'] as $env) {
    // CLI has no HTTPS; only flag it for web requests.
    $ok = $env['ok'] || $env['label'] === 'HTTPS';
    $problems += $ok ? 0 : 1;
    printf("  %s %-24s %s\n", $ok ? '✅' : '❌', $env['label'], $env['value']);
}
if ($report['sessions']) {
    echo "  · utilizatori activi: {$report['sessions']['users']} · sesiuni: {$report['sessions']['active']}\n";
}

echo $problems ? "\n⚠️  $problems probleme de rezolvat.\n\n" : "\n✅ Totul în regulă.\n\n";
exit($problems ? 2 : 0);

function findReservations(): void
{
    echo "\nCaut baza de date cu tabela reservation_status…\n\n";

    $legacy = getenv('HOME') . '/smartstay.ro/reservations/config/database.php';
    if (is_file($legacy)) {
        // Read the name without executing the file (and without printing credentials).
        $src = (string) file_get_contents($legacy);
        if (preg_match("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $src, $m)) {
            echo "  Reservations config ($legacy):\n  → DB_NAME = {$m[1]}\n\n";
        }
        if (preg_match("/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $src, $m)) {
            echo "  → DB_USER = {$m[1]} (parola rămâne în fișier — copiaz-o direct în config/database-reservations.php)\n\n";
        }
    } else {
        echo "  (nu găsesc $legacy)\n\n";
    }

    foreach (['one', 'cleaning', 'inventory'] as $name) {
        if (!Database::isConfigured($name)) {
            continue;
        }
        try {
            $rows = Database::get($name)->query(
                "SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'reservation_status'"
            )->fetchAll(PDO::FETCH_COLUMN);
            echo "  Vizibil cu userul din database-$name.php: " . ($rows ? implode(', ', $rows) : 'nicio bază') . "\n";
            break;
        } catch (Throwable $e) {
            echo "  database-$name.php: " . $e->getMessage() . "\n";
        }
    }
    echo "\nPune numele găsit în config/database-reservations.php, apoi rulează din nou: php bin/doctor.php\n\n";
}
