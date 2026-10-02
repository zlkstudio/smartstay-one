<?php
declare(strict_types=1);

namespace One\Inventory;

use InvalidArgumentException;
use One\Db\Database;
use PDO;

/**
 * smartconcept_inventoryStay.inventar_apartamente — same rows the legacy Inventory app uses,
 * so both apps stay in sync while they run side by side.
 *
 * ONE writes only the stock counters and the "Necesar" note. The legacy Previo columns
 * (check_in_date, guest_name, …) are left alone: ONE reads occupancy live from Previo.
 */
final class InventoryRepository
{
    /** column => label. The whitelist for every write: column names never come from the browser. */
    public const ITEMS = [
        'lenjerie'          => 'Lenjerii',
        'fete_perne_mari'   => 'Fețe de pernă',
        'prosoape_mari'     => 'Prosoape mari',
        'prosoape_mici'     => 'Prosoape mici',
        'prosoape_picioare' => 'Prosoape picioare',
    ];

    public const NOTE_MAX = 2000;

    /** @return list<array{apartment:string, items:array<string,int>, note:string}> */
    public static function all(): array
    {
        $rows = self::db()->query(
            'SELECT apartament, ' . implode(', ', array_keys(self::ITEMS)) . ', necesar FROM inventar_apartamente'
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $items = [];
            foreach (array_keys(self::ITEMS) as $col) {
                $items[$col] = (int) ($row[$col] ?? 0);
            }
            $out[] = [
                'apartment' => trim((string) $row['apartament']),
                'items'     => $items,
                'note'      => (string) ($row['necesar'] ?? ''),
            ];
        }
        return $out;
    }

    /** Apartments with linen at the critical level (Home indicator). @return list<string> */
    public static function critical(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            if (Stock::level($row['apartment'], $row['items']['lenjerie']) === 'critical') {
                $out[] = $row['apartment'];
            }
        }
        natsort($out);
        return array_values($out);
    }

    /**
     * +1 / −1, atomic in one UPDATE (two people tapping at once both count), never below zero.
     * The legacy app read the value, then wrote value ± 1 — that lost taps under concurrency.
     * @return int|null the new value, null when the apartment does not exist
     */
    public static function adjust(string $apartment, string $item, int $delta): ?int
    {
        if (!isset(self::ITEMS[$item]) || ($delta !== 1 && $delta !== -1)) {
            throw new InvalidArgumentException('Articol sau pas invalid.');
        }
        $col = '`' . $item . '`';
        $pdo = self::db();
        $pdo->prepare($delta > 0
            ? "UPDATE inventar_apartamente SET $col = COALESCE($col, 0) + 1 WHERE apartament = ?"
            : "UPDATE inventar_apartamente SET $col = $col - 1 WHERE apartament = ? AND $col > 0"
        )->execute([$apartment]);

        $stmt = $pdo->prepare("SELECT COALESCE($col, 0) FROM inventar_apartamente WHERE apartament = ?");
        $stmt->execute([$apartment]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @return bool false when the apartment does not exist */
    public static function setNote(string $apartment, string $note): bool
    {
        if (!self::exists($apartment)) {
            return false;
        }
        $note = rtrim(mb_substr($note, 0, self::NOTE_MAX));
        self::db()->prepare('UPDATE inventar_apartamente SET necesar = ? WHERE apartament = ?')
            ->execute([$note === '' ? null : $note, $apartment]);
        return true;
    }

    public static function exists(string $apartment): bool
    {
        $stmt = self::db()->prepare('SELECT 1 FROM inventar_apartamente WHERE apartament = ?');
        $stmt->execute([$apartment]);
        return $stmt->fetchColumn() !== false;
    }

    private static function db(): PDO
    {
        return Database::get('inventory');
    }
}
