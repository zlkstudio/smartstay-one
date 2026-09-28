<?php
declare(strict_types=1);

namespace One\System;

use One\Db\Database;
use Throwable;

/**
 * Checks everything ONE depends on: the four databases, their tables,
 * integration configs and the PHP environment. Read-only.
 */
final class HealthCheck
{
    public const EXPECTED_TABLES = [
        'one'          => ['users', 'permissions', 'sessions', 'login_attempts', 'audit_log', 'report_cache'],
        'cleaning'     => ['cleaning_records', 'maid_assignments', 'checklist_submissions'],
        'inventory'    => ['inventar_apartamente'],
        'reservations' => ['reservation_status', 'reservation_status_log'],
    ];

    public const DB_LABELS = [
        'one'          => 'SmartStay ONE',
        'cleaning'     => 'Housekeeping',
        'inventory'    => 'Inventar',
        'reservations' => 'Rezervări',
    ];

    /** Integration configs ported in Stage 2 (copied from the legacy apps, never symlinked). */
    public const INTEGRATION_CONFIGS = [
        'previo.php' => 'Previo API (din guest-app/config/previo.php)',
        'nuki.php'   => 'Nuki API token + mapare yale',
    ];

    /** @return array{ok:int,total:int,problems:list<string>} */
    public static function summary(): array
    {
        $problems = [];
        $ok = 0;
        foreach (array_keys(self::EXPECTED_TABLES) as $name) {
            $db = self::database($name);
            if ($db['status'] === 'ok') {
                $ok++;
            } else {
                $problems[] = self::DB_LABELS[$name] . ': ' . $db['message'];
            }
        }
        return ['ok' => $ok, 'total' => count(self::EXPECTED_TABLES), 'problems' => $problems];
    }

    /** @return array<string,mixed> */
    public static function full(): array
    {
        $databases = [];
        foreach (array_keys(self::EXPECTED_TABLES) as $name) {
            $databases[$name] = self::database($name);
        }

        $integrations = [];
        foreach (self::INTEGRATION_CONFIGS as $file => $label) {
            $integrations[] = [
                'file'    => "config/$file",
                'label'   => $label,
                'present' => is_file(ONE_ROOT . "/config/$file"),
            ];
        }

        return [
            'databases'    => $databases,
            'maids'        => self::maidNames(in_array($databases['cleaning']['status'], ['ok', 'warn'], true)),
            'integrations' => $integrations,
            'environment'  => self::environment(),
            'sessions'     => self::sessionStats($databases['one']['status'] === 'ok'),
        ];
    }

    /** @return array{status:string,message:string,database?:string,tables?:array<string,?int>} */
    public static function database(string $name): array
    {
        if (!Database::isConfigured($name)) {
            return ['status' => 'missing', 'message' => "lipsește config/database-$name.php"];
        }
        try {
            $pdo = Database::get($name);
            $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
            );
            $stmt->execute([$dbName]);
            $existing = [];
            foreach ($stmt->fetchAll() as $row) {
                $existing[$row['TABLE_NAME']] = $row['TABLE_ROWS'] === null ? null : (int) $row['TABLE_ROWS'];
            }

            $tables = [];
            $missing = [];
            foreach (self::EXPECTED_TABLES[$name] as $table) {
                if (array_key_exists($table, $existing)) {
                    $tables[$table] = $existing[$table];
                } else {
                    $tables[$table] = null;
                    $missing[] = $table;
                }
            }

            return [
                'status'   => $missing ? 'warn' : 'ok',
                'message'  => $missing ? 'lipsesc tabelele: ' . implode(', ', $missing) : 'conectat',
                'database' => $dbName,
                'tables'   => $tables,
                'missing'  => $missing,
            ];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Compares the maid keys in config/app.php with the maid_name values actually
     * stored in cleaning_records, so Stage 2 filters by the right spelling.
     * @return array{configured:array<string,string>, stored:list<string>, unmatched:list<string>}
     */
    private static function maidNames(bool $cleaningOk): array
    {
        $configured = config('maids', []);
        $stored = [];
        if ($cleaningOk) {
            try {
                $stored = Database::get('cleaning')->query(
                    "SELECT DISTINCT maid_name FROM cleaning_records
                     WHERE cleaning_date >= CURDATE() - INTERVAL 90 DAY
                     ORDER BY maid_name"
                )->fetchAll(\PDO::FETCH_COLUMN);
            } catch (Throwable) {
                $stored = [];
            }
        }
        $known = array_map('mb_strtolower', array_merge(array_keys($configured), array_values($configured)));
        $unmatched = array_values(array_filter(
            $stored,
            static fn(string $n): bool => !in_array(mb_strtolower($n), $known, true)
        ));
        return ['configured' => $configured, 'stored' => $stored, 'unmatched' => $unmatched];
    }

    /** @return list<array{label:string,value:string,ok:bool}> */
    private static function environment(): array
    {
        $storage = ONE_ROOT . '/storage/logs';
        return [
            ['label' => 'PHP', 'value' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['label' => 'pdo_mysql', 'value' => extension_loaded('pdo_mysql') ? 'da' : 'lipsă', 'ok' => extension_loaded('pdo_mysql')],
            ['label' => 'curl (Previo, Nuki)', 'value' => extension_loaded('curl') ? 'da' : 'lipsă', 'ok' => extension_loaded('curl')],
            ['label' => 'gd (poze checklist)', 'value' => extension_loaded('gd') ? 'da' : 'lipsă', 'ok' => extension_loaded('gd')],
            ['label' => 'HTTPS', 'value' => is_https() ? 'da' : 'nu', 'ok' => is_https() || is_dev()],
            ['label' => 'Cookie Secure', 'value' => config('cookie_secure', true) ? 'da' : 'nu', 'ok' => (bool) config('cookie_secure', true) || is_dev()],
            ['label' => 'Mediu', 'value' => (string) config('env'), 'ok' => config('env') === 'production' || is_dev()],
            ['label' => 'storage/logs scriibil', 'value' => is_writable($storage) ? 'da' : 'nu', 'ok' => is_writable($storage)],
            ['label' => 'Versiune ONE', 'value' => ONE_VERSION, 'ok' => true],
        ];
    }

    /** @return array{active:int,users:int}|null */
    private static function sessionStats(bool $oneOk): ?array
    {
        if (!$oneOk) {
            return null;
        }
        $pdo = Database::get('one');
        return [
            'active' => (int) $pdo->query('SELECT COUNT(*) FROM sessions WHERE expires_at > UTC_TIMESTAMP()')->fetchColumn(),
            'users'  => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn(),
        ];
    }
}
