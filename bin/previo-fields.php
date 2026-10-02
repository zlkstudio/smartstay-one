<?php
declare(strict_types=1);

// Lists the XML fields Previo returns for reservations on this account — field PATHS only,
// never values (guest data and prices stay off the screen). For a few harmless fields
// (status / channel / source / currency) it shows up to 5 distinct values, so we can decide
// how to detect cancellations and booking channels, and whether revenue is available.
//
// Usage: php bin/previo-fields.php [days back, default 30]

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use One\Integrations\Previo;

$days = max(1, min(120, (int) ($argv[1] ?? 30)));
$from = date('Y-m-d', strtotime("-$days days"));
$to = date('Y-m-d');

try {
    $reservations = Previo::search("$from 00:00:00", "$to 23:59:00", 'check-in');
} catch (Throwable $e) {
    fwrite(STDERR, '✖ ' . $e->getMessage() . "\n");
    exit(1);
}

$showValues = '/(status|state|cancel|storn|source|channel|partner|agen|operator|currency|type)/i';
$paths = [];
$values = [];

$walk = static function (SimpleXMLElement $node, string $path) use (&$walk, &$paths, &$values, $showValues): void {
    foreach ($node->attributes() as $name => $value) {
        $attrPath = "$path@$name";
        $paths[$attrPath] = ($paths[$attrPath] ?? 0) + 1;
    }
    $children = $node->children();
    if (count($children) === 0) {
        $paths[$path] = ($paths[$path] ?? 0) + 1;
        if (preg_match($showValues, $path)) {
            $v = mb_substr(trim((string) $node), 0, 40);
            if ($v !== '' && count($values[$path] ?? []) < 5) {
                $values[$path][$v] = true;
            }
        }
        return;
    }
    $seen = [];
    foreach ($children as $name => $child) {
        // Repeated children (guests) count once per reservation.
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $walk($child, "$path/$name");
    }
};

foreach ($reservations as $r) {
    $walk($r, 'reservation');
}
ksort($paths);

echo "\nPrevio · " . count($reservations) . " rezervări cu check-in între $from și $to\n\n";
foreach ($paths as $path => $count) {
    printf("  %-60s %4d\n", $path, $count);
    foreach (array_keys($values[$path] ?? []) as $v) {
        echo "      · $v\n";
    }
}
echo "\nCaută: un câmp de preț (price / total / amount) și unul de status (anulare).\n\n";
