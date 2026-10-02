<?php
declare(strict_types=1);

namespace One\Reports;

use One\Integrations\Previo;
use One\Properties;
use One\Stays;

/**
 * Operations overview from Previo stays: today (free / occupied / check-in / check-out),
 * occupancy for the last 30 days + next 14, and booking channels over the last 30 days.
 *
 * Cached in report_cache under KEY for the period [today − 29, today + 14]; the period moves
 * every day, so a cached payload is never "yesterday's today".
 */
final class OperationsReport
{
    /** Bumped when the payload rules change, so an old cached payload is never served. */
    public const KEY = 'operations_v4'; // v4: options counted, arrivals/departures done, Analytics
    public const PAST_DAYS = 30;
    public const NEXT_DAYS = 14;

    public const CHANNELS = [
        'booking_com' => ['label' => 'Booking.com', 'color' => '#2563eb'],
        'airbnb'      => ['label' => 'Airbnb', 'color' => '#e11d48'],
        'expedia'     => ['label' => 'Expedia', 'color' => '#d97706'],
        'travelminit' => ['label' => 'TravelMinit / Szállás', 'color' => '#0d9488'],
        'google'      => ['label' => 'Direct / altele', 'color' => '#7c3aed'],
    ];

    /** @return array{0:string, 1:string} cache period [start, end] */
    public static function period(): array
    {
        return [
            date('Y-m-d', strtotime('-' . (self::PAST_DAYS - 1) . ' days')),
            date('Y-m-d', strtotime('+' . self::NEXT_DAYS . ' days')),
        ];
    }

    /**
     * Cached payload when younger than $maxAge seconds, otherwise computed now and stored.
     * @throws \RuntimeException when Previo is unreachable and nothing usable is cached
     */
    public static function cached(int $maxAge = 3600, bool $freshPrevio = false): array
    {
        [$start, $end] = self::period();
        if ($maxAge > 0 && ($hit = ReportCache::get(self::KEY, $start, $end, $maxAge)) !== null) {
            return $hit;
        }
        $payload = self::build(Stays::window($freshPrevio));
        ReportCache::put(self::KEY, $start, $end, $payload);
        return $payload;
    }

    public static function build(array $stays): array
    {
        $today = date('Y-m-d');
        // Ap. 40 and "Daily" are not in the rented portfolio — out of every number below.
        $stays = array_values(array_filter($stays, static fn(array $s): bool => !Properties::isReportExcluded($s['apartment'])));
        ['source' => $source, 'apartments' => $roster] = Stays::roster($stays);
        $roster = array_values(array_filter($roster, static fn(string $a): bool => !Properties::isReportExcluded($a)));
        $inRoster = array_flip($roster);
        $total = count($roster);
        $stays = array_values(array_filter($stays, static fn(array $s): bool => isset($inRoster[$s['apartment']])));
        $analytics = new Analytics($stays, $roster, date('Y-m-d', strtotime('-' . (Stays::DAYS_BACK - 1) . ' days')), self::vatRate());

        // ── Today ──
        $occupied = Stays::occupiedOn($stays, $today);
        $free = array_values(array_diff($roster, $occupied));
        $arrivals = $arrivalsDone = $departures = $departuresDone = $ongoing = 0;
        foreach ($stays as $s) {
            $status = (string) ($s['status'] ?? '');
            if ($s['checkIn'] === $today) {
                $arrivals++;
                $arrivalsDone += in_array($status, [Previo::STATUS_CHECKED_IN, Previo::STATUS_CHECKED_OUT], true) ? 1 : 0;
            }
            if ($s['checkOut'] === $today) {
                $departures++;
                $departuresDone += $status === Previo::STATUS_CHECKED_OUT ? 1 : 0;
            }
            if ($s['checkIn'] < $today && $s['checkOut'] > $today && $status === Previo::STATUS_CHECKED_IN) {
                $ongoing++;
            }
        }

        // ── Occupancy per night: 30 back (today included) + 14 ahead ──
        $from = date('Y-m-d', strtotime('-' . (self::PAST_DAYS - 1) . ' days'));
        $next = date('Y-m-d', strtotime('+1 day'));
        $last = date('Y-m-d', strtotime('+' . self::NEXT_DAYS . ' days'));
        $days = array_map(static fn(array $d): array => [
            'date' => $d['date'], 'occupied' => $d['occupied'], 'pct' => (int) round($d['pct']), 'future' => $d['date'] > $today,
        ], $analytics->series($from, $last));
        $past = $analytics->kpis($from, $today);
        $ahead = $analytics->kpis($next, $last);
        $channels = $analytics->channels($from, $today);

        return [
            'date'       => $today,
            'computedAt' => gmdate('Y-m-d H:i:s'),
            'roster'     => ['source' => $source, 'total' => $total, 'apartments' => $roster],
            'today'      => [
                'occupied'       => count($occupied),
                'free'           => $free,
                'checkIns'       => $arrivals,
                'checkOuts'      => $departures,
                'arrivalsDone'   => $arrivalsDone,
                'departuresDone' => $departuresDone,
                'ongoing'        => $ongoing,
            ],
            'occupancy'  => [
                'days'    => $days,
                'avgPast' => (int) round($past['occupancy'] ?? 0),
                'avgNext' => (int) round($ahead['occupancy'] ?? 0),
            ],
            'channels'   => ['from' => $from, 'to' => $today, 'total' => $channels['total'], 'rows' => $channels['rows']],
        ];
    }

    /** config('reports.vat_rate'): when Previo prices include VAT, e.g. 0.11 → revenue is shown without it. */
    public static function vatRate(): float
    {
        $reports = config('reports', []);
        return is_array($reports) ? max(0.0, (float) ($reports['vat_rate'] ?? 0)) : 0.0;
    }
}
