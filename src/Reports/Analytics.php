<?php
declare(strict_types=1);

namespace One\Reports;

use DateTimeImmutable;
use One\Integrations\Previo;

/**
 * THE aggregation module for Rapoarte · Prezentare. Every number on the page (KPI cards,
 * chart tooltips, channels, per-apartment table, monthly trend) comes from here, so two
 * sections can never compute the same metric differently.
 *
 * Definitions (Previo Overview / Hotelgroup overview):
 *  - room nights total  = apartments in the roster × nights in the period (no closed rooms in ONE)
 *  - occupied           = nights with a stay; options count (here they are cash-at-checkout bookings);
 *                         cancelled / no-show / waiting list never reach us (searchReservations omits them)
 *  - occupancy %        = occupied / total
 *  - revenue            = reservation price spread evenly over its nights (a day-use stay with 0 nights
 *                         books its price on the check-in day, without a room night); VAT removed when
 *                         config('reports.vat_rate') is set
 *  - ADR = revenue / occupied · RevPAR = revenue / total (≤ ADR, equal at 100%)
 *  - TRevPAR / TRevPP need the room accounts (extras, city tax…) — searchReservations does not send them.
 */
final class Analytics
{
    /** @var array<string, array<string,true>> date → apartments occupied that night */
    private array $occ = [];
    /** @var array<string, float> date → revenue */
    private array $rev = [];
    /** @var array<string, array<string, float>> apartment → date → revenue */
    private array $revApt = [];
    /** @var array<string, int> date → guests sleeping there */
    private array $guestNights = [];
    /** @var list<array> stays of roster apartments */
    private array $stays = [];
    /** @var array<string,true> */
    private array $inRoster;
    private int $unpriced = 0;
    private bool $hasCreated = false;

    /**
     * @param list<array>  $stays    Stays::between() rows
     * @param list<string> $roster   apartments that count
     * @param string       $dataFrom first night the loaded stays fully cover (earlier = no baseline)
     */
    public function __construct(array $stays, private readonly array $roster, private readonly string $dataFrom, private readonly float $vatRate = 0.0)
    {
        $this->inRoster = array_fill_keys($roster, true);
        foreach ($stays as $s) {
            if (!isset($this->inRoster[$s['apartment']])) {
                continue;
            }
            $this->stays[] = $s;
            $net = $this->net((float) ($s['price'] ?? 0));
            $this->unpriced += $net <= 0 ? 1 : 0;
            $this->hasCreated = $this->hasCreated || ($s['created'] ?? '') !== '';

            $nights = (int) $s['nights'];
            if ($nights === 0) {
                $this->addRevenue($s['apartment'], $s['checkIn'], $net);
                continue;
            }
            $perNight = $net / $nights;
            $d = new DateTimeImmutable($s['checkIn']);
            for ($i = 0; $i < $nights; $i++, $d = $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');
                $this->occ[$date][$s['apartment']] = true;
                $this->guestNights[$date] = ($this->guestNights[$date] ?? 0) + max(1, (int) $s['guests']);
                $this->addRevenue($s['apartment'], $date, $perNight);
            }
        }
    }

    public function rosterSize(): int
    {
        return count($this->roster);
    }

    public function unpricedCount(): int
    {
        return $this->unpriced;
    }

    public function vatRemoved(): bool
    {
        return $this->vatRate > 0;
    }

    /**
     * KPIs for the nights [from, to], optionally for one apartment.
     * @return array{nights:int, total:int, occupied:int, available:int, occupancy:?float, revenue:float,
     *   adr:?float, revpar:?float, guestNights:int, covered:bool}
     */
    public function kpis(string $from, string $to, ?string $apartment = null): array
    {
        $nights = $occupied = $guestNights = 0;
        $revenue = 0.0;
        foreach (self::dates($from, $to) as $date) {
            $nights++;
            if ($apartment === null) {
                $occupied += count($this->occ[$date] ?? []);
                $revenue += $this->rev[$date] ?? 0.0;
                $guestNights += $this->guestNights[$date] ?? 0;
            } else {
                $occupied += isset($this->occ[$date][$apartment]) ? 1 : 0;
                $revenue += $this->revApt[$apartment][$date] ?? 0.0;
            }
        }
        $total = $nights * ($apartment === null ? count($this->roster) : 1);
        return [
            'nights'      => $nights,
            'total'       => $total,
            'occupied'    => $occupied,
            'available'   => $total - $occupied,
            'occupancy'   => $total ? $occupied * 100 / $total : null,
            'revenue'     => $revenue,
            'adr'         => $occupied ? $revenue / $occupied : null,
            'revpar'      => $total ? $revenue / $total : null,
            'guestNights' => $guestNights,
            'covered'     => $from >= $this->dataFrom,
        ];
    }

    /**
     * Per-night series for the occupancy chart.
     * @return list<array{date:string, occupied:int, total:int, pct:float, revenue:float, adr:?float, revpar:?float}>
     */
    public function series(string $from, string $to): array
    {
        $total = count($this->roster);
        $out = [];
        foreach (self::dates($from, $to) as $date) {
            $occ = count($this->occ[$date] ?? []);
            $rev = $this->rev[$date] ?? 0.0;
            $out[] = [
                'date'     => $date,
                'occupied' => $occ,
                'total'    => $total,
                'pct'      => $total ? $occ * 100 / $total : 0.0,
                'revenue'  => $rev,
                'adr'      => $occ ? $rev / $occ : null,
                'revpar'   => $total ? $rev / $total : null,
            ];
        }
        return $out;
    }

    /**
     * Channels for reservations with check-in in [from, to].
     * @return array{total:int, rows: array<string, array{reservations:int, nights:int, revenue:float, adr:?float}>}
     */
    public function channels(string $from, string $to): array
    {
        $rows = array_fill_keys(array_keys(OperationsReport::CHANNELS), ['reservations' => 0, 'nights' => 0, 'revenue' => 0.0, 'adr' => null]);
        $total = 0;
        foreach ($this->stays as $s) {
            if ($s['checkIn'] < $from || $s['checkIn'] > $to) {
                continue;
            }
            $key = isset($rows[$s['platform']]) ? $s['platform'] : 'google';
            $rows[$key]['reservations']++;
            $rows[$key]['nights'] += (int) $s['nights'];
            $rows[$key]['revenue'] += $this->net((float) $s['price']);
            $total++;
        }
        foreach ($rows as &$r) {
            $r['adr'] = $r['nights'] ? $r['revenue'] / $r['nights'] : null;
        }
        unset($r);
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * One row per roster apartment for [from, to].
     * @return list<array{apartment:string, kpis:array, channel:?string}>
     */
    public function apartments(string $from, string $to): array
    {
        $out = [];
        foreach ($this->roster as $apt) {
            $byChannel = [];
            foreach ($this->stays as $s) {
                if ($s['apartment'] !== $apt || $s['checkIn'] > $to || $s['checkOut'] <= $from) {
                    continue;
                }
                $byChannel[$s['platform']] = ($byChannel[$s['platform']] ?? 0) + max(1, (int) $s['nights']);
            }
            arsort($byChannel);
            $out[] = ['apartment' => $apt, 'kpis' => $this->kpis($from, $to, $apt), 'channel' => array_key_first($byChannel)];
        }
        return $out;
    }

    /**
     * Detail sheet for one apartment: KPIs on fixed windows, last 30 nights, recent reservations.
     * @return array{windows: array<string, array>, nights: list<array{date:string, occupied:bool}>, recent: list<array>}
     */
    public function apartmentDetail(string $apt, string $today): array
    {
        $t = new DateTimeImmutable($today);
        $month = $t->modify('first day of this month');
        $windows = [
            'Ultimele 30 de zile' => [$t->modify('-29 days')->format('Y-m-d'), $today],
            'Luna trecută'        => [$month->modify('-1 month')->format('Y-m-d'), $month->modify('-1 day')->format('Y-m-d')],
            'Luna asta'           => [$month->format('Y-m-d'), $month->modify('last day of this month')->format('Y-m-d')],
            'De la 1 ianuarie'    => [$t->format('Y') . '-01-01', $today],
        ];
        $nights = [];
        foreach (self::dates($t->modify('-29 days')->format('Y-m-d'), $today) as $date) {
            $nights[] = ['date' => $date, 'occupied' => isset($this->occ[$date][$apt])];
        }
        $recent = array_values(array_filter($this->stays, static fn(array $s): bool => $s['apartment'] === $apt && $s['checkIn'] <= $today));
        usort($recent, static fn(array $a, array $b): int => strcmp($b['checkIn'], $a['checkIn']));
        $recent = array_map(fn(array $s): array => [
            'checkIn'  => $s['checkIn'],
            'checkOut' => $s['checkOut'],
            'nights'   => (int) $s['nights'],
            'platform' => $s['platform'],
            'revenue'  => $this->net((float) $s['price']),
            'option'   => (string) ($s['status'] ?? '') === Previo::STATUS_OPTION,
        ], array_slice($recent, 0, 8));

        return [
            'windows' => array_map(fn(array $w): array => $this->kpis($w[0], $w[1], $apt), $windows),
            'nights'  => $nights,
            'recent'  => $recent,
        ];
    }

    /**
     * 12 months of $year, Hotelgroup-overview style.
     * @return array{months: list<array{month:int, kpis:array, partial:bool, current:bool}>, total: array}
     */
    public function monthly(int $year, string $today): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $from = $first->format('Y-m-d');
            $to = $first->modify('last day of this month')->format('Y-m-d');
            $months[] = [
                'month'   => $m,
                'kpis'    => $this->kpis($from, $to),
                'current' => $today >= $from && $today <= $to,
                'partial' => $today >= $from && $today < $to,   // nights still to come
            ];
        }
        return ['months' => $months, 'total' => $this->kpis("$year-01-01", "$year-12-31")];
    }

    /**
     * Reservation movement: arrivals per day + reservations created in the period.
     * @return array{days: list<array{date:string, arrivals:int}>, arrivals:int, created:?int}
     */
    public function movement(string $from, string $to): array
    {
        $perDay = array_fill_keys(self::dates($from, $to), 0);
        $created = 0;
        foreach ($this->stays as $s) {
            if (isset($perDay[$s['checkIn']])) {
                $perDay[$s['checkIn']]++;
            }
            $c = $s['created'] ?? '';
            $created += ($c !== '' && $c >= $from && $c <= $to) ? 1 : 0;
        }
        $days = [];
        foreach ($perDay as $date => $n) {
            $days[] = ['date' => $date, 'arrivals' => $n];
        }
        return ['days' => $days, 'arrivals' => array_sum($perDay), 'created' => $this->hasCreated ? $created : null];
    }

    /**
     * Guests of reservations staying at least one night in [from, to] (day-use included when it falls inside).
     * @return array{reservations:int, guests:int, domestic:int, foreign:int, unknown:int}
     */
    public function guests(string $from, string $to): array
    {
        $out = ['reservations' => 0, 'guests' => 0, 'domestic' => 0, 'foreign' => 0, 'unknown' => 0];
        foreach ($this->stays as $s) {
            $touches = $s['nights'] > 0
                ? ($s['checkIn'] <= $to && $s['checkOut'] > $from)
                : ($s['checkIn'] >= $from && $s['checkIn'] <= $to);
            if (!$touches) {
                continue;
            }
            $out['reservations']++;
            $countries = $s['countries'] ?? [];
            $out['guests'] += max(1, count($countries));
            if ($countries === []) {
                $out['unknown']++;
                continue;
            }
            foreach ($countries as $c) {
                $key = $c === '' ? 'unknown' : (in_array($c, ['RO', 'ROU'], true) ? 'domestic' : 'foreign');
                $out[$key]++;
            }
        }
        return $out;
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** Relative change in %, null when there is no baseline. */
    public static function delta(?float $now, ?float $before): ?float
    {
        if ($now === null || $before === null || abs($before) < 0.0001) {
            return null;
        }
        return ($now - $before) * 100 / abs($before);
    }

    /** @return list<string> Y-m-d for every day in [from, to] */
    public static function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }
        return $out;
    }

    private function net(float $price): float
    {
        return $this->vatRate > 0 ? $price / (1 + $this->vatRate) : $price;
    }

    private function addRevenue(string $apt, string $date, float $amount): void
    {
        $this->rev[$date] = ($this->rev[$date] ?? 0.0) + $amount;
        $this->revApt[$apt][$date] = ($this->revApt[$apt][$date] ?? 0.0) + $amount;
    }
}
