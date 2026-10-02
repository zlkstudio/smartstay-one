<?php
declare(strict_types=1);

namespace One\Reservations;

use DateTimeImmutable;
use One\Integrations\Nuki;
use One\Integrations\NukiCode;
use One\Integrations\Previo;
use One\Properties;
use SimpleXMLElement;

/**
 * Reservation lists for Rezervări, ported from reservations/get_reservations.php,
 * get_tomorrow_reservations.php, get_recent_reservations.php and
 * api/get_whatsapp_reservations.php. Normalisation happens here (it used to live
 * in state.js), so the browser receives clean, flat rows.
 */
final class ReservationFeed
{
    /** Previo / Booking.com system lines removed from the housekeeping note. */
    private const NOTE_NOISE = [
        'Systém', 'Partener Booking.com', 'Total', 'Comision', 'Commission', 'El l-a creat',
        'Comentariu Special', 'Payment description', 'payment_on_', 'Cometariu', 'Plată', 'RON',
        'Virtual credit card', 'Needs to be charged', 'Payout type', 'You can charge',
        'requestssmoking', 'preference Non-Smoking',
    ];

    /**
     * Check-ins on a day, parkings attached to their apartment (never shown alone).
     * @return list<array<string,mixed>>
     */
    public static function checkins(string $date): array
    {
        $apartments = [];
        $parkings = [];
        foreach (Previo::forDay($date, 'check-in') as $r) {
            $row = self::normalize($r);
            if ($row['_parking'] !== null) {
                $parkings[] = $row;
            } else {
                $apartments[] = $row;
            }
        }

        // PASS 2 — parking → apartment: phone (last 9 digits) + check-in date, then normalised name + date.
        foreach ($parkings as $parking) {
            $index = self::findOwner($apartments, $parking, '_phoneKey') ?? self::findOwner($apartments, $parking, '_nameKey');
            if ($index === null) {
                error_log(sprintf(
                    '[ONE] Orphan parking dropped: resId=%s label=%s name=%s date=%s',
                    $parking['id'],
                    $parking['_parking'],
                    $parking['name'],
                    $parking['checkInDate']
                ));
                continue;
            }
            $apartments[$index]['parkingSpot'] = $apartments[$index]['parkingSpot'] === null
                ? $parking['_parking']
                : $apartments[$index]['parkingSpot'] . ', ' . $parking['_parking'];
        }

        usort($apartments, static fn(array $a, array $b): int =>
            [$a['checkInTime'], (int) $a['apartment']] <=> [$b['checkInTime'], (int) $b['apartment']]);

        return array_map([self::class, 'publicRow'], $apartments);
    }

    /** Check-ins of the last 4 days (Generator link). Newest first, parkings removed. */
    public static function recent(): array
    {
        $from = date('Y-m-d', strtotime('-4 days'));
        $rows = [];
        foreach (Previo::search("$from 00:00:00", date('Y-m-d') . ' 23:59:00', 'check-in') as $r) {
            $row = self::normalize($r);
            if ($row['_parking'] === null) {
                $rows[] = self::publicRow($row);
            }
        }
        usort($rows, static fn(array $a, array $b): int =>
            ($b['checkInDate'] . $b['checkInTime']) <=> ($a['checkInDate'] . $a['checkInTime']));
        return $rows;
    }

    /** Find one reservation among today's and tomorrow's check-ins (server-side source for Nuki). */
    public static function findUpcoming(string $reservationId): ?array
    {
        foreach ([date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))] as $day) {
            foreach (self::checkins($day) as $row) {
                if ($row['id'] === $reservationId) {
                    return $row;
                }
            }
        }
        return null;
    }

    /**
     * Check-outs for the WhatsApp outreach page: today, 7 and 14 days ago.
     * @return array{windows:array<string,list<array>>, dates:array<string,string>, errors:array<string,string>}
     */
    public static function whatsappWindows(): array
    {
        $dates = [
            'w1' => date('Y-m-d'),
            'w2' => date('Y-m-d', strtotime('-7 days')),
            'w3' => date('Y-m-d', strtotime('-14 days')),
        ];
        $windows = [];
        $errors = [];
        foreach ($dates as $key => $date) {
            try {
                $rows = [];
                foreach (Previo::forDay($date, 'check-out') as $r) {
                    if (Properties::isParking(Previo::apartment($r))) {
                        continue;
                    }
                    $guest = Previo::guests($r)[0] ?? null;
                    $phone = Previo::contactPhone($r);
                    if ($guest && $guest['phone'] !== '') {
                        $phone = $guest['phone'];
                    }
                    $name = $guest ? trim($guest['firstName'] . ' ' . $guest['lastName']) : '';
                    $rows[] = [
                        'id'        => (string) $r->resId,
                        'name'      => $name !== '' ? $name : (Previo::contactName($r) ?: 'Oaspete'),
                        'apartment' => Previo::apartment($r),
                        'phone'     => $phone,
                        'waPhone'   => $phone !== '' ? Properties::whatsappPhone($phone) : '',
                        'isRo'      => Properties::isRomanianPhone($phone),
                        'checkOut'  => substr((string) $r->term->to, 0, 10),
                        'platform'  => Previo::platform($r),
                    ];
                }
                usort($rows, static fn(array $a, array $b): int => strnatcmp($a['apartment'], $b['apartment']));
                $windows[$key] = $rows;
            } catch (\Throwable $e) {
                $windows[$key] = [];
                $errors[$key] = $e->getMessage();
            }
        }
        return ['windows' => $windows, 'dates' => $dates, 'errors' => $errors];
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function normalize(SimpleXMLElement $r): array
    {
        $apartment = Previo::apartment($r);
        $phone = Previo::contactPhone($r);
        $name = Previo::contactName($r);
        $from = (string) $r->term->from;
        $to = (string) $r->term->to;
        $hasNuki = Nuki::hasLock($apartment);

        return [
            'id'            => (string) $r->resId,
            'name'          => $name !== '' ? $name : 'Oaspete',
            'phone'         => $phone,
            'waPhone'       => $phone !== '' ? Properties::whatsappPhone($phone) : '',
            'isRo'          => Properties::isRomanianPhone($phone),
            'email'         => (string) $r->contactPerson->email,
            'apartment'     => $apartment,
            'checkInDate'   => substr($from, 0, 10),
            'checkInTime'   => self::time($from, '15:00'),
            'checkOutDate'  => substr($to, 0, 10),
            'checkOutTime'  => self::time($to, '11:00'),
            'checkInLabel'  => self::dayLabel($from),
            'checkOutLabel' => self::dayLabel($to),
            'guestCount'    => count(Previo::guests($r)),
            'parkingSpot'   => null,
            'hasNuki'       => $hasNuki,
            'nukiCode'      => NukiCode::fromPhone($phone), // also the legacy-link door code
            'note'          => self::cleanNote(isset($r->note) ? (string) $r->note : ''),
            '_parking'      => Properties::parkingLabel($apartment),
            '_phoneKey'     => substr(preg_replace('/\D+/', '', $phone) ?? '', -9),
            '_nameKey'      => self::nameKey($name),
        ];
    }

    private static function publicRow(array $row): array
    {
        unset($row['_parking'], $row['_phoneKey'], $row['_nameKey']);
        $row['guestLink'] = self::guestLink($row['id'], $row['isRo'] ? 'ro' : 'en');
        return $row;
    }

    public static function guestLink(string $reservationId, string $lang): string
    {
        return rtrim((string) config('guest_app_url', 'https://smartstay.ro/guest-app'), '/')
            . '/?' . http_build_query(['reservationId' => $reservationId, 'lang' => $lang]);
    }

    private static function findOwner(array $apartments, array $parking, string $key): ?int
    {
        if ($parking[$key] === '') {
            return null;
        }
        foreach ($apartments as $i => $apartment) {
            if ($apartment[$key] === $parking[$key] && $apartment['checkInDate'] === $parking['checkInDate']) {
                return $i;
            }
        }
        return null;
    }

    private static function time(string $value, string $fallback): string
    {
        $ts = strtotime($value);
        return $ts !== false && strlen($value) > 10 ? date('H:i', $ts) : $fallback;
    }

    private static function dayLabel(string $value): string
    {
        $ts = strtotime(substr($value, 0, 10));
        if ($ts === false) {
            return $value;
        }
        $days = ['Dum', 'Lun', 'Mar', 'Mie', 'Joi', 'Vin', 'Sâm'];
        $months = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'noi', 'dec'];
        $d = new DateTimeImmutable('@' . $ts);
        $d = $d->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $days[(int) $d->format('w')] . ', ' . (int) $d->format('j') . ' ' . $months[(int) $d->format('n') - 1];
    }

    private static function nameKey(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = strtr($n, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'à' => 'a', 'è' => 'e',
            'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
            'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ]);
        return trim(preg_replace('/\s+/', ' ', $n) ?? '');
    }

    private static function cleanNote(string $note): string
    {
        $note = str_replace(['Úklid - ', 'Recepce - '], '', $note);
        $keep = [];
        foreach (explode("\n", $note) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            foreach (self::NOTE_NOISE as $word) {
                if (stripos($line, $word) !== false) {
                    continue 2;
                }
            }
            $keep[] = $line;
        }
        return implode("\n", $keep);
    }
}
