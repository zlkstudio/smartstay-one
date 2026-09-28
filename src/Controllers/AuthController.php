<?php
declare(strict_types=1);

namespace One\Controllers;

use One\Audit;
use One\Auth\Access;
use One\Auth\Auth;
use One\Http\Guard;
use One\Users\UserRepository;

final class AuthController
{
    public const MIN_PASSWORD = 10;

    public static function showLogin(): never
    {
        if ($user = Auth::user()) {
            redirect(Access::homePath($user));
        }
        view('pages/login', [
            'pageTitle'  => 'Autentificare',
            'next'       => self::safeNext($_GET['next'] ?? ''),
            'identifier' => '',
            'error'      => isset($_GET['expired']) ? 'Sesiunea a expirat. Autentifică-te din nou.' : null,
        ], 'layout-bare');
    }

    public static function login(): never
    {
        Guard::requireCsrf();
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $result = Auth::attempt($identifier, (string) ($_POST['password'] ?? ''), isset($_POST['remember']));
        $next = self::safeNext($_POST['next'] ?? '');

        if (!$result['ok']) {
            http_response_code(422);
            view('pages/login', [
                'pageTitle'  => 'Autentificare',
                'next'       => $next,
                'identifier' => $identifier,
                'error'      => $result['error'],
            ], 'layout-bare');
        }

        $user = $result['user'];
        if ((int) $user['must_change_password']) {
            redirect('/account/password');
        }
        redirect($next ?: Access::homePath($user));
    }

    public static function logout(): never
    {
        Guard::requireCsrf();
        Auth::logout();
        redirect('/login');
    }

    public static function account(): never
    {
        $user = Guard::requireLogin();
        view('pages/account', [
            'user'      => $user,
            'pageTitle' => 'Contul meu',
            'active'    => 'account',
            'backHref'  => Access::homePath($user),
        ]);
    }

    public static function showPassword(): never
    {
        $user = Guard::requireLogin();
        view('pages/password', [
            'user'      => $user,
            'pageTitle' => 'Schimbă parola',
            'active'    => 'account',
            'backHref'  => '/account',
            'forced'    => (bool) (int) $user['must_change_password'],
            'errors'    => [],
        ], (int) $user['must_change_password'] ? 'layout-bare' : 'layout');
    }

    public static function changePassword(): never
    {
        $user = Guard::requireLogin();
        Guard::requireCsrf();

        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');
        $errors = [];

        if (!password_verify($current, $user['password_hash'])) {
            $errors['current'] = 'Parola actuală nu e corectă.';
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            $errors['new'] = 'Minim ' . self::MIN_PASSWORD . ' caractere.';
        } elseif ($new === $current) {
            $errors['new'] = 'Alege o parolă diferită de cea actuală.';
        }
        if ($new !== $confirm) {
            $errors['confirm'] = 'Parolele nu coincid.';
        }

        $forced = (bool) (int) $user['must_change_password'];
        if ($errors) {
            http_response_code(422);
            view('pages/password', [
                'user'      => $user,
                'pageTitle' => 'Schimbă parola',
                'active'    => 'account',
                'backHref'  => '/account',
                'forced'    => $forced,
                'errors'    => $errors,
            ], $forced ? 'layout-bare' : 'layout');
        }

        UserRepository::setPassword((int) $user['id'], $new, false);
        Auth::revokeAll((int) $user['id'], keepCurrent: true);
        Audit::log((int) $user['id'], 'auth.password_change', 'user', (string) $user['id']);

        redirect($forced ? Access::homePath($user) . '?welcome=1' : '/account?ok=password');
    }

    /** Only same-site relative paths — prevents open redirects via ?next=. */
    private static function safeNext(mixed $next): string
    {
        $next = is_string($next) ? $next : '';
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return '';
        }
        return $next;
    }
}
