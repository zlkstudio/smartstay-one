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
 * Parkings are dropped (they are never apartments). The cache holds guest names, so it
 * lives in storage/ (outside the docroot, denied by .htaccess, never deployed over).
 */
final class Stays
{
    public const DAYS_BACK = 90;
    public const DAYS_AHEAD = 30;
    private const TTL = 300;

    /**
     * @return list<array{id:string, apartment:string, guest:string, checkIn:string, checkInTime:string,
     *   checkOut:string, checkOutTime:string, nights:int, guests:int, platform:string}>
     */
    public static function window(bool $fresh = false): array
    {
        $today = date('Y-m-d');
        $dir = ONE_ROOT . '/storage/cache';
        $file = "$dir/stays-$today.json";

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
        ];
    }

    private static function store(string $dir, string $file, array $rows): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            error_log('[ONE] storage/cache not writable — stays not cached');
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($rows, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $file);
        }
        // Yesterday's snapshots are useless (and hold guest names): drop them.
        foreach (glob("$dir/stays-*.json") ?: [] as $old) {
            if ($old !== $file) {
                @unlink($old);
            }
        }
    }
}
