<?php
declare(strict_types=1);

namespace One\Db;

use PDO;
use PDOException;
use RuntimeException;

/**
 * One PDO connection per database, opened lazily.
 * Each database has its own config/database-{name}.php — never a shared file.
 */
final class Database
{
    public const NAMES = ['one', 'cleaning', 'inventory', 'reservations'];

    /** @var array<string, PDO> */
    private static array $connections = [];

    public static function get(string $name): PDO
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }
        $config = self::config($name);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'] ?? 'localhost',
            (int) ($config['port'] ?? 3306),
            $config['database']
        );

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            // Never leak the DSN/credentials: log the detail, throw a clean message.
            error_log("[ONE] DB connect failed ($name): " . $e->getMessage());
            throw new RuntimeException("Conexiunea la baza '$name' a eșuat.", 0, $e);
        }

        // ONE stores UTC. Legacy databases keep whatever they had.
        if ($name === 'one') {
            $pdo->exec("SET time_zone = '+00:00'");
        }

        return self::$connections[$name] = $pdo;
    }

    public static function isConfigured(string $name): bool
    {
        return is_file(self::configPath($name));
    }

    /** @return array{host?:string,port?:int,database:string,username:string,password:string} */
    public static function config(string $name): array
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new RuntimeException("Bază necunoscută: $name");
        }
        $path = self::configPath($name);
        if (!is_file($path)) {
            throw new RuntimeException("Lipsește config/database-$name.php");
        }
        $config = require $path;
        foreach (['database', 'username', 'password'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key])) {
                throw new RuntimeException("config/database-$name.php: lipsește '$key'");
            }
        }
        return $config;
    }

    private static function configPath(string $name): string
    {
        return ONE_ROOT . "/config/database-$name.php";
    }
}
