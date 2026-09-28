<?php
declare(strict_types=1);

namespace One\Controllers;

use One\Audit;
use One\Auth\Access;
use One\Auth\Auth;
use One\Http\Guard;
use One\Users\UserRepository;

final class UsersController
{
    public static function index(): never
    {
        $user = Guard::requireAccess('users', 'edit');
        view('pages/users/index', [
            'user'      => $user,
            'pageTitle' => 'Utilizatori',
            'active'    => 'users',
            'backHref'  => '/account',
            'users'     => UserRepository::all(),
            'activity'  => Audit::recent(15),
            'notice'    => self::notice($_GET['ok'] ?? null),
        ]);
    }

    public static function create(): never
    {
        $user = Guard::requireAccess('users', 'edit');
        self::form($user, null, self::blank(), []);
    }

    public static function store(): never
    {
        $admin = Guard::requireAccess('users', 'edit');
        Guard::requireCsrf();

        [$data, $errors] = self::validate($_POST, 0);
        if ($errors) {
            self::form($admin, null, $data, $errors, 422);
        }

        $password = UserRepository::temporaryPassword();
        $id = UserRepository::create($data, $password);
        if ($data['role'] === 'user') {
            UserRepository::setPermissions($id, $data['permissions']);
        }
        Audit::log((int) $admin['id'], 'user.create', 'user', (string) $id, [
            'role'        => $data['role'],
            'permissions' => $data['role'] === 'user' ? $data['permissions'] : null,
        ]);

        self::credentials($admin, UserRepository::find($id), $password, 'created');
    }

    public static function edit(array $params): never
    {
        $admin = Guard::requireAccess('users', 'edit');
        $target = self::findOr404((int) $params['id']);
        $data = $target;
        $data['phone'] = $target['phone'] ?? '';
        self::form($admin, $target, $data, []);
    }

    public static function update(array $params): never
    {
        $admin = Guard::requireAccess('users', 'edit');
        Guard::requireCsrf();
        $target = self::findOr404((int) $params['id']);

        [$data, $errors] = self::validate($_POST, (int) $target['id']);

        $isSelf = (int) $target['id'] === (int) $admin['id'];
        if ($isSelf && $data['role'] !== 'admin') {
            $errors['role'] = 'Nu îți poți schimba propriul rol de admin.';
        } elseif ($target['role'] === 'admin' && $data['role'] !== 'admin'
            && (int) $target['active'] && UserRepository::countActiveAdmins() <= 1) {
            $errors['role'] = 'Trebuie să rămână cel puțin un admin activ.';
        }

        if ($errors) {
            self::form($admin, $target, $data, $errors, 422);
        }

        UserRepository::update((int) $target['id'], $data);
        UserRepository::setPermissions((int) $target['id'], $data['role'] === 'user' ? $data['permissions'] : []);

        $changes = self::diff($target, $data);
        if ($changes) {
            Audit::log((int) $admin['id'], 'user.update', 'user', (string) $target['id'], $changes);
        }
        if ($target['permissions'] !== ($data['role'] === 'user' ? $data['permissions'] : [])) {
            Audit::log((int) $admin['id'], 'permission.update', 'user', (string) $target['id'], [
                'before' => $target['permissions'],
                'after'  => $data['role'] === 'user' ? $data['permissions'] : [],
            ]);
        }
        redirect('/users?ok=updated');
    }

    public static function resetPassword(array $params): never
    {
        $admin = Guard::requireAccess('users', 'edit');
        Guard::requireCsrf();
        $target = self::findOr404((int) $params['id']);

        $password = UserRepository::temporaryPassword();
        UserRepository::setPassword((int) $target['id'], $password, true);
        Auth::revokeAll((int) $target['id'], keepCurrent: (int) $target['id'] === (int) $admin['id']);
        Audit::log((int) $admin['id'], 'user.password_reset', 'user', (string) $target['id']);

        self::credentials($admin, UserRepository::find((int) $target['id']), $password, 'reset');
    }

    public static function toggleActive(array $params): never
    {
        $admin = Guard::requireAccess('users', 'edit');
        Guard::requireCsrf();
        $target = self::findOr404((int) $params['id']);
        $activate = !(int) $target['active'];

        if (!$activate) {
            if ((int) $target['id'] === (int) $admin['id']) {
                redirect('/users/' . $target['id'] . '/edit?err=self');
            }
            if ($target['role'] === 'admin' && UserRepository::countActiveAdmins() <= 1) {
                redirect('/users/' . $target['id'] . '/edit?err=lastadmin');
            }
        }

        UserRepository::setActive((int) $target['id'], $activate);
        if (!$activate) {
            Auth::revokeAll((int) $target['id']);
        }
        Audit::log((int) $admin['id'], $activate ? 'user.activate' : 'user.deactivate', 'user', (string) $target['id']);
        redirect('/users?ok=' . ($activate ? 'activated' : 'deactivated'));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private static function form(array $admin, ?array $target, array $data, array $errors, int $status = 200): never
    {
        http_response_code($status);
        $err = $_GET['err'] ?? null;
        if ($err === 'self') {
            $errors['_'] = 'Nu îți poți dezactiva propriul cont.';
        } elseif ($err === 'lastadmin') {
            $errors['_'] = 'Acesta e ultimul admin activ — nu poate fi dezactivat.';
        }
        view('pages/users/form', [
            'user'      => $admin,
            'pageTitle' => $target ? 'Editează utilizator' : 'Utilizator nou',
            'active'    => 'users',
            'backHref'  => '/users',
            'target'    => $target,
            'data'      => $data,
            'errors'    => $errors,
            'maids'     => config('maids', []),
        ]);
    }

    private static function credentials(array $admin, array $target, string $password, string $reason): never
    {
        header('Cache-Control: no-store');
        view('pages/users/credentials', [
            'user'      => $admin,
            'pageTitle' => $reason === 'created' ? 'Cont creat' : 'Parolă resetată',
            'active'    => 'users',
            'target'    => $target,
            'password'  => $password,
            'reason'    => $reason,
            'loginUrl'  => rtrim((string) config('base_url'), '/'),
        ]);
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private static function validate(array $input, int $exceptId): array
    {
        $errors = [];
        $maids = config('maids', []);

        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        $phone = $phoneRaw === '' ? '' : normalize_phone($phoneRaw);
        $role = (string) ($input['role'] ?? '');
        $maidRef = (string) ($input['maid_ref'] ?? '');

        $permissions = [];
        foreach (Access::MODULES as $module) {
            $level = (string) ($input['perm'][$module] ?? 'none');
            if (in_array($level, ['view', 'edit'], true)) {
                $permissions[$module] = $level;
            }
        }

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Numele e obligatoriu (max. 100 caractere).';
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
            $errors['email'] = 'Email invalid.';
        } elseif ($email !== '' && UserRepository::identifierTaken('email', $email, $exceptId)) {
            $errors['email'] = 'Există deja un cont cu acest email.';
        }
        if ($phone !== '' && !preg_match('/^\d{10,15}$/', $phone)) {
            $errors['phone'] = 'Telefon invalid.';
        } elseif ($phone !== '' && UserRepository::identifierTaken('phone', $phone, $exceptId)) {
            $errors['phone'] = 'Există deja un cont cu acest telefon.';
        }
        if ($email === '' && $phone === '') {
            $errors['email'] = 'Completează emailul sau telefonul — cu el se face autentificarea.';
        }
        if (!array_key_exists($role, Access::ROLE_LABELS)) {
            $errors['role'] = 'Alege un rol.';
        }
        if ($role === 'maid') {
            if (!array_key_exists($maidRef, $maids)) {
                $errors['maid_ref'] = 'Alege menajera din listă.';
            } elseif (UserRepository::maidRefTaken($maidRef, $exceptId)) {
                $errors['maid_ref'] = 'Menajera are deja un cont.';
            }
        }
        if ($role === 'user' && !$permissions) {
            $errors['perm'] = 'Dă acces la cel puțin un modul.';
        }

        return [[
            'name'        => $name,
            'email'       => $email === '' ? null : $email,
            'phone'       => $phone === '' ? null : $phone,
            'role'        => $role,
            'maid_ref'    => $role === 'maid' ? $maidRef : null,
            'permissions' => $role === 'user' ? $permissions : [],
        ], $errors];
    }

    private static function blank(): array
    {
        return ['name' => '', 'email' => '', 'phone' => '', 'role' => 'user', 'maid_ref' => null, 'permissions' => []];
    }

    private static function findOr404(int $id): array
    {
        $target = UserRepository::find($id);
        if (!$target) {
            Guard::notFound();
        }
        return $target;
    }

    private static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (['name', 'email', 'phone', 'role', 'maid_ref'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }
        return $changes;
    }

    private static function notice(mixed $key): ?string
    {
        return match ($key) {
            'updated'     => 'Modificările au fost salvate.',
            'activated'   => 'Contul a fost reactivat.',
            'deactivated' => 'Contul a fost dezactivat și deconectat de pe toate dispozitivele.',
            default       => null,
        };
    }
}
