<?php
declare(strict_types=1);

namespace One\Auth;

use One\Audit;
use One\Db\Database;
use One\Users\UserRepository;

/**
 * Server-side sessions in smartconcept_one.sessions.
 *
 * Cookie holds a random 256-bit token; the DB stores only sha256(token).
 * "Ține-mă minte" = 90-day sliding session: the token is rotated at most once per
 * session_rotate_hours (new row, old row kept valid for 2 minutes so parallel
 * requests from the PWA don't log the user out).
 */
final class Auth
{
    public const COOKIE = 'ss1_session';
    public const GUEST_CSRF_COOKIE = 'ss1_csrf';
    private const ROTATION_GRACE_SECONDS = 120;
    private const TOUCH_EVERY_SECONDS = 600;

    private static bool $resolved = false;
    private static ?array $user = null;
    private static ?array $session = null;

    public static function user(): ?array
    {
        if (!self::$resolved) {
            self::$resolved = true;
            self::resolve();
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user() ? (int) self::$user['id'] : null;
    }

    /**
     * @return array{ok:bool, error?:string, user?:array}
     */
    public static function attempt(string $identifier, string $password, bool $remember): array
    {
        $identifier = mb_strtolower(trim($identifier));
        $key = str_contains($identifier, '@') ? $identifier : normalize_phone($identifier);
        $ip = client_ip();

        if ($key === '' || $password === '') {
            return ['ok' => false, 'error' => 'Completează utilizatorul și parola.'];
        }

        $wait = LoginThrottle::retryAfter($key, $ip);
        if ($wait > 0) {
            return ['ok' => false, 'error' => sprintf('Prea multe încercări. Încearcă din nou în %d minute.', (int) ceil($wait / 60))];
        }

        $user = UserRepository::findByIdentifier($identifier);
        // Constant-time-ish: always run a verify, even for unknown users.
        $hash = $user['password_hash'] ?? '$2y$12$4mh4Ym7Axvi7NTtASPynCeBVcWoO/.UyGFLjV/b4HmUAXCZ9GI1tm';
        $valid = password_verify($password, $hash);

        if (!$user || !$valid || !(int) $user['active']) {
            LoginThrottle::record($key, $ip, false);
            $message = ($user && $valid && !(int) $user['active'])
                ? 'Contul este dezactivat. Contactează administratorul.'
                : 'Utilizator sau parolă greșită.';
            return ['ok' => false, 'error' => $message];
        }

        LoginThrottle::record($key, $ip, true);
        UserRepository::rehashIfNeeded($user, $password);
        UserRepository::touchLogin((int) $user['id']);
        self::startSession($user, $remember);
        Audit::log((int) $user['id'], 'auth.login');

        return ['ok' => true, 'user' => $user];
    }

    public static function startSession(array $user, bool $remember): void
    {
        $token = bin2hex(random_bytes(32));
        $lifetime = self::lifetimeSeconds($remember);

        Database::get('one')->prepare(
            'INSERT INTO sessions (id, user_id, csrf_token, remember, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            hash('sha256', $token),
            $user['id'],
            bin2hex(random_bytes(32)),
            $remember ? 1 : 0,
            client_ip(),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            utc_plus($lifetime),
        ]);

        self::setCookie(self::COOKIE, $token, $remember ? time() + $lifetime : 0);
        self::$resolved = false;
        $_COOKIE[self::COOKIE] = $token;
    }

    public static function logout(): void
    {
        if (self::user()) {
            Audit::log(self::id(), 'auth.logout');
        }
        if (self::$session) {
            // Rotated copies share the csrf_token: delete the whole family, incl. the grace-period row.
            Database::get('one')->prepare('DELETE FROM sessions WHERE user_id = ? AND csrf_token = ?')
                ->execute([self::id(), self::$session['csrf_token']]);
        }
        self::setCookie(self::COOKIE, '', time() - 3600);
        self::$user = null;
        self::$session = null;
    }

    /** Ends every session of a user, optionally keeping the current one. */
    public static function revokeAll(int $userId, bool $keepCurrent = false): void
    {
        $current = $keepCurrent && self::$session ? self::$session['id'] : '';
        Database::get('one')->prepare('DELETE FROM sessions WHERE user_id = ? AND id <> ?')
            ->execute([$userId, $current]);
    }

    public static function csrfToken(): string
    {
        if (self::user() && self::$session) {
            return self::$session['csrf_token'];
        }
        $token = $_COOKIE[self::GUEST_CSRF_COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            self::setCookie(self::GUEST_CSRF_COOKIE, $token, 0);
            $_COOKIE[self::GUEST_CSRF_COOKIE] = $token;
        }
        return $token;
    }

    public static function verifyCsrf(?string $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }
        return hash_equals(self::csrfToken(), $submitted);
    }

    /** Refreshes the cached user after an account change in the same request. */
    public static function refresh(): void
    {
        self::$resolved = false;
        self::$user = null;
    }

    private static function resolve(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return;
        }

        $db = Database::get('one');
        $stmt = $db->prepare(
            'SELECT s.id AS s_id, s.user_id, s.csrf_token, s.remember, s.last_seen_at, s.rotated_at, s.expires_at
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > UTC_TIMESTAMP() AND u.active = 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $session = $stmt->fetch();
        if (!$session) {
            self::setCookie(self::COOKIE, '', time() - 3600);
            return;
        }

        $user = UserRepository::find((int) $session['user_id']);
        if (!$user) {
            return;
        }

        self::$user = $user;
        self::$session = [
            'id'         => $session['s_id'],
            'csrf_token' => $session['csrf_token'],
            'remember'   => (bool) $session['remember'],
        ];

        self::slide($token, $session);
    }

    /** Sliding expiry + periodic token rotation. */
    private static function slide(string $token, array $session): void
    {
        $remember = (bool) $session['remember'];
        $lifetime = self::lifetimeSeconds($remember);
        $rotateAfter = (int) config('session_rotate_hours', 24) * 3600;
        $rotatedAt = strtotime($session['rotated_at'] . ' UTC');
        $lastSeen = strtotime($session['last_seen_at'] . ' UTC');
        $db = Database::get('one');

        // Only remembered sessions rotate; short sessions just expire.
        if ($remember && time() - $rotatedAt >= $rotateAfter && !headers_sent()) {
            $newToken = bin2hex(random_bytes(32));
            $newId = hash('sha256', $newToken);
            $db->beginTransaction();
            try {
                $db->prepare(
                    'INSERT INTO sessions (id, user_id, csrf_token, remember, ip_address, user_agent, created_at, expires_at)
                     SELECT ?, user_id, csrf_token, remember, ?, ?, created_at, ? FROM sessions WHERE id = ?'
                )->execute([
                    $newId,
                    client_ip(),
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    utc_plus($lifetime),
                    $session['s_id'],
                ]);
                // Old token stays valid briefly for in-flight parallel requests.
                // rotated_at = now so a request still carrying the old token doesn't rotate again.
                $db->prepare('UPDATE sessions SET expires_at = LEAST(expires_at, ?), rotated_at = UTC_TIMESTAMP() WHERE id = ?')
                    ->execute([utc_plus(self::ROTATION_GRACE_SECONDS), $session['s_id']]);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                error_log('[ONE] session rotation failed: ' . $e->getMessage());
                return;
            }
            self::$session['id'] = $newId;
            self::setCookie(self::COOKIE, $newToken, time() + $lifetime);
            return;
        }

        if (time() - $lastSeen >= self::TOUCH_EVERY_SECONDS) {
            $db->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?')
                ->execute([utc_plus($lifetime), $session['s_id']]);
            if ($remember && !headers_sent()) {
                self::setCookie(self::COOKIE, $token, time() + $lifetime);
            }
        }
    }

    private static function lifetimeSeconds(bool $remember): int
    {
        return $remember
            ? (int) config('session_days', 90) * 86400
            : (int) config('session_short_hours', 12) * 3600;
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => (bool) config('cookie_secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
