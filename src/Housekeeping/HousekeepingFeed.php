<?php
declare(strict_types=1);

namespace One\Housekeeping;

use DateTimeImmutable;
use DateTimeZone;
use One\Integrations\Previo;
use One\Properties;

/** Previo reads for Housekeeping — ported from get_checkouts.php and get_active_guests.php. */
final class HousekeepingFeed
{
    /** Today's check-outs, parkings removed. @return list<array{reservationId:string,apartment:string,guest:string,checkOutTime:string}> */
    public static function checkouts(string $date): array
    {
        $rows = [];
        foreach (Previo::forDay($date, 'check-out') as $r) {
            $apartment = Previo::apartment($r);
            if ($apartment === '' || Properties::isParking($apartment)) {
                continue;
            }
            $to = (string) $r->term->to;
            $rows[] = [
                'reservationId' => (string) $r->resId,
                'apartment'     => $apartment,
                'guest'         => Previo::contactName($r) ?: 'Fără nume',
                'checkOutTime'  => strlen($to) > 10 ? date('H:i', (int) strtotime($to)) : '11:00',
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strnatcmp($a['apartment'], $b['apartment']));
        return $rows;
    }

    /**
     * Guests in-house right now (checked in, not yet checked out) — for intermediate cleanings.
     * Previo cannot filter "currently staying": pull 90 days of check-ins, filter by overlap.
     * @return list<array{reservationId:string,apartment:string,guest:string,checkIn:string,checkOut:string}>
     */
    public static function inHouse(): array
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $now = new DateTimeImmutable('now', $tz);
        $from = $now->modify('-90 days')->format('Y-m-d') . ' 00:00:00';
        $to = $now->format('Y-m-d') . ' 23:59:00';

        $active = [];
        foreach (Previo::search($from, $to, 'check-in') as $r) {
            $apartment = Previo::apartment($r);
            if ($apartment === '' || Properties::isParking($apartment)) {
                continue;
            }
            $in = (string) $r->term->from;
            $out = (string) $r->term->to;
            if ($in === '' || $out === '') {
                continue;
            }
            $checkIn = new DateTimeImmutable($in, $tz);
            $checkOut = new DateTimeImmutable($out, $tz);
            if ($checkIn <= $now && $checkOut > $now) {
                $active[] = [
                    'reservationId' => (string) $r->resId,
                    'apartment'     => $apartment,
                    'guest'         => Previo::contactName($r) ?: 'Fără nume',
                    'checkIn'       => $checkIn->format('Y-m-d'),
                    'checkOut'      => $checkOut->format('Y-m-d'),
                ];
            }
        }

        // One card per apartment (earliest check-in wins), sorted by apartment number.
        usort($active, static fn(array $a, array $b): int => strcmp($a['checkIn'], $b['checkIn']));
        $seen = [];
        $out = [];
        foreach ($active as $row) {
            if (!isset($seen[$row['apartment']])) {
                $seen[$row['apartment']] = true;
                $out[] = $row;
            }
        }
        usort($out, static fn(array $a, array $b): int => strnatcmp($a['apartment'], $b['apartment']));
        return $out;
    }
}
