<?php
declare(strict_types=1);

namespace One\Reports;

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
    public const KEY = 'operations';
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
        ['source' => $source, 'apartments' => $roster] = Stays::roster($stays);
        $inRoster = array_flip($roster);
        $total = count($roster);

        // ── Today ──
        $occupied = array_values(array_filter(Stays::occupiedOn($stays, $today), static fn(string $a): bool => isset($inRoster[$a])));
        $free = array_values(array_diff($roster, $occupied));
        natsort($occupied);
        $checkIns = $checkOuts = 0;
        foreach ($stays as $s) {
            $checkIns += $s['checkIn'] === $today ? 1 : 0;
            $checkOuts += $s['checkOut'] === $today ? 1 : 0;
        }

        // ── Occupancy per night ──
        $days = [];
        $pastSum = $nextSum = 0;
        for ($i = -(self::PAST_DAYS - 1); $i <= self::NEXT_DAYS; $i++) {
            $date = date('Y-m-d', strtotime(($i >= 0 ? '+' : '') . $i . ' days'));
            $count = count(array_filter(Stays::occupiedOn($stays, $date), static fn(string $a): bool => isset($inRoster[$a])));
            $pct = $total ? (int) round($count * 100 / $total) : 0;
            $days[] = ['date' => $date, 'occupied' => $count, 'pct' => $pct, 'future' => $i > 0];
            if ($i > 0) {
                $nextSum += $pct;
            } else {
                $pastSum += $pct;
            }
        }

        // ── Channels: check-ins in the last 30 days ──
        $from = date('Y-m-d', strtotime('-' . (self::PAST_DAYS - 1) . ' days'));
        $channels = array_fill_keys(array_keys(self::CHANNELS), ['reservations' => 0, 'nights' => 0]);
        $reservations = 0;
        foreach ($stays as $s) {
            if ($s['checkIn'] < $from || $s['checkIn'] > $today) {
                continue;
            }
            $key = isset($channels[$s['platform']]) ? $s['platform'] : 'google';
            $channels[$key]['reservations']++;
            $channels[$key]['nights'] += $s['nights'];
            $reservations++;
        }

        return [
            'date'       => $today,
            'computedAt' => gmdate('Y-m-d H:i:s'),
            'roster'     => ['source' => $source, 'total' => $total],
            'today'      => [
                'occupied'  => count($occupied),
                'free'      => $free,
                'checkIns'  => $checkIns,
                'checkOuts' => $checkOuts,
            ],
            'occupancy'  => [
                'days'    => $days,
                'avgPast' => (int) round($pastSum / self::PAST_DAYS),
                'avgNext' => (int) round($nextSum / self::NEXT_DAYS),
            ],
            'channels'   => ['from' => $from, 'to' => $today, 'total' => $reservations, 'rows' => $channels],
        ];
    }
}
