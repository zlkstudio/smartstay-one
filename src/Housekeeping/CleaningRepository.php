<?php
declare(strict_types=1);

namespace One\Housekeeping;

use One\Db\Database;
use PDO;
use PDOException;
use RuntimeException;

/**
 * smartconcept_cleaning — ported from housekeeping/includes/Database.php (mysqli → PDO).
 * maid_name holds the display name ("Ioana"), exactly as the legacy app writes it,
 * so payment reports keep working while both apps run side by side.
 */
final class CleaningRepository
{
    // ── Assignments ───────────────────────────────────────────────────────

    /**
     * Assigns apartments to a maid for a day. An apartment belongs to one maid per day:
     * other maids' *pending* rows for it are removed (in the legacy app the maid's list
     * came from the URL, so stale rows were harmless — in ONE her list comes from here).
     * @param list<string> $apartments
     */
    public static function assign(string $maidName, array $apartments, string $date): void
    {
        $pdo = self::db();
        $pdo->beginTransaction();
        try {
            $remove = $pdo->prepare(
                "DELETE FROM maid_assignments
                 WHERE apartment_number = ? AND assignment_date = ? AND maid_name <> ? AND status = 'pending'"
            );
            $insert = $pdo->prepare(
                "INSERT INTO maid_assignments (maid_name, apartment_number, assignment_date, status)
                 VALUES (?, ?, ?, 'pending')
                 ON DUPLICATE KEY UPDATE status = IF(status = 'completed', 'completed', 'pending')"
            );
            foreach ($apartments as $apartment) {
                $remove->execute([$apartment, $date, $maidName]);
                $insert->execute([$maidName, $apartment, $date]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string, list<array{maid:string,status:string}>> apartment => assignments */
    public static function assignmentsForDate(string $date): array
    {
        $stmt = self::db()->prepare(
            'SELECT apartment_number, maid_name, status FROM maid_assignments
             WHERE assignment_date = ? ORDER BY created_at'
        );
        $stmt->execute([$date]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['apartment_number']][] = ['maid' => $row['maid_name'], 'status' => (string) $row['status']];
        }
        return $out;
    }

    /** Apartments assigned to one maid on a day (any status), natural order. @return list<string> */
    public static function apartmentsOf(string $maidName, string $date): array
    {
        $stmt = self::db()->prepare(
            'SELECT DISTINCT apartment_number FROM maid_assignments WHERE maid_name = ? AND assignment_date = ?'
        );
        $stmt->execute([$maidName, $date]);
        $apartments = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        natsort($apartments);
        return array_values($apartments);
    }

    /** First maid assigned to an apartment on a day (who a manager-submitted checklist belongs to). */
    public static function maidFor(string $apartment, string $date): ?string
    {
        $stmt = self::db()->prepare(
            'SELECT maid_name FROM maid_assignments WHERE apartment_number = ? AND assignment_date = ? ORDER BY created_at LIMIT 1'
        );
        $stmt->execute([$apartment, $date]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    /** @return list<string> */
    public static function pendingOf(string $maidName, string $date): array
    {
        $stmt = self::db()->prepare(
            "SELECT apartment_number FROM maid_assignments WHERE maid_name = ? AND assignment_date = ? AND status = 'pending'"
        );
        $stmt->execute([$maidName, $date]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── Cleaning records (what the maid is paid for) ─────────────────────

    /**
     * Deduplicated on (maid, apartment, date, type): a check-out and an intermediate on the same day both count.
     * @return bool true when a new record was written, false when it already existed
     */
    public static function recordCleaning(string $maid, string $apartment, string $date, string $type = 'checkout', ?string $reservationId = null): bool
    {
        $pdo = self::db();
        $exists = $pdo->prepare(
            'SELECT id FROM cleaning_records WHERE maid_name = ? AND apartment_number = ? AND cleaning_date = ? AND cleaning_type = ?'
        );
        $exists->execute([$maid, $apartment, $date, $type]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        $pdo->prepare(
            "INSERT INTO cleaning_records (maid_name, apartment_number, cleaning_date, cleaning_type, reservation_id, status)
             VALUES (?, ?, ?, ?, ?, 'completed')"
        )->execute([$maid, $apartment, $date, $type, $reservationId]);
        return true;
    }

    /** One payment line (for the report's delete action). @return array<string,mixed>|null */
    public static function findRecord(int $id): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT id, maid_name, apartment_number, cleaning_date, cleaning_type FROM cleaning_records WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Removes a payment line only. Checklist history (checklist_submissions) stays untouched. */
    public static function deleteRecord(int $id): bool
    {
        $stmt = self::db()->prepare('DELETE FROM cleaning_records WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<string> apartments with an intermediate cleaning on that day */
    public static function intermediatesOn(string $date): array
    {
        $stmt = self::db()->prepare(
            "SELECT apartment_number, maid_name FROM cleaning_records WHERE cleaning_date = ? AND cleaning_type = 'intermediate'"
        );
        $stmt->execute([$date]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['apartment_number']] = (string) $row['maid_name'];
        }
        return $out;
    }

    public static function markAssignmentCompleted(string $maid, string $apartment, string $date): void
    {
        self::db()->prepare(
            "UPDATE maid_assignments SET status = 'completed' WHERE maid_name = ? AND apartment_number = ? AND assignment_date = ?"
        )->execute([$maid, $apartment, $date]);
    }

    // ── Checklist submissions (max 2 per apartment + day) ────────────────

    /** @param list<string> $apartments @return array<string,int> */
    public static function submissionCounts(array $apartments, string $date): array
    {
        $counts = array_fill_keys(array_map('strval', $apartments), 0);
        if (!$apartments) {
            return $counts;
        }
        $stmt = self::db()->prepare(
            'SELECT apartment_number, COUNT(*) AS c FROM checklist_submissions
             WHERE cleaning_date = ? AND apartment_number IN (' . implode(',', array_fill(0, count($apartments), '?')) . ')
             GROUP BY apartment_number'
        );
        $stmt->execute([$date, ...array_map('strval', $apartments)]);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['apartment_number']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * Registers a submission. The UNIQUE(apartment, date, submission_number) key makes the
     * limit atomic. @return int 1 or 2 @throws RuntimeException code 409 when the limit is reached
     */
    public static function addSubmission(string $maid, string $apartment, string $date, ?string $reservationId, string $submittedBy): int
    {
        $next = (self::submissionCounts([$apartment], $date)[$apartment] ?? 0) + 1;
        if ($next > Checklist::MAX_SUBMISSIONS) {
            throw new RuntimeException('Checklist limit reached', 409);
        }
        try {
            self::db()->prepare(
                'INSERT INTO checklist_submissions
                 (maid_name, apartment_number, cleaning_date, reservation_id, submission_number, submitted_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$maid, $apartment, $date, $reservationId, $next, mb_substr($submittedBy, 0, 50)]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Checklist limit reached', 409);
            }
            throw $e;
        }
        return $next;
    }

    private static function db(): PDO
    {
        return Database::get('cleaning');
    }
}
