<?php
declare(strict_types=1);

namespace One\Reservations;

use One\Db\Database;

/** "Trimis" marks on the WhatsApp page, in smartconcept_one (cross-device, per message type). */
final class OutreachRepository
{
    public const TYPES = ['w1', 'w2', 'w3'];

    /** @param list<string> $ids @return array<string, array{at:string, by:?string}> keyed "resId_type" */
    public static function forReservations(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
        if (!$ids) {
            return [];
        }
        $stmt = Database::get('one')->prepare(
            'SELECT o.reservation_id, o.msg_type, o.sent_at, u.name AS sent_by_name
             FROM whatsapp_outreach o LEFT JOIN users u ON u.id = o.sent_by
             WHERE o.reservation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['reservation_id'] . '_' . $row['msg_type']] = [
                'at' => local_time($row['sent_at'], 'H:i'),
                'by' => $row['sent_by_name'],
            ];
        }
        return $out;
    }

    public static function mark(string $reservationId, string $type, ?string $platform, int $userId, bool $sent): void
    {
        $pdo = Database::get('one');
        if ($sent) {
            $pdo->prepare(
                'INSERT INTO whatsapp_outreach (reservation_id, msg_type, platform, sent_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE platform = VALUES(platform), sent_by = VALUES(sent_by), sent_at = UTC_TIMESTAMP()'
            )->execute([$reservationId, $type, $platform, $userId]);
        } else {
            $pdo->prepare('DELETE FROM whatsapp_outreach WHERE reservation_id = ? AND msg_type = ?')
                ->execute([$reservationId, $type]);
        }
    }
}
