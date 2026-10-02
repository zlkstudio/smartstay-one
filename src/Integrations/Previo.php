<?php
declare(strict_types=1);

namespace One\Integrations;

use RuntimeException;
use SimpleXMLElement;

/**
 * Previo XML API — searchReservations, copied from the legacy apps
 * (reservations/get_*.php, housekeeping/get_checkouts.php).
 *
 * Credentials: config/previo.php (gitignored, PROTECTED_CONFIGS). Source of truth:
 * ~/smartstay.ro/guest-app/config/previo.php — copy it, never symlink.
 *
 * Quirks kept from the legacy code:
 *  - searchReservations cannot filter by resId → search a window, filter locally.
 *  - Apartment number = <object><name>.
 *  - contactPerson may be empty for OTA bookings → fall back to the first <guest>.
 *  - Redirects need CURLOPT_FOLLOWLOCATION + CURLOPT_POSTREDIR.
 */
final class Previo
{
    private const ENDPOINT = 'https://api.previo.app/x1/hotel/searchReservations';

    /**
     * @param 'check-in'|'check-out' $termType
     * @return list<SimpleXMLElement> <reservation> nodes
     */
    public static function search(string $from, string $to, string $termType = 'check-in'): array
    {
        $config = self::config();
        $xml = '<?xml version="1.0"?>'
            . '<request>'
            . '<login>' . self::x($config['username']) . '</login>'
            . '<password>' . self::x($config['password']) . '</password>'
            . '<hotId>' . self::x((string) $config['hotel_id']) . '</hotId>'
            . '<term>'
            . '<from>' . self::x($from) . '</from>'
            . '<to>' . self::x($to) . '</to>'
            . '<termType>' . self::x($termType) . '</termType>'
            . '</term>'
            . '</request>';

        $ch = curl_init((string) ($config['endpoint'] ?? self::ENDPOINT));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/xml'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => 7,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            error_log("[ONE] Previo cURL: $error");
            throw new RuntimeException('Previo nu răspunde. Încearcă din nou.');
        }
        if ($status !== 200) {
            // Never log the request body: it carries the password.
            error_log("[ONE] Previo HTTP $status: " . substr((string) $response, 0, 300));
            throw new RuntimeException("Previo a răspuns cu eroare ($status).");
        }

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string((string) $response);
        libxml_use_internal_errors($previous);
        if ($parsed === false) {
            throw new RuntimeException('Răspuns Previo invalid.');
        }

        $out = [];
        foreach ($parsed->reservation as $reservation) {
            $out[] = $reservation;
        }
        return $out;
    }

    /** Check-ins (or check-outs) on one calendar day. @return list<SimpleXMLElement> */
    public static function forDay(string $date, string $termType = 'check-in'): array
    {
        return self::search("$date 00:00:00", "$date 23:59:00", $termType);
    }

    // ── Field helpers shared by every feed ────────────────────────────────

    /** @return list<array{firstName:string,lastName:string,email:string,phone:string,countryCode:string}> */
    public static function guests(SimpleXMLElement $r): array
    {
        $guests = [];
        foreach ($r->guest ?? [] as $g) {
            $guests[] = [
                'firstName'   => (string) $g->firstName,
                'lastName'    => (string) $g->lastName,
                'email'       => (string) $g->email,
                'phone'       => str_replace(' ', '', (string) $g->phone),
                'countryCode' => (string) $g->countryCode,
            ];
        }
        return $guests;
    }

    public static function apartment(SimpleXMLElement $r): string
    {
        return trim((string) $r->object->name);
    }

    /** contactPerson name, else first guest (OTA bookings). */
    public static function contactName(SimpleXMLElement $r): string
    {
        $name = trim((string) $r->contactPerson->name);
        if ($name === '') {
            $g = self::guests($r)[0] ?? null;
            $name = $g ? trim($g['firstName'] . ' ' . $g['lastName']) : '';
        }
        return $name;
    }

    /** contactPerson phone, else first guest with a phone. */
    public static function contactPhone(SimpleXMLElement $r): string
    {
        $phone = str_replace(' ', '', (string) $r->contactPerson->phone);
        if ($phone === '') {
            foreach (self::guests($r) as $g) {
                if ($g['phone'] !== '') {
                    return $g['phone'];
                }
            }
        }
        return $phone;
    }

    /**
     * Booking channel: booking_com | airbnb | expedia | travelminit | google (direct / unknown).
     * Previo has no single channel field on this account, so every likely field + the notes are scanned.
     */
    public static function platform(SimpleXMLElement $r): string
    {
        $fields = [];
        foreach (['source', 'channel', 'partner', 'partnerName', 'agentName', 'agency', 'tourOperator', 'channelManager'] as $f) {
            if (isset($r->$f)) {
                $fields[] = (string) $r->$f;
                if (isset($r->$f->name)) {
                    $fields[] = (string) $r->$f->name;
                }
            }
        }
        foreach (['note', 'gNote', 'internalNote', 'systemNote'] as $f) {
            if (isset($r->$f)) {
                $fields[] = (string) $r->$f;
            }
        }
        $haystack = mb_strtolower(implode(' ', $fields));
        return match (true) {
            str_contains($haystack, 'airbnb')                                 => 'airbnb',
            str_contains($haystack, 'expedia')                                => 'expedia',
            str_contains($haystack, 'szallas'), str_contains($haystack, 'travelminit') => 'travelminit',
            str_contains($haystack, 'booking.com'), str_contains($haystack, 'booking com'),
            str_contains($haystack, 'partener booking')                       => 'booking_com',
            default                                                           => 'google',
        };
    }

    /**
     * status/statusId on this account (checked 02.10.2026 on 517 reservations):
     * 1 = option (has optionExpiration, not confirmed) · 2 = confirmed · 3 = checked in · 9 = checked out.
     * Cancelled reservations are not returned by searchReservations at all.
     */
    public const STATUS_OPTION = '1';

    public const STATUS_CHECKED_IN = '3';
    public const STATUS_CHECKED_OUT = '9';

    /**
     * Option (statusId 1). On this account an option is a real booking paid cash at check-out,
     * so it counts everywhere (occupancy, channels, revenue) — the flag is informational only.
     */
    public static function isOption(SimpleXMLElement $r): bool
    {
        return (string) $r->status->statusId === self::STATUS_OPTION;
    }

    public static function statusId(SimpleXMLElement $r): string
    {
        return trim((string) $r->status->statusId);
    }

    /** Reservation price (whole stay, RON, as Previo sends it). 0 when missing. */
    public static function price(SimpleXMLElement $r): float
    {
        $raw = str_replace([' ', ','], ['', '.'], trim((string) $r->price));
        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /** Creation date (Y-m-d) when Previo sends one under any of the usual names, else ''. */
    public static function createdAt(SimpleXMLElement $r): string
    {
        foreach (['created', 'createdAt', 'dateCreated', 'creationDate', 'creationTime', 'insertDate', 'dateInsert', 'bookingDate', 'reservationDate'] as $f) {
            $v = trim((string) ($r->$f ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                return substr($v, 0, 10);
            }
        }
        return '';
    }

    public static function isConfigured(): bool
    {
        return is_file(ONE_ROOT . '/config/previo.php');
    }

    /** @return array{username:string,password:string,hotel_id:string} */
    private static function config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }
        $path = ONE_ROOT . '/config/previo.php';
        if (!is_file($path)) {
            throw new RuntimeException('Lipsește config/previo.php (copiază-l din guest-app/config/previo.php).');
        }
        $data = require $path;
        if (!is_array($data) || empty($data['username']) || empty($data['password'])) {
            throw new RuntimeException('config/previo.php incomplet (username, password, hotel_id).');
        }
        $data['hotel_id'] = (string) ($data['hotel_id'] ?? $data['hotelId'] ?? '783733');
        return $config = $data;
    }

    private static function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
