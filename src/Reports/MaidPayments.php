<?php
declare(strict_types=1);

namespace One\Reports;

use One\Db\Database;
use One\Housekeeping\Rates;

/**
 * Maid payment report — port of housekeeping/includes/WeeklyPaymentReport.php.
 * Source: smartconcept_cleaning.cleaning_records (the single source for payments).
 * Rates are recomputed from Housekeeping\Rates on every view. "✓✓ x2" comes from
 * checklist_submissions and is shown only here, never to the maids.
 */
final class MaidPayments
{
    /**
     * @return array{from:string, to:string, total:int, count:int, unknown:list<string>,
     *   maids:list<array{name:string, key:?string, total:int, checkouts:int, intermediates:int, lines:list<array>}>}
     */
    public static function build(string $from, string $to, ?string $onlyMaid = null): array
    {
        $pdo = Database::get('cleaning');

        // $onlyMaid ("Ioana"): contul de Menajeră vede doar rândurile ei — filtrat în SQL, nu în view.
        $stmt = $pdo->prepare(
            'SELECT id, maid_name, apartment_number, cleaning_date, cleaning_type
             FROM cleaning_records
             WHERE cleaning_date BETWEEN ? AND ?' . ($onlyMaid !== null ? ' AND maid_name = ?' : '') . '
             ORDER BY cleaning_date, apartment_number'
        );
        $stmt->execute($onlyMaid !== null ? [$from, $to, $onlyMaid] : [$from, $to]);
        $records = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT apartment_number, cleaning_date, COUNT(*) AS c FROM checklist_submissions
             WHERE cleaning_date BETWEEN ? AND ? GROUP BY apartment_number, cleaning_date'
        );
        $stmt->execute([$from, $to]);
        $checklists = [];
        foreach ($stmt->fetchAll() as $row) {
            $checklists[$row['apartment_number'] . '|' . $row['cleaning_date']] = (int) $row['c'];
        }

        $keys = array_flip(array_map('strval', config('maids', [])));   // "Ioana" => "ioana"
        $maids = [];
        $unknown = [];
        $total = 0;
        foreach ($records as $r) {
            $type = (string) ($r['cleaning_type'] ?: 'checkout');
            $apartment = trim((string) $r['apartment_number']);
            $date = (string) $r['cleaning_date'];
            ['rate' => $rate, 'known' => $known] = Rates::rateFor($apartment, $type);
            if (!$known) {
                $unknown[$apartment] = true;
            }

            $name = (string) $r['maid_name'];
            $maids[$name] ??= [
                'name' => $name, 'key' => $keys[$name] ?? null,
                'total' => 0, 'checkouts' => 0, 'intermediates' => 0, 'lines' => [],
            ];
            $maids[$name]['lines'][] = [
                'id'        => (int) $r['id'],
                'date'      => $date,
                'apartment' => $apartment,
                'type'      => $type,
                'rate'      => $rate,
                'known'     => $known,
                'double'    => $type === 'checkout' && ($checklists["$apartment|$date"] ?? 0) >= 2,
            ];
            $maids[$name]['total'] += $rate;
            $maids[$name][$type === 'intermediate' ? 'intermediates' : 'checkouts']++;
            $total += $rate;
        }

        foreach ($maids as &$maid) {
            usort($maid['lines'], static fn(array $a, array $b): int =>
                strcmp($a['date'], $b['date']) ?: strnatcmp($a['apartment'], $b['apartment']));
        }
        unset($maid);

        // Configured maids first (config order), then anyone else found in the data.
        $order = array_flip(array_keys($keys));
        uasort($maids, static fn(array $a, array $b): int =>
            [$order[$a['name']] ?? PHP_INT_MAX, $a['name']] <=> [$order[$b['name']] ?? PHP_INT_MAX, $b['name']]);

        $unknown = array_keys($unknown);
        natsort($unknown);

        return [
            'from'    => $from,
            'to'      => $to,
            'total'   => $total,
            'count'   => count($records),
            'unknown' => array_values(array_map('strval', $unknown)),
            'maids'   => array_values($maids),
        ];
    }
}
