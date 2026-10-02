<?php
declare(strict_types=1);

namespace One\Integrations;

use RuntimeException;

/**
 * Nuki Web API — keypad codes, copied from reservations/api/send_nuki_code.php.
 *
 * config/nuki.php (gitignored, PROTECTED_CONFIGS):
 *   ['api_token' => '…', 'api_base' => 'https://api.nuki.io', 'smartlocks' => ['99' => '18043849972', …]]
 *
 * Kept from the legacy fixes:
 *  - PUT /smartlock/{id}/auth (per lock — proven 204 on all locks, incl. Ultra),
 *    NOT the account-level /smartlock/auth (async, unreliable).
 *  - HTTP 409 = the code already exists → success.
 */
final class Nuki
{
    public static function isConfigured(): bool
    {
        return is_file(ONE_ROOT . '/config/nuki.php');
    }

    /** Apartment → smartlock id map. Never exposes the token. @return array<string,string> */
    public static function smartlocks(): array
    {
        if (!self::isConfigured()) {
            return [];
        }
        $map = self::config()['smartlocks'] ?? [];
        $out = [];
        foreach ((array) $map as $apartment => $lockId) {
            $out[(string) $apartment] = (string) $lockId;
        }
        return $out;
    }

    public static function hasLock(string $apartment): bool
    {
        return isset(self::smartlocks()[self::cleanApartment($apartment)]);
    }

    /**
     * Pushes a 6-digit keypad code to the apartment's lock.
     * @return array{ok:bool, http:int, already:bool, lock:string}
     */
    public static function sendKeypadCode(string $apartment, string $guestName, string $code): array
    {
        $apartment = self::cleanApartment($apartment);
        $lockId = self::smartlocks()[$apartment] ?? null;
        if ($lockId === null) {
            throw new RuntimeException("Apartamentul $apartment nu are yală Nuki configurată.");
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('Cod Nuki invalid.');
        }

        $config = self::config();
        $base = rtrim((string) ($config['api_base'] ?? 'https://api.nuki.io'), '/');
        $payload = json_encode([
            'name' => $guestName !== '' ? mb_substr($guestName, 0, 32) : 'Guest ' . $apartment,
            'type' => 13, // keypad code
            'code' => (int) $code,
        ]);

        $ch = curl_init("$base/smartlock/$lockId/auth");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Cache-Control: no-cache',
                'Authorization: Bearer ' . $config['api_token'],
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $ok = $error === '' && in_array($status, [200, 201, 204, 409], true);
        self::log(sprintf(
            'apt=%s lock=%s http=%d %s',
            $apartment,
            $lockId,
            $status,
            $ok ? 'OK' : ('FAIL ' . ($error ?: trim(strip_tags(substr((string) $response, 0, 200)))))
        ));

        if ($error !== '') {
            throw new RuntimeException('Nuki nu răspunde. Încearcă din nou.');
        }
        return ['ok' => $ok, 'http' => $status, 'already' => $status === 409, 'lock' => $lockId];
    }

    /** "Ap. 295", " 295 " → "295" (defensive, as in the legacy endpoint). */
    public static function cleanApartment(string $raw): string
    {
        return preg_match('/\d+/', $raw, $m) ? $m[0] : trim($raw);
    }

    private static function config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }
        $data = require ONE_ROOT . '/config/nuki.php';
        if (!is_array($data) || empty($data['api_token'])) {
            throw new RuntimeException('config/nuki.php invalid (lipsește api_token).');
        }
        return $config = $data;
    }

    private static function log(string $line): void
    {
        @file_put_contents(
            ONE_ROOT . '/storage/logs/nuki.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
