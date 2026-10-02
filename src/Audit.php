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
        // Etapa 2 — module actions (shown in module history, not on the Users page)
        'reservation.status'      => 'A modificat statusul rezervării',
        'reservation.nuki'        => 'A trimis codul Nuki',
        'housekeeping.assign'     => 'A alocat curățenii',
        'housekeeping.intermediate' => 'A înregistrat o curățenie intermediară',
        'housekeeping.checklist'  => 'A trimis checklist-ul',
        // Etapa 3
        'inventory.adjust'        => 'A modificat stocul',
        'inventory.note'          => 'A modificat „Necesar"',
        'report.cleaning_add'     => 'A adăugat o curățenie în raport',
        'report.cleaning_delete'  => 'A șters o curățenie din raport',
        'report.refresh'          => 'A recalculat rapoartele',
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
             WHERE (a.action LIKE \'user.%\' OR a.action LIKE \'permission.%\' OR a.action = \'auth.password_change\')
             ORDER BY a.id DESC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Latest action per target (e.g. who last touched each apartment's stock).
     * @return array<string, array{by:?string, at:string}> target_id => last change
     */
    public static function latestFor(string $targetType, int $days = 60): array
    {
        try {
            $stmt = Database::get('one')->prepare(
                'SELECT a.target_id, a.created_at, u.name
                 FROM audit_log a
                 JOIN (SELECT target_id, MAX(id) AS id FROM audit_log
                       WHERE target_type = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ? DAY
                       GROUP BY target_id) m ON m.id = a.id
                 LEFT JOIN users u ON u.id = a.user_id'
            );
            $stmt->execute([$targetType, max(1, $days)]);
        } catch (\Throwable $e) {
            error_log('[ONE] audit read failed: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['target_id']] = ['by' => $row['name'], 'at' => (string) $row['created_at']];
        }
        return $out;
    }
}
