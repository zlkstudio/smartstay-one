<?php
declare(strict_types=1);

namespace One\Reports;

use One\Db\Database;
use One\Housekeeping\CleaningTracker;

/**
 * Rapoarte · Timpi curățenie — how long check-out cleanings really take, by length of stay
 * and studio / apartment, against the targets in CleaningTracker::TARGETS. Source: cleaning_sessions.
 * Sessions under 5 min or over 4 h are left out of the averages (a lock missed by Nuki, a door left open)
 * and counted separately so nothing disappears silently.
 */
final class CleaningTimes
{
    private const MIN = 5;
    private const MAX = 240;

    /** @return array<string,mixed> */
    public static function build(string $from, string $to): array
    {
        $stmt = Database::get('one')->prepare(
            'SELECT cleaning_date, apartment, maid_name, nights, unit, target_min, started_at, ended_at, duration_min
             FROM cleaning_sessions WHERE cleaning_date BETWEEN ? AND ? ORDER BY started_at DESC'
        );
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll();

        $first = Database::get('one')->query('SELECT MIN(cleaning_date) FROM cleaning_sessions')->fetchColumn();

        $valid = [];
        $open = 0;
        $outliers = 0;
        foreach ($rows as $r) {
            if ($r['duration_min'] === null) {
                $open++;
                continue;
            }
            $m = (int) $r['duration_min'];
            if ($m < self::MIN || $m > self::MAX) {
                $outliers++;
                continue;
            }
            $valid[] = [
                'date'      => (string) $r['cleaning_date'],
                'apartment' => (string) $r['apartment'],
                'maid'      => (string) $r['maid_name'],
                'nights'    => $r['nights'] !== null ? (int) $r['nights'] : null,
                'bucket'    => CleaningTracker::bucketFor($r['nights'] !== null ? (int) $r['nights'] : null),
                'unit'      => (string) $r['unit'],
                'target'    => (int) $r['target_min'],
                'minutes'   => $m,
                'from'      => substr((string) $r['started_at'], 11, 5),
                'to'        => substr((string) $r['ended_at'], 11, 5),
            ];
        }

        // Matrix: length of stay × studio / apartment, in the order of the targets.
        $matrix = [];
        foreach (CleaningTracker::BUCKETS as $bucket => $label) {
            foreach (['studio', 'apartment'] as $unit) {
                $nights = ['1' => 1, '2-3' => 2, '4-5' => 4, '6+' => 6][$bucket];
                $set = array_filter($valid, static fn(array $v): bool => $v['bucket'] === $bucket && $v['unit'] === $unit);
                $matrix[] = ['bucket' => $bucket, 'label' => $label, 'unit' => $unit,
                    'target' => CleaningTracker::targetFor($nights, $unit)] + self::stats(array_values($set));
            }
        }
        $unknownStay = array_values(array_filter($valid, static fn(array $v): bool => $v['bucket'] === null));

        $maids = [];
        foreach ($valid as $v) {
            $maids[$v['maid']][] = $v;
        }
        ksort($maids);
        $maids = array_map(static fn(array $set, string $name): array => ['name' => $name] + self::stats($set), $maids, array_keys($maids));

        return [
            'from'        => $from,
            'to'          => $to,
            'since'       => $first !== false && $first !== null ? (string) $first : null,
            'total'       => self::stats($valid),
            'matrix'      => $matrix,
            'unknownStay' => count($unknownStay),
            'maids'       => $maids,
            'sessions'    => array_slice($valid, 0, 30),
            'open'        => $open,
            'outliers'    => $outliers,
        ];
    }

    /**
     * @param list<array{minutes:int, target:int}> $set
     * @return array{count:int, avg:?float, median:?float, min:?int, max:?int, over:int, overPct:?int, suggest:?int}
     */
    private static function stats(array $set): array
    {
        $n = count($set);
        if ($n === 0) {
            return ['count' => 0, 'avg' => null, 'median' => null, 'min' => null, 'max' => null, 'over' => 0, 'overPct' => null, 'suggest' => null];
        }
        $minutes = array_column($set, 'minutes');
        sort($minutes);
        $mid = intdiv($n, 2);
        $median = $n % 2 ? (float) $minutes[$mid] : ($minutes[$mid - 1] + $minutes[$mid]) / 2;
        $over = count(array_filter($set, static fn(array $v): bool => $v['minutes'] > $v['target']));
        return [
            'count'   => $n,
            'avg'     => array_sum($minutes) / $n,
            'median'  => $median,
            'min'     => $minutes[0],
            'max'     => $minutes[$n - 1],
            'over'    => $over,
            'overPct' => (int) round($over * 100 / $n),
            // Target the data supports: the median rounded up to 5 min — only with enough cleanings to mean something.
            'suggest' => $n >= 5 ? (int) (ceil($median / 5) * 5) : null,
        ];
    }
}
