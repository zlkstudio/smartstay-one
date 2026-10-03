<?php
declare(strict_types=1);

namespace One\Notify;

use One\Audit;
use One\Db\Database;
use One\Inventory\InventoryRepository;

/**
 * Human text for audit_log rows — one source for the Jurnal page and for push notifications,
 * so what the admin reads on the phone is exactly what the log says.
 */
final class Activity
{
    /** Module filters on the Jurnal page: key => [label, action LIKE pattern]. */
    public const FILTERS = [
        'all'          => ['Toate', null],
        'housekeeping' => ['Curățenie', 'housekeeping.%'],
        'inventory'    => ['Inventar', 'inventory.%'],
        'reservations' => ['Rezervări', 'reservation.%'],
        'reports'      => ['Rapoarte', 'report.%'],
        'accounts'     => ['Conturi', 'user.%'],
    ];

    /**
     * @param array{action:string, target_id:?string, meta:mixed, actor_name:?string} $row
     * @return array{title:string, body:string, url:string}
     */
    public static function describe(array $row): array
    {
        $meta = is_array($row['meta']) ? $row['meta'] : (json_decode((string) ($row['meta'] ?? ''), true) ?: []);
        $who = trim((string) ($row['actor_name'] ?? '')) ?: 'Sistem';
        $apt = (string) ($row['target_id'] ?? '');
        $item = static fn(string $key): string => InventoryRepository::ITEMS[$key] ?? $key;

        switch ($row['action']) {
            case 'housekeeping.checklist':
                $maid = (string) ($meta['maid'] ?? $who);
                $body = ($maid !== $who ? "$who, pentru $maid" : $maid) . ' a trimis checklist-ul';
                if ((int) ($meta['submission'] ?? 1) > 1) {
                    $body .= ' (a doua trecere — verificare)';
                }
                if (array_key_exists('mailed', $meta) && !$meta['mailed']) {
                    $body .= '. E-mailul cu poze NU a plecat';
                }
                return ['title' => "Checklist · Apt $apt", 'body' => $body . '.', 'url' => '/reports/payments'];

            case 'inventory.adjust':
                $delta = (int) ($meta['delta'] ?? 0);
                return ['title' => "Inventar · Apt $apt", 'url' => '/inventory',
                    'body' => sprintf('%s: %s %s%d (acum %d)', $who, $item((string) ($meta['item'] ?? '')),
                        $delta > 0 ? '+' : '−', abs($delta), (int) ($meta['value'] ?? 0))];

            case 'inventory.batch':
                $op = ['set' => 'a scăzut un set', 'box' => 'a adăugat o cutie', 'undo' => 'a anulat ultima operație'][$meta['op'] ?? ''] ?? 'operație stoc';
                $parts = [];
                foreach ((array) ($meta['applied'] ?? []) as $key => $d) {
                    if ((int) $d !== 0) {
                        $parts[] = $item((string) $key) . ' ' . ((int) $d > 0 ? '+' : '−') . abs((int) $d);
                    }
                }
                return ['title' => "Inventar · Apt $apt", 'url' => '/inventory',
                    'body' => "$who $op" . ($parts ? ': ' . implode(', ', $parts) : '') . '.'];

            case 'inventory.note':
                $text = trim((string) ($meta['text'] ?? ''));
                return ['title' => "Necesar · Apt $apt", 'url' => '/inventory',
                    'body' => $who . ($text !== '' ? ": „{$text}”" : ' a golit „Necesar”.')];

            case 'inventory.tech':
                $bits = [];
                if (array_key_exists('tvApp', $meta)) {
                    $bits[] = 'TV App ' . ($meta['tvApp'] ? 'bifat' : 'debifat');
                }
                if (array_key_exists('text', $meta)) {
                    $bits[] = trim((string) $meta['text']) !== '' ? 'Tehnic: „' . $meta['text'] . '”' : 'a golit nota Tehnic';
                } elseif (array_key_exists('length', $meta)) {
                    $bits[] = 'a modificat nota Tehnic';
                }
                return ['title' => "Tehnic · Apt $apt", 'url' => '/inventory',
                    'body' => $who . ': ' . ($bits ? implode(', ', $bits) : 'modificare') . '.'];
        }

        $label = Audit::ACTION_LABELS[$row['action']] ?? $row['action'];
        $name = trim((string) ($row['target_name'] ?? ''));
        $target = $name !== '' ? ' ' . $name : ($apt !== '' ? ' · ' . $apt : '');
        return ['title' => $label . $target, 'body' => $who, 'url' => '/activity'];
    }

    /**
     * Inventory +/− taps by one user on one apartment in the last minutes, summed per item —
     * so the push shows "Lenjerii +3 (acum 7)" once instead of three separate notifications.
     */
    public static function inventoryBurst(int $userId, string $apartment, int $minutes = 5): ?string
    {
        try {
            $stmt = Database::get('one')->prepare(
                'SELECT meta FROM audit_log WHERE user_id = ? AND action = \'inventory.adjust\' AND target_id = ?
                   AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE ORDER BY id'
            );
            $stmt->execute([$userId, $apartment, $minutes]);
        } catch (\Throwable) {
            return null;
        }
        $sum = [];
        $now = [];
        foreach ($stmt->fetchAll() as $row) {
            $m = json_decode((string) $row['meta'], true) ?: [];
            $key = (string) ($m['item'] ?? '');
            $sum[$key] = ($sum[$key] ?? 0) + (int) ($m['delta'] ?? 0);
            $now[$key] = (int) ($m['value'] ?? 0);
        }
        $parts = [];
        foreach ($sum as $key => $d) {
            $label = InventoryRepository::ITEMS[$key] ?? $key;
            $parts[] = $d === 0 ? "$label neschimbat (acum {$now[$key]})"
                : sprintf('%s %s%d (acum %d)', $label, $d > 0 ? '+' : '−', abs($d), $now[$key]);
        }
        return $parts ? implode(' · ', $parts) : null;
    }

    /**
     * Jurnal page rows, newest first.
     * @return list<array<string,mixed>>
     */
    public static function recent(string $filter = 'all', int $limit = 150, ?int $beforeId = null): array
    {
        $pattern = self::FILTERS[$filter][1] ?? null;
        $sql = 'SELECT a.id, a.action, a.target_type, a.target_id, a.meta, a.created_at, u.name AS actor_name,
                       t.name AS target_name
                FROM audit_log a
                LEFT JOIN users u ON u.id = a.user_id
                LEFT JOIN users t ON a.target_type = \'user\' AND t.id = a.target_id
                WHERE a.action NOT LIKE \'auth.%\'';
        $args = [];
        if ($pattern !== null) {
            $sql .= ' AND a.action LIKE ?';
            $args[] = $pattern;
        }
        if ($beforeId !== null) {
            $sql .= ' AND a.id < ?';
            $args[] = $beforeId;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . max(1, min(300, $limit));
        $stmt = Database::get('one')->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }
}
