<?php
declare(strict_types=1);

namespace One\Auth;

use One\Db\Database;

/** Brute-force protection for /login. Counts failures in a sliding 15-minute window. */
final class LoginThrottle
{
    private const WINDOW_MINUTES = 15;
    private const MAX_PER_IDENTIFIER = 5;
    private const MAX_PER_IP = 20;

    /** @return int seconds until the next attempt is allowed; 0 = allowed now */
    public static function retryAfter(string $identifier, string $ip): int
    {
        $db = Database::get('one');
        $checks = [
            ['identifier', $identifier, self::MAX_PER_IDENTIFIER],
            ['ip_address', $ip, self::MAX_PER_IP],
        ];

        foreach ($checks as [$column, $value, $max]) {
            // $column comes from the fixed list above, never from input.
            $query = $db->prepare(
                "SELECT COUNT(*) AS n, MIN(attempted_at) AS oldest FROM login_attempts
                 WHERE success = 0
                   AND attempted_at > UTC_TIMESTAMP() - INTERVAL " . self::WINDOW_MINUTES . " MINUTE
                   AND $column = ?"
            );
            $query->execute([$value]);
            $row = $query->fetch();
            if ((int) $row['n'] >= $max) {
                $unlock = strtotime($row['oldest'] . ' UTC') + self::WINDOW_MINUTES * 60;
                return max(60, $unlock - time());
            }
        }
        return 0;
    }

    public static function record(string $identifier, string $ip, bool $success): void
    {
        $db = Database::get('one');
        $db->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)')
            ->execute([$identifier, $ip, $success ? 1 : 0]);

        if ($success) {
            // A successful login clears the identifier's failure streak.
            $db->prepare(
                'DELETE FROM login_attempts WHERE identifier = ? AND success = 0
                 AND attempted_at > UTC_TIMESTAMP() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE'
            )->execute([$identifier]);
        }
    }
}
