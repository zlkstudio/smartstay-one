<?php
declare(strict_types=1);

// Applies sql/NNN_*.sql to smartconcept_one, once each, in order. Safe to re-run.
// Usage: php bin/migrate.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use One\Db\Database;

$pdo = Database::get('one');
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(ONE_ROOT . '/sql/[0-9][0-9][0-9]_*.sql') ?: [];
sort($files);

$count = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        continue;
    }
    $sql = (string) file_get_contents($file);
    // Strip "-- comments", then split on ";" at end of line. Migrations contain no procedures.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
    $statements = array_filter(array_map('trim', preg_split('/;\s*$/m', $sql) ?: []));

    echo "→ $version (" . count($statements) . " instrucțiuni)\n";
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
    $count++;
}

echo $count ? "✅ $count migrări aplicate.\n" : "✅ Schema e la zi.\n";
