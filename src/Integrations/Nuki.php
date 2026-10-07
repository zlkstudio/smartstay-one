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

    /** Labels for the lock log. Unknown actions still show ("Eveniment N") — only pure system noise is skipped. */
    private const LOG_ACTIONS = [
        1 => 'Descuiat', 2 => 'Încuiat', 3 => 'Deschis (unlatch)', 4 => "Încuiat (Lock 'n' Go)",
        5 => "Lock 'n' Go cu deschidere", 208 => 'Ușă întredeschisă', 209 => 'Stare ușă neclară',
        224 => 'Sonerie', 240 => 'Ușă deschisă', 241 => 'Ușă închisă', 242 => 'Senzor ușă blocat',
    ];
    /** Firmware, calibration, log on/off, initialisation — never what the team is looking for. */
    private const LOG_NOISE = [243, 250, 251, 252, 253, 254, 255];
    private const LOG_TRIGGERS = [
        0 => '', 1 => 'manual', 2 => 'buton', 3 => 'automat', 4 => 'web',
        5 => 'aplicație', 6 => 'auto-lock', 7 => 'accesoriu', 255 => 'tastatură',
    ];
    private const LOG_CACHE_TTL = 60;

    /**
     * Last $count meaningful lock events per apartment, newest first — one parallel round to Nuki,
     * cached 60 s in storage/cache (holds names: never under public/). Apartments without a lock are skipped.
     * @param list<string> $apartments
     * Locks Nuki could not be asked about land in $errors (apartment => short reason) instead of an empty list,
     * so the card says why rather than "no events".
     * @return array<string, list<array{action:string, via:string, name:string, at:string, ok:bool}>>
     */
    public static function recentEvents(array $apartments, int $count = 2, ?array &$errors = null): array
    {
        $locks = self::smartlocks();
        $errors = [];
        $wanted = [];
        foreach ($apartments as $apartment) {
            $apartment = self::cleanApartment((string) $apartment);
            if (!isset($locks[$apartment])) {
                continue;
            }
            if (preg_match('/^\d{5,20}$/', $locks[$apartment])) {
                $wanted[$apartment] = $locks[$apartment];
            } else {
                $errors[$apartment] = 'ID yală invalid în config/nuki.php';
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

        // limit=30: the latest raw entries can be system noise; we keep the first $count real ones.
        $paths = [];
        foreach ($missing as $apartment => $lockId) {
            $paths[$apartment] = "/smartlock/$lockId/log?limit=30";
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        foreach (self::requestMany($paths, 10) as $apartment => $res) {
            if ($res['error'] !== '' || $res['status'] !== 200) {
                self::log(sprintf('log apt=%s http=%d %s', $apartment, $res['status'], $res['error'] ?: 'FAIL'));
                $errors[$apartment] = $res['error'] !== '' ? 'fără răspuns' : 'HTTP ' . $res['status'];
                continue;
            }
            $entries = array_filter((array) json_decode($res['body'], true), 'is_array');
            usort($entries, static fn(array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
            $events = [];
            foreach ($entries as $entry) {
                $action = (int) ($entry['action'] ?? 0);
                if (in_array($action, self::LOG_NOISE, true) || empty($entry['date'])) {
                    continue;
                }
                try {
                    $at = (new \DateTimeImmutable((string) $entry['date']))->setTimezone($tz)->format(DATE_ATOM);
                } catch (\Exception) {
                    continue;
                }
                [$name, $via] = self::logActor((string) ($entry['name'] ?? ''), (int) ($entry['trigger'] ?? -1));
                $events[] = [
                    'action' => self::LOG_ACTIONS[$action] ?? "Eveniment $action",
                    'via'    => $via,
                    'name'   => $name,
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

    // ── Cleaning tracker: raw log, keypad codes, deleting the departed guest's code ──

    /** Unlock-type actions (the maid coming in) and lock-type actions (the maid leaving). */
    public const UNLOCK_ACTIONS = [1, 3, 5];
    public const LOCK_ACTIONS = [2, 4];

    /**
     * Codes that are NEVER deleted, whatever matches: staff and the building entry.
     * config/nuki.php 'protected_names' adds more; it can never remove these.
     */
    private const PROTECTED_NAMES = ['Romeo', 'Ioana Menaj', 'Cristina Menaj', 'Entry Code'];

    /**
     * Today's raw lock events per apartment, oldest first, no cache (the tracker needs the exact
     * times). One parallel round to Nuki. Failed locks land in $errors.
     * @param list<string> $apartments
     * @return array<string, list<array{action:int, name:string, via:string, at:string, ok:bool}>>
     */
    public static function logEntries(array $apartments, string $date, int $limit = 50, ?array &$errors = null): array
    {
        $errors = [];
        $locks = self::smartlocks();
        $paths = [];
        foreach ($apartments as $apartment) {
            $apartment = self::cleanApartment((string) $apartment);
            $lockId = $locks[$apartment] ?? '';
            if (preg_match('/^\d{5,20}$/', $lockId)) {
                $paths[$apartment] = "/smartlock/$lockId/log?limit=$limit";
            }
        }
        if (!$paths) {
            return [];
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        $out = [];
        foreach (self::requestMany($paths, 10) as $apartment => $res) {
            if ($res['error'] !== '' || $res['status'] !== 200) {
                $errors[$apartment] = $res['error'] !== '' ? 'fără răspuns' : 'HTTP ' . $res['status'];
                continue;
            }
            $events = [];
            foreach (array_filter((array) json_decode($res['body'], true), 'is_array') as $entry) {
                if (empty($entry['date'])) {
                    continue;
                }
                try {
                    $at = (new \DateTimeImmutable((string) $entry['date']))->setTimezone($tz);
                } catch (\Exception) {
                    continue;
                }
                if ($at->format('Y-m-d') !== $date) {
                    continue;
                }
                [$name, $via] = self::logActor((string) ($entry['name'] ?? ''), (int) ($entry['trigger'] ?? -1));
                $events[] = [
                    'action' => (int) ($entry['action'] ?? 0),
                    'name'   => $name,
                    'via'    => $via,
                    'at'     => $at->format('Y-m-d H:i:s'),
                    'ok'     => (int) ($entry['state'] ?? 0) === 0,
                ];
            }
            usort($events, static fn(array $a, array $b): int => strcmp($a['at'], $b['at']));
            $out[(string) $apartment] = $events;
        }
        return $out;
    }

    /** "Ioana  Menaj (Keypad)" → "ioana menaj" — lowercase ASCII, single spaces. */
    public static function normalizeName(string $name): string
    {
        $name = (string) preg_replace('/\s*\((keypad|tastatur[aă])\)\s*$/iu', '', trim($name));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $name = strtolower($ascii !== false ? $ascii : $name);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    /** True when the code belongs to staff / the entry door — contains a protected name as whole words. */
    public static function isProtected(string $name): bool
    {
        $norm = self::normalizeName($name);
        if ($norm === '') {
            return false;
        }
        $extra = self::isConfigured() ? (array) (self::config()['protected_names'] ?? []) : [];
        foreach ([...self::PROTECTED_NAMES, ...$extra] as $protected) {
            $p = self::normalizeName((string) $protected);
            if ($p !== '' && preg_match('/(^| )' . preg_quote($p, '/') . '( |$)/', $norm)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deletes the departed guest's keypad code(s) from the apartment's lock.
     * A code is deleted only when ALL hold: keypad type · not protected (Romeo, Ioana Menaj, Cristina Menaj,
     * Entry Code, config 'protected_names') · its code equals the guest's code OR its name equals the guest's
     * name · it is not the code / name of a guest still to come ($keepCodes / $keepNames).
     * @param list<string> $keepCodes @param list<string> $keepNames
     * @return array{status:string, note:string} status: deleted | not_found | failed | skipped
     */
    public static function removeGuestCode(string $apartment, string $guestName, ?string $guestCode, array $keepCodes, array $keepNames): array
    {
        $apartment = self::cleanApartment($apartment);
        $lockId = self::smartlocks()[$apartment] ?? '';
        if (!preg_match('/^\d{5,20}$/', $lockId)) {
            return ['status' => 'skipped', 'note' => 'fără yală Nuki'];
        }
        $guestNorm = self::normalizeName(mb_substr($guestName, 0, 32));
        if ($guestNorm === '' && $guestCode === null) {
            return ['status' => 'skipped', 'note' => 'oaspete fără nume și telefon'];
        }
        if (self::isProtected($guestName)) {
            return ['status' => 'skipped', 'note' => 'numele oaspetelui e protejat'];
        }
        $keepNames = array_map(static fn(string $n): string => self::normalizeName(mb_substr($n, 0, 32)), $keepNames);

        $res = self::request('GET', "/smartlock/$lockId/auth", null, 12);
        if ($res['error'] !== '' || $res['status'] !== 200) {
            self::log("cleanup apt=$apartment list http={$res['status']} " . ($res['error'] ?: 'FAIL'));
            return ['status' => 'failed', 'note' => 'lista de coduri nu s-a putut citi'];
        }

        $deleted = [];
        $failed = 0;
        foreach (array_filter((array) json_decode($res['body'], true), 'is_array') as $auth) {
            if ((int) ($auth['type'] ?? -1) !== 13 || empty($auth['id'])) {
                continue;
            }
            $name = (string) ($auth['name'] ?? '');
            $code = isset($auth['code']) ? str_pad((string) (int) $auth['code'], 6, '0', STR_PAD_LEFT) : null;
            if (self::isProtected($name)) {
                continue;
            }
            $byCode = $guestCode !== null && $code !== null && $code === $guestCode;
            // "Guest 99" is the default name for any guest without one: only the code may match it.
            $byName = $guestNorm !== '' && !preg_match('/^guest \d+$/', $guestNorm) && self::normalizeName($name) === $guestNorm;
            if (!$byCode && !$byName) {
                continue;
            }
            if (($code !== null && in_array($code, $keepCodes, true)) || in_array(self::normalizeName($name), $keepNames, true)) {
                continue;   // the same code / name belongs to a guest who is still coming
            }
            $authId = preg_replace('/[^A-Za-z0-9]/', '', (string) $auth['id']);
            $del = self::request('DELETE', "/smartlock/$lockId/auth/$authId", null, 12);
            $ok = $del['error'] === '' && in_array($del['status'], [200, 204], true);
            self::log(sprintf('cleanup apt=%s auth=%s name="%s" http=%d %s', $apartment, $authId, $name, $del['status'], $ok ? 'DELETED' : 'FAIL'));
            if ($ok) {
                $deleted[] = $name;
            } else {
                $failed++;
            }
        }

        if ($failed) {
            return ['status' => 'failed', 'note' => $deleted ? 'șters parțial: ' . implode(', ', $deleted) : 'Nuki a refuzat ștergerea'];
        }
        return $deleted
            ? ['status' => 'deleted', 'note' => implode(', ', $deleted)]
            : ['status' => 'not_found', 'note' => 'niciun cod al oaspetelui pe yală'];
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
     * Who acted, as the team reads it. "Nuki Web (…)" is the Web API = the Guest App door buttons;
     * " (Keypad)" is dropped because the trigger already says "tastatură".
     * @return array{0:string, 1:string} [name, via]
     */
    private static function logActor(string $raw, int $trigger): array
    {
        $name = trim($raw);
        $via = self::LOG_TRIGGERS[$trigger] ?? '';
        if (stripos($name, 'Nuki Web') === 0) {
            return ['', 'Guest App'];
        }
        $name = trim((string) preg_replace('/\s*\((keypad|tastatur[aă])\)\s*$/iu', '', $name));
        if ($name !== '' && strcasecmp($name, 'keypad') === 0) {
            $name = '';
        }
        return [mb_substr($name, 0, 40), $via];
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
