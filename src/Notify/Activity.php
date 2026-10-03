<?php
declare(strict_types=1);

namespace One\Notify;

use One\Audit;
use One\Db\Database;
use One\Inventory\InventoryRepository;
use One\Inventory\Stock;

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
                $red = self::linenTurnedRed('inventory.adjust', $apt, $meta) !== null ? ' — pe roșu' : '';
                return ['title' => "Inventar · Apt $apt", 'url' => '/inventory',
                    'body' => sprintf('%s: %s %s%d (acum %d)%s', $who, $item((string) ($meta['item'] ?? '')),
                        $delta > 0 ? '+' : '−', abs($delta), (int) ($meta['value'] ?? 0), $red)];

            case 'inventory.batch':
                $op = ['set' => 'a scăzut un set', 'box' => 'a adăugat o cutie', 'undo' => 'a anulat ultima operație'][$meta['op'] ?? ''] ?? 'operație stoc';
                $parts = [];
                foreach ((array) ($meta['applied'] ?? []) as $key => $d) {
                    if ((int) $d !== 0) {
                        $parts[] = $item((string) $key) . ' ' . ((int) $d > 0 ? '+' : '−') . abs((int) $d);
                    }
                }
                return ['title' => "Inventar · Apt $apt", 'url' => '/inventory',
                    'body' => "$who $op" . ($parts ? ': ' . implode(', ', $parts) : '')
                        . (self::linenTurnedRed('inventory.batch', $apt, $meta) !== null ? ' — lenjerii pe roșu' : '') . '.'];

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
     * Lenjerii remaining when this inventory action moved the apartment INTO the red zone
     * (Stock::level critical now, not critical before). null otherwise. Works for +/− and set/box/undo.
     */
    public static function linenTurnedRed(string $action, string $apartment, array $meta): ?int
    {
        if ($action === 'inventory.adjust' && ($meta['item'] ?? '') === 'lenjerie') {
            $after = (int) ($meta['value'] ?? 0);
            $before = $after - (int) ($meta['delta'] ?? 0);
        } elseif ($action === 'inventory.batch' && isset($meta['values']['lenjerie'])) {
            $after = (int) $meta['values']['lenjerie'];
            $before = $after - (int) ($meta['applied']['lenjerie'] ?? 0);
        } else {
            return null;
        }
        if ($after >= $before || $apartment === '') {
            return null;
        }
        return Stock::level($apartment, $after) === 'critical' && Stock::level($apartment, $before) !== 'critical'
            ? $after : null;
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
