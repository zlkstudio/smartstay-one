<?php
declare(strict_types=1);

namespace One\Notify;

use One\Db\Database;

/** Devices subscribed to push (table push_subscriptions in smartconcept_one). */
final class PushSubscriptions
{
    /** Push services we accept endpoints from — the server POSTs to these URLs, so never an arbitrary host. */
    private const HOSTS = [
        'fcm.googleapis.com', 'android.googleapis.com', 'web.push.apple.com',
        'updates.push.services.mozilla.com', 'push.services.mozilla.com',
    ];
    private const HOST_SUFFIXES = ['.push.apple.com', '.notify.windows.com', '.push.services.mozilla.com'];

    public static function validEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || strlen($endpoint) > 1000) {
            return false;
        }
        $host = strtolower($p['host']);
        if (in_array($host, self::HOSTS, true)) {
            return true;
        }
        foreach (self::HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        return false;
    }

    public static function save(int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): void
    {
        Database::get('one')->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth),
                                     user_agent = VALUES(user_agent)'
        )->execute([$userId, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, mb_substr($userAgent, 0, 255)]);
    }

    public static function delete(int $userId, string $endpoint): void
    {
        Database::get('one')->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?')
            ->execute([$userId, hash('sha256', $endpoint)]);
    }

    public static function countFor(int $userId): int
    {
        $stmt = Database::get('one')->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Devices of active users with one of $roles (optionally one user only).
     * @param list<string> $roles
     * @return array<int, array{endpoint:string, p256dh:string, auth:string, user_id:int}> keyed by id
     */
    public static function forRoles(array $roles, ?int $exceptUserId = null, ?int $onlyUserId = null): array
    {
        if (!$roles) {
            return [];
        }
        $sql = 'SELECT s.id, s.user_id, s.endpoint, s.p256dh, s.auth FROM push_subscriptions s
                JOIN users u ON u.id = s.user_id
                WHERE u.active = 1 AND u.role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
        $args = array_values($roles);
        if ($exceptUserId !== null) {
            $sql .= ' AND s.user_id <> ?';
            $args[] = $exceptUserId;
        }
        if ($onlyUserId !== null) {
            $sql .= ' AND s.user_id = ?';
            $args[] = $onlyUserId;
        }
        $stmt = Database::get('one')->prepare($sql);
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = [
                'endpoint' => (string) $row['endpoint'], 'p256dh' => (string) $row['p256dh'],
                'auth' => (string) $row['auth'], 'user_id' => (int) $row['user_id'],
            ];
        }
        return $out;
    }

    /** @param array<int,int> $results subscription id => HTTP status */
    public static function applyResults(array $results): void
    {
        $db = Database::get('one');
        $gone = array_keys(array_filter($results, static fn(int $s): bool => $s === 404 || $s === 410));
        $ok = array_keys(array_filter($results, static fn(int $s): bool => $s >= 200 && $s < 300));
        if ($gone) {
            $db->prepare('DELETE FROM push_subscriptions WHERE id IN (' . implode(',', array_fill(0, count($gone), '?')) . ')')
                ->execute($gone);
        }
        if ($ok) {
            $db->prepare('UPDATE push_subscriptions SET last_success_at = UTC_TIMESTAMP() WHERE id IN ('
                . implode(',', array_fill(0, count($ok), '?')) . ')')->execute($ok);
        }
    }
}
