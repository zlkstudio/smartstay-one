<?php
declare(strict_types=1);

namespace One;

use DateTimeImmutable;
use One\Integrations\Previo;
use SimpleXMLElement;

/**
 * Apartment stays from Previo, normalised and cached in storage/cache for 5 minutes.
 *
 * One window — check-ins from 90 days ago to 30 days ahead — serves Inventar, the Home
 * indicators and Rapoarte, so Previo is called at most once per 5 minutes for all of them.
 * Parkings are dropped. Options (statusId 1) are KEPT with option=true: Previo counts them as
 * occupied (Overview / Dashboard), and the guest may already be in the apartment. The cache holds guest names, so it
 * lives in storage/ (outside the docroot, denied by .htaccess, never deployed over).
 */
final class Stays
{
    public const DAYS_BACK = 90;
    public const DAYS_AHEAD = 30;
    private const TTL = 300;

    /**
     * @return list<array{id:string, apartment:string, guest:string, checkIn:string, checkInTime:string,
     *   checkOut:string, checkOutTime:string, nights:int, guests:int, platform:string, option:bool,
     *   status:string, price:float, created:string, countries:list<string>}>
     */
    public static function window(bool $fresh = false): array
    {
        $today = date('Y-m-d');
        $dir = ONE_ROOT . '/storage/cache';
        $file = "$dir/stays-v3-$today.json"; // v3: options + status, price, created, countries

        if (!$fresh && is_file($file) && time() - (int) filemtime($file) < self::TTL) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $from = date('Y-m-d', strtotime('-' . self::DAYS_BACK . ' days'));
        $to = date('Y-m-d', strtotime('+' . self::DAYS_AHEAD . ' days'));
        $rows = [];
        foreach (Previo::search("$from 00:00:00", "$to 23:59:00", 'check-in') as $r) {
            $row = self::normalize($r);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        self::store($dir, $file, $rows);
        return $rows;
    }

    /**
     * Every stay touching calendar year $year: check-ins from 60 days before 1 January to 31 December.
     * Fetched from Previo in quarterly chunks (one big request times out), cached in
     * storage/cache/history-{year}.json — 1 hour for the current year, 24 hours for past years.
     * @return list<array> same shape as window()
     * @throws \RuntimeException when Previo is unreachable and nothing is cached
     */
    public static function year(int $year, bool $fresh = false): array
    {
        $dir = ONE_ROOT . '/storage/cache';
        $file = "$dir/history-v1-$year.json";
        $ttl = $year >= (int) date('Y') ? 3600 : 86400;
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!$fresh && is_array($cached) && time() - (int) filemtime($file) < $ttl) {
            return $cached;
        }

        $rows = [];
        try {
            $start = new DateTimeImmutable(($year - 1) . '-11-02');
            $end = new DateTimeImmutable("$year-12-31");
            while ($start <= $end) {
                $chunkEnd = min($start->modify('+3 months -1 day'), $end);
                foreach (Previo::search($start->format('Y-m-d') . ' 00:00:00', $chunkEnd->format('Y-m-d') . ' 23:59:00', 'check-in') as $r) {
                    $row = self::normalize($r);
                    if ($row !== null) {
                        $rows[$row['id'] . '|' . $row['apartment']] = $row;
                    }
                }
                $start = $chunkEnd->modify('+1 day');
            }
        } catch (\RuntimeException $e) {
            if (is_array($cached)) {
                error_log("[ONE] history $year: Previo failed, serving stale cache — " . $e->getMessage());
                return $cached;
            }
            throw $e;
        }

        $rows = array_values($rows);
        self::store($dir, $file, $rows, false);
        return $rows;
    }

    /**
     * Stays for every year touched by [$from, $to], deduplicated.
     * @return list<array>
     */
    public static function between(string $from, string $to, bool $fresh = false): array
    {
        $out = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            foreach (self::year($y, $fresh) as $s) {
                $out[$s['id'] . '|' . $s['apartment']] = $s;
            }
        }
        return array_values($out);
    }

    /**
     * What happens in each apartment on one day.
     * @param list<array> $stays
     * @return array<string, array{checkIn:?array, checkOut:?array, staying:?array}>
     */
    public static function dayStatus(array $stays, string $date): array
    {
        $out = [];
        foreach ($stays as $s) {
            $apt = $s['apartment'];
            $out[$apt] ??= ['checkIn' => null, 'checkOut' => null, 'staying' => null];
            if ($s['checkIn'] === $date) {
                $out[$apt]['checkIn'] = $s;
            }
            if ($s['checkOut'] === $date) {
                $out[$apt]['checkOut'] = $s;
            }
            if ($s['checkIn'] < $date && $s['checkOut'] > $date) {
                $out[$apt]['staying'] = $s;
            }
        }
        return array_filter($out, static fn(array $v): bool => $v['checkIn'] || $v['checkOut'] || $v['staying']);
    }

    /** Apartments with a guest sleeping there the night of $date. @return list<string> */
    public static function occupiedOn(array $stays, string $date): array
    {
        $apts = [];
        foreach ($stays as $s) {
            if ($s['checkIn'] <= $date && $s['checkOut'] > $date) {
                $apts[$s['apartment']] = true;
            }
        }
        return array_keys($apts);
    }

    /**
     * The apartments that count for occupancy. config('apartments') when set (exact list),
     * otherwise every apartment with at least one stay in the Previo window.
     * @return array{source:'config'|'previo', apartments:list<string>}
     */
    public static function roster(array $stays): array
    {
        $configured = config('apartments');
        if (is_array($configured) && $configured) {
            $list = array_values(array_unique(array_map(static fn($a): string => trim((string) $a), $configured)));
            $source = 'config';
        } else {
            $list = array_values(array_unique(array_column($stays, 'apartment')));
            $source = 'previo';
        }
        natsort($list);
        return ['source' => $source, 'apartments' => array_values($list)];
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function normalize(SimpleXMLElement $r): ?array
    {
        $apartment = Previo::apartment($r);
        if ($apartment === '' || Properties::isParking($apartment)) {
            return null;
        }
        $from = (string) $r->term->from;
        $to = (string) $r->term->to;
        if (strlen($from) < 10 || strlen($to) < 10) {
            return null;
        }
        $checkIn = substr($from, 0, 10);
        $checkOut = substr($to, 0, 10);
        $nights = (new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days;

        return [
            'id'           => (string) $r->resId,
            'apartment'    => $apartment,
            'guest'        => Previo::contactName($r) ?: 'Oaspete',
            'checkIn'      => $checkIn,
            'checkInTime'  => strlen($from) > 10 ? substr($from, 11, 5) : '15:00',
            'checkOut'     => $checkOut,
            'checkOutTime' => strlen($to) > 10 ? substr($to, 11, 5) : '11:00',
            'nights'       => max(0, (int) $nights),
            'guests'       => count(Previo::guests($r)),
            'platform'     => Previo::platform($r),
            'option'       => Previo::isOption($r),
            'status'       => Previo::statusId($r),
            'price'        => Previo::price($r),
            'created'      => Previo::createdAt($r),
            'countries'    => array_map(static fn(array $g): string => strtoupper(trim($g['countryCode'])), Previo::guests($r)),
        ];
    }

    private static function store(string $dir, string $file, array $rows, bool $pruneDaily = true): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            error_log('[ONE] storage/cache not writable — stays not cached');
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($rows, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $file);
        }
        if (!$pruneDaily) {
            return;
        }
        // Yesterday's snapshots are useless (and hold guest names): drop them.
        foreach (glob("$dir/stays-*.json") ?: [] as $old) {
            if ($old !== $file) {
                @unlink($old);
            }
        }
    }
}
