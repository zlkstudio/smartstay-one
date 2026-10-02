<?php
declare(strict_types=1);

namespace One\Reports;

use One\Db\Database;
use Throwable;

/**
 * smartconcept_one.report_cache — computed report payloads, keyed by (report, period).
 * Written by bin/reports-cron.php (hourly) and on demand; read by Rapoarte and Home.
 * A cache failure never breaks a report: callers fall back to computing live.
 */
final class ReportCache
{
    /** @return array<string,mixed>|null payload when present and younger than $maxAge seconds */
    public static function get(string $key, string $start, string $end, int $maxAge): ?array
    {
        try {
            $stmt = Database::get('one')->prepare(
                'SELECT payload FROM report_cache
                 WHERE report_key = ? AND period_start = ? AND period_end = ?
                   AND computed_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            );
            $stmt->execute([$key, $start, $end, max(0, $maxAge)]);
            $json = $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('[ONE] report_cache read: ' . $e->getMessage());
            return null;
        }
        $data = $json === false ? null : json_decode((string) $json, true);
        return is_array($data) ? $data : null;
    }

    public static function put(string $key, string $start, string $end, array $payload): void
    {
        try {
            Database::get('one')->prepare(
                'INSERT INTO report_cache (report_key, period_start, period_end, payload, computed_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), computed_at = VALUES(computed_at)'
            )->execute([$key, $start, $end, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            error_log('[ONE] report_cache write: ' . $e->getMessage());
        }
    }

    /** UTC time of the newest payload for a report, null when never computed. */
    public static function lastComputed(string $key): ?string
    {
        try {
            $stmt = Database::get('one')->prepare('SELECT MAX(computed_at) FROM report_cache WHERE report_key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Drops payloads older than $days (each day gets a new period, so rows accumulate). */
    public static function prune(int $days = 30): int
    {
        $stmt = Database::get('one')->prepare('DELETE FROM report_cache WHERE computed_at < UTC_TIMESTAMP() - INTERVAL ? DAY');
        $stmt->execute([max(1, $days)]);
        return $stmt->rowCount();
    }
}
