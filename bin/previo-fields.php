<?php
declare(strict_types=1);

// Lists the XML fields Previo returns for reservations on this account — field PATHS only,
// never values (guest data and prices stay off the screen). For a few harmless fields
// (status / channel / source / currency) it shows up to 5 distinct values, so we can decide
// how to detect cancellations and booking channels, and whether revenue is available.
//
// Usage: php bin/previo-fields.php [days back, default 30]
//        php bin/previo-fields.php --status [days back]   statusId breakdown: count, 3 example resIds,
//                                                         price min/avg/max — no guest data

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use One\Integrations\Previo;

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => $a !== '--status'));
$statusMode = in_array('--status', $argv, true);
$days = max(1, min(120, (int) ($args[0] ?? 30)));
$from = date('Y-m-d', strtotime("-$days days"));
$to = date('Y-m-d');

try {
    $reservations = Previo::search("$from 00:00:00", "$to 23:59:00", 'check-in');
} catch (Throwable $e) {
    fwrite(STDERR, '✖ ' . $e->getMessage() . "\n");
    exit(1);
}

if ($statusMode) {
    $today = date('Y-m-d');
    $groups = [];
    foreach ($reservations as $r) {
        $id = (string) $r->status->statusId;
        $g = &$groups[$id];
        $g ??= ['count' => 0, 'examples' => [], 'prices' => [], 'past' => 0, 'parking' => 0, 'option' => 0];
        $g['count']++;
        if (count($g['examples']) < 3) {
            $g['examples'][] = (string) $r->resId;
        }
        $g['prices'][] = (float) str_replace(',', '.', (string) $r->price);
        $g['past'] += substr((string) $r->term->to, 0, 10) <= $today ? 1 : 0;
        $g['parking'] += One\Properties::isParking(Previo::apartment($r)) ? 1 : 0;
        $g['option'] += isset($r->status->optionExpiration) ? 1 : 0;
        unset($g);
    }
    ksort($groups);
    echo "\nPrevio · statusId pentru " . count($reservations) . " rezervări cu check-in între $from și $to\n\n";
    foreach ($groups as $id => $g) {
        $p = $g['prices'];
        printf("  statusId %-3s %4d rez. · %3d plecate · %3d parcări · %d cu optionExpiration\n", $id, $g['count'], $g['past'], $g['parking'], $g['option']);
        printf("      preț min %s · medie %s · max %s · exemple resId: %s\n",
            number_format(min($p), 2, '.', ''), number_format(array_sum($p) / count($p), 2, '.', ''),
            number_format(max($p), 2, '.', ''), implode(', ', $g['examples']));
    }
    echo "\nDeschide în Previo câte un resId din fiecare grup și notează ce status are (confirmată, anulată, opțiune…).\n\n";
    exit(0);
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
