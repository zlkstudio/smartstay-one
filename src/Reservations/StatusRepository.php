<?php
declare(strict_types=1);

namespace One\Reservations;

use InvalidArgumentException;
use One\Db\Database;
use PDO;
use PDOException;

/**
 * reservation_status (city_tax_paid, checkin_completed, notes) + reservation_status_log,
 * in the legacy Reservations database. Ported 1:1 from reservations/includes/ReservationStatus.php.
 * The legacy app keeps working on the same rows during the transition.
 */
final class StatusRepository
{
    public const FLAGS = ['city_tax_paid', 'checkin_completed'];

    public function __construct(private readonly string $userName)
    {
    }

    /** @param list<string> $ids @return array<string, array{city_tax_paid:bool,checkin_completed:bool,notes:?string,updated_at:?string}> */
    public function getMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('strval', $ids),
            static fn(string $id): bool => (bool) preg_match('/^[A-Za-z0-9_-]{1,50}$/', $id)
        )));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = self::empty();
        }
        if (!$ids) {
            return $out;
        }
        $stmt = self::db()->prepare(
            'SELECT reservation_id, city_tax_paid, checkin_completed, notes, updated_at
             FROM reservation_status WHERE reservation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['reservation_id']] = self::shape($row);
        }
        return $out;
    }

    public function get(string $id): array
    {
        return $this->getMany([$id])[$id] ?? self::empty();
    }

    public function setFlag(string $id, string $field, bool $value): array
    {
        if (!in_array($field, self::FLAGS, true)) {
            throw new InvalidArgumentException("Câmp invalid: $field");
        }
        $old = $this->get($id)[$field];

        // $field is whitelisted above — never user input in the SQL.
        self::db()->prepare(
            "INSERT INTO reservation_status (reservation_id, $field, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE $field = VALUES($field), updated_by = VALUES(updated_by)"
        )->execute([$id, $value ? 1 : 0, $this->userName]);

        if ($old !== $value) {
            $this->log($id, $field, $old ? '1' : '0', $value ? '1' : '0');
        }
        return $this->get($id);
    }

    public function setNotes(string $id, ?string $notes): array
    {
        self::db()->prepare(
            'INSERT INTO reservation_status (reservation_id, notes, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE notes = VALUES(notes), updated_by = VALUES(updated_by)'
        )->execute([$id, $notes, $this->userName]);
        return $this->get($id);
    }

    /** Best-effort, like the legacy app: a missing log table never blocks the toggle. */
    private function log(string $id, string $field, string $old, string $new): void
    {
        try {
            self::db()->prepare(
                'INSERT INTO reservation_status_log (reservation_id, field, old_value, new_value, changed_by, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$id, $field, $old, $new, $this->userName, client_ip()]);
        } catch (PDOException $e) {
            error_log('[ONE] reservation_status_log: ' . $e->getMessage());
        }
    }

    private static function empty(): array
    {
        return ['city_tax_paid' => false, 'checkin_completed' => false, 'notes' => null, 'updated_at' => null];
    }

    private static function shape(array $row): array
    {
        return [
            'city_tax_paid'     => (bool) $row['city_tax_paid'],
            'checkin_completed' => (bool) $row['checkin_completed'],
            'notes'             => $row['notes'],
            'updated_at'        => $row['updated_at'],
        ];
    }

    private static function db(): PDO
    {
        return Database::get('reservations');
    }
}
