<?php
declare(strict_types=1);

namespace One\Integrations;

use RuntimeException;

/**
 * Nuki Web API — keypad codes, copied from reservations/api/send_nuki_code.php.
 *
 * config/nuki.php (gitignored, PROTECTED_CONFIGS):
 *   ['api_token' => '…', 'api_base' => 'https://api.nuki.io', 'smartlocks' => ['99' => '<smartlock id>', …]]
 *
 * Kept from the legacy fixes:
 *  - PUT /smartlock/{id}/auth (per lock — proven 204 on all locks, incl. Ultra),
 *    NOT the account-level /smartlock/auth (async, unreliable).
 *  - HTTP 409 = the code already exists → success.
 */
final class Nuki
{
    /** Locks added after config/nuki.php was written on the server. Apartment => smartlockId. */
    private const DEFAULT_LOCKS = [
        '400' => '18045779828',
    ];

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
        $map = (array) (self::config()['smartlocks'] ?? []);
        $out = self::DEFAULT_LOCKS; // lock ids are not secrets; config/nuki.php wins on conflicts
        foreach ((array) $map as $apartment => $lockId) {
            $out[self::cleanApartment((string) $apartment)] = trim((string) $lockId);
        }
        return $out;
    }

    public static function hasLock(string $apartment): bool
    {
        return isset(self::smartlocks()[self::cleanApartment($apartment)]);
    }

    /**
     * Pushes a 6-digit keypad code to the apartment's lock.
     * @return array{ok:bool, http:int, already:bool, lock:string, reason:string}
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

        $payload = json_encode([
            'name' => $guestName !== '' ? mb_substr($guestName, 0, 32) : 'Guest ' . $apartment,
            'type' => 13, // keypad code
            'code' => (int) $code,
        ]);
        $res = self::request('PUT', "/smartlock/$lockId/auth", $payload);
        $ok = $res['error'] === '' && in_array($res['status'], [200, 201, 204, 409], true);
        self::log(sprintf(
            'apt=%s lock=%s http=%d %s',
            $apartment,
            $lockId,
            $res['status'],
            $ok ? 'OK' : ('FAIL ' . ($res['error'] ?: trim(strip_tags(substr($res['body'], 0, 200)))))
        ));

        if ($res['error'] !== '') {
            throw new RuntimeException('Nuki nu răspunde. Încearcă din nou.');
        }
        return [
            'ok'      => $ok,
            'http'    => $res['status'],
            'already' => $res['status'] === 409,
            'lock'    => $lockId,
            'reason'  => $ok ? '' : self::reason($res['status'], $res['body'], $lockId),
        ];
    }

    /**
     * Read-only check of one lock: is it in the token's account, online, keypad paired, how many keypad codes.
     * Used by Setări → Yale Nuki to explain why an apartment does not get its code.
     * @return array{apartment:string, lock:string, ok:bool, name:?string, online:?bool, keypad:?bool, codes:?int, problem:string}
     */
    public static function inspect(string $apartment): array
    {
        $apartment = self::cleanApartment($apartment);
        $lockId = self::smartlocks()[$apartment] ?? '';
        $out = ['apartment' => $apartment, 'lock' => $lockId, 'ok' => false, 'name' => null,
            'online' => null, 'keypad' => null, 'codes' => null, 'problem' => ''];
        if ($lockId === '' || !preg_match('/^\d{5,20}$/', $lockId)) {
            $out['problem'] = 'ID de yală lipsă sau invalid în config/nuki.php.';
            return $out;
        }

        $lock = self::request('GET', "/smartlock/$lockId", null, 12);
        if ($lock['error'] !== '' || $lock['status'] !== 200) {
            $out['problem'] = $lock['error'] !== '' ? 'Nuki nu răspunde.' : self::reason($lock['status'], $lock['body'], $lockId);
            return $out;
        }
        $data = json_decode($lock['body'], true) ?: [];
        $config = (array) ($data['config'] ?? []);
        $out['name'] = isset($data['name']) ? (string) $data['name'] : null;
        $out['online'] = isset($data['serverState']) ? (int) $data['serverState'] === 0 : null;
        $out['keypad'] = !empty($config['keypadPaired']) || !empty($config['keypad2Paired'])
            || !empty(($data['advancedConfig'] ?? [])['keypad2Paired'] ?? false);

        $auths = self::request('GET', "/smartlock/$lockId/auth", null, 12);
        if ($auths['error'] === '' && $auths['status'] === 200) {
            $list = json_decode($auths['body'], true);
            $out['codes'] = is_array($list) ? count(array_filter($list, static fn($a): bool => (int) ($a['type'] ?? -1) === 13)) : null;
        }

        $problems = [];
        if ($out['online'] === false) {
            $problems[] = 'Yala e offline (bridge / Wi-Fi) — codurile noi nu ajung pe ea.';
        }
        if ($out['keypad'] === false) {
            $problems[] = 'Nicio tastatură (Keypad) asociată yalei în Nuki.';
        }
        if ($out['codes'] !== null && $out['codes'] >= self::KEYPAD_CODE_WARN) {
            $problems[] = "{$out['codes']} coduri de tastatură pe yală — aproape de limita Nuki; șterge codurile vechi din aplicația Nuki.";
        }
        $out['problem'] = implode(' ', $problems);
        $out['ok'] = $problems === [];
        return $out;
    }

    /** Log actions worth showing (lock / unlock / door sensor). System noise (calibration, firmware…) is skipped. */
    private const LOG_ACTIONS = [
        1 => 'Descuiat', 2 => 'Încuiat', 3 => 'Deschis (unlatch)', 4 => "Încuiat (Lock 'n' Go)",
        5 => "Lock 'n' Go cu deschidere", 240 => 'Ușă deschisă', 241 => 'Ușă închisă',
    ];
    private const LOG_TRIGGERS = [
        0 => '', 1 => 'manual', 2 => 'buton', 3 => 'automat', 4 => 'web',
        5 => 'aplicație', 6 => 'auto-lock', 7 => 'accesoriu', 255 => 'tastatură',
    ];
    private const LOG_CACHE_TTL = 60;

    /**
     * Last $count meaningful lock events per apartment, newest first — one parallel round to Nuki,
     * cached 60 s in storage/cache (holds names: never under public/). Apartments without a lock are skipped.
     * @param list<string> $apartments
     * @return array<string, list<array{action:string, via:string, name:string, at:string, ok:bool}>>
     */
    public static function recentEvents(array $apartments, int $count = 2): array
    {
        $locks = self::smartlocks();
        $wanted = [];
        foreach ($apartments as $apartment) {
            $apartment = self::cleanApartment((string) $apartment);
            if (isset($locks[$apartment]) && preg_match('/^\d{5,20}$/', $locks[$apartment])) {
                $wanted[$apartment] = $locks[$apartment];
            }
        }
        if (!$wanted) {
            return [];
        }

        $dir = ONE_ROOT . '/storage/cache';
        $file = $dir . '/nuki-log-' . date('Y-m-d') . '.json';
        $cache = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $out = [];
        $missing = [];
        foreach ($wanted as $apartment => $lockId) {
            $hit = $cache[$apartment] ?? null;
            if (is_array($hit) && time() - (int) ($hit['t'] ?? 0) < self::LOG_CACHE_TTL) {
                $out[$apartment] = $hit['events'];
            } else {
                $missing[$apartment] = $lockId;
            }
        }
        if (!$missing) {
            return $out;
        }

        // limit=15: the latest raw entries can be system noise; we keep the first $count meaningful ones.
        $paths = [];
        foreach ($missing as $apartment => $lockId) {
            $paths[$apartment] = "/smartlock/$lockId/log?limit=15";
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        foreach (self::requestMany($paths, 10) as $apartment => $res) {
            if ($res['error'] !== '' || $res['status'] !== 200) {
                self::log(sprintf('log apt=%s http=%d %s', $apartment, $res['status'], $res['error'] ?: 'FAIL'));
                continue; // no line on the card rather than a wrong one
            }
            $entries = array_filter((array) json_decode($res['body'], true), 'is_array');
            usort($entries, static fn(array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
            $events = [];
            foreach ($entries as $entry) {
                $action = (int) ($entry['action'] ?? 0);
                if (!isset(self::LOG_ACTIONS[$action]) || empty($entry['date'])) {
                    continue;
                }
                try {
                    $at = (new \DateTimeImmutable((string) $entry['date']))->setTimezone($tz)->format(DATE_ATOM);
                } catch (\Exception) {
                    continue;
                }
                $events[] = [
                    'action' => self::LOG_ACTIONS[$action],
                    'via'    => self::LOG_TRIGGERS[(int) ($entry['trigger'] ?? -1)] ?? '',
                    'name'   => mb_substr(trim((string) ($entry['name'] ?? '')), 0, 40),
                    'at'     => $at,
                    'ok'     => (int) ($entry['state'] ?? 0) === 0,
                ];
                if (count($events) >= $count) {
                    break;
                }
            }
            $out[$apartment] = $events;
            $cache[$apartment] = ['t' => time(), 'events' => $events];
        }

        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            @file_put_contents($file, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $out;
    }

    /** Above this many keypad codes the lock is flagged (Nuki Keypad holds at most 100 / 200 codes). */
    private const KEYPAD_CODE_WARN = 90;

    /** Human reason for a non-success Nuki answer. Never includes the token. */
    private static function reason(int $status, string $body, string $lockId): string
    {
        $data = json_decode($body, true);
        $detail = is_array($data) ? trim((string) ($data['detailMessage'] ?? $data['message'] ?? $data['error'] ?? '')) : '';
        $detail = $detail !== '' ? ' (' . mb_substr($detail, 0, 160) . ')' : '';
        return match (true) {
            $status === 401 => 'Tokenul Nuki e invalid sau a expirat — actualizează api_token în config/nuki.php.',
            $status === 403 => "Tokenul Nuki nu are acces la yala $lockId — yala e probabil în alt cont Nuki sau tokenul nu are dreptul „Manage authorizations”.",
            $status === 404 => "Yala $lockId nu există în contul Nuki — ID greșit în config/nuki.php.",
            $status === 400, $status === 422 => 'Nuki a refuzat codul' . ($detail ?: ' (cod invalid sau limita de coduri a tastaturii atinsă).'),
            $status === 423 => 'Yala e blocată de o altă operație Nuki. Reîncearcă în câteva secunde.',
            $status >= 500 => 'Nuki are o problemă temporară (HTTP ' . $status . '). Reîncearcă.',
            default => 'Nuki a refuzat codul (HTTP ' . $status . ')' . $detail . '.',
        };
    }

    /** @return array{status:int, body:string, error:string} */
    private static function request(string $method, string $path, ?string $body = null, int $timeout = 25): array
    {
        $config = self::config();
        $base = rtrim((string) ($config['api_base'] ?? 'https://api.nuki.io'), '/');
        $ch = curl_init($base . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Cache-Control: no-cache',
                'Authorization: Bearer ' . $config['api_token'],
                'Content-Type: application/json',
            ],
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $error];
    }

    /**
     * Parallel GETs (curl_multi) — one Nuki round-trip for every card instead of one per lock.
     * @param array<string,string> $paths key => path
     * @return array<string, array{status:int, body:string, error:string}>
     */
    private static function requestMany(array $paths, int $timeout = 10): array
    {
        $config = self::config();
        $base = rtrim((string) ($config['api_base'] ?? 'https://api.nuki.io'), '/');
        $multi = curl_multi_init();
        $handles = [];
        foreach ($paths as $key => $path) {
            $ch = curl_init($base . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $config['api_token']],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $out[$key] = [
                'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body'   => is_string($body) ? $body : '',
                'error'  => curl_error($ch),
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $out;
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
