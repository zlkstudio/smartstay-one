<?php
declare(strict_types=1);

namespace One;

use One\Db\Database;

final class Audit
{
    public const ACTION_LABELS = [
        'auth.login'          => 'S-a autentificat',
        'auth.logout'         => 'S-a deconectat',
        'auth.password_change'=> 'Și-a schimbat parola',
        'user.create'         => 'A creat contul',
        'user.update'         => 'A modificat contul',
        'user.password_reset' => 'A resetat parola pentru',
        'user.deactivate'     => 'A dezactivat contul',
        'user.activate'       => 'A reactivat contul',
        'permission.update'   => 'A modificat permisiunile pentru',
    ];

    public static function log(
        ?int $userId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $meta = []
    ): void {
        try {
            Database::get('one')->prepare(
                'INSERT INTO audit_log (user_id, action, target_type, target_id, meta, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                $action,
                $targetType,
                $targetId,
                $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                PHP_SAPI === 'cli' ? 'cli' : client_ip(),
            ]);
        } catch (\Throwable $e) {
            // Audit must never break the action it records.
            error_log('[ONE] audit write failed: ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        $stmt = Database::get('one')->prepare(
            'SELECT a.*, u.name AS actor_name, t.name AS target_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN users t ON a.target_type = \'user\' AND t.id = a.target_id
             WHERE a.action NOT IN (\'auth.login\', \'auth.logout\')
             ORDER BY a.id DESC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
