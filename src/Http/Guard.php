<?php
declare(strict_types=1);

namespace One\Http;

use One\Auth\Access;
use One\Auth\Auth;

/** Middleware. Every controller starts with one of these — the UI never decides access. */
final class Guard
{
    private const PASSWORD_CHANGE_ALLOWED = ['/account/password', '/logout'];

    public static function isApi(): bool
    {
        return str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/api/');
    }

    public static function requireLogin(): array
    {
        $user = Auth::user();
        if ($user === null) {
            if (self::isApi()) {
                json_response(['ok' => false, 'error' => 'Sesiunea a expirat. Autentifică-te din nou.'], 401);
            }
            $next = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            redirect('/login' . ($next !== '/' ? '?next=' . rawurlencode($next) : ''));
        }

        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ((int) $user['must_change_password'] && !in_array($path, self::PASSWORD_CHANGE_ALLOWED, true)) {
            if (self::isApi()) {
                json_response(['ok' => false, 'error' => 'Schimbă parola temporară înainte de a continua.'], 403);
            }
            redirect('/account/password');
        }
        return $user;
    }

    /** @param 'view'|'edit' $level */
    public static function requireAccess(string $module, string $level = 'view'): array
    {
        $user = self::requireLogin();
        if (!Access::can($user, $module, $level)) {
            self::forbidden($user);
        }
        return $user;
    }

    /** CSRF token + Origin check for every state-changing request. */
    public static function requireCsrf(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && $origin !== 'null' && !self::sameOrigin($origin)) {
            self::csrfFailed();
        }
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!Auth::verifyCsrf(is_string($token) ? $token : null)) {
            self::csrfFailed();
        }
    }

    public static function forbidden(?array $user = null): never
    {
        http_response_code(403);
        if (self::isApi()) {
            json_response(['ok' => false, 'error' => 'Nu ai acces la această secțiune.'], 403);
        }
        view('pages/forbidden', ['user' => $user, 'pageTitle' => 'Acces restricționat', 'active' => null]);
    }

    public static function notFound(string $path = ''): never
    {
        http_response_code(404);
        if (self::isApi()) {
            json_response(['ok' => false, 'error' => 'Endpoint inexistent.'], 404);
        }
        view('pages/not-found', ['user' => Auth::user(), 'pageTitle' => 'Pagină inexistentă', 'active' => null]);
    }

    private static function csrfFailed(): never
    {
        if (self::isApi()) {
            json_response(['ok' => false, 'error' => 'Cerere respinsă. Reîncarcă pagina și încearcă din nou.'], 419);
        }
        http_response_code(419);
        view('pages/expired', ['user' => Auth::user(), 'pageTitle' => 'Pagina a expirat', 'active' => null]);
    }

    private static function sameOrigin(string $origin): bool
    {
        $expected = parse_url((string) config('base_url', ''), PHP_URL_HOST);
        $host = parse_url($origin, PHP_URL_HOST);
        $requestHost = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
        return $host !== null && ($host === $expected || $host === $requestHost);
    }
}
