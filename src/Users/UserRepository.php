<?php
declare(strict_types=1);

namespace One\Users;

use One\Auth\Access;
use One\Db\Database;
use PDO;

final class UserRepository
{
    private static function db(): PDO
    {
        return Database::get('one');
    }

    /** @return array<string,mixed>|null user row + 'permissions' map */
    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ? self::withPermissions($user) : null;
    }

    /** Looks up by email (case-insensitive) or phone (normalized). */
    public static function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        if (str_contains($identifier, '@')) {
            $stmt = self::db()->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([mb_strtolower($identifier)]);
        } else {
            $stmt = self::db()->prepare('SELECT * FROM users WHERE phone = ?');
            $stmt->execute([normalize_phone($identifier)]);
        }
        $user = $stmt->fetch();
        return $user ? self::withPermissions($user) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $users = self::db()->query(
            "SELECT * FROM users ORDER BY active DESC,
                FIELD(role, 'admin','manager','user','maid'), name"
        )->fetchAll();

        $perms = self::db()->query('SELECT user_id, module, access_level FROM permissions')->fetchAll();
        $byUser = [];
        foreach ($perms as $p) {
            $byUser[(int) $p['user_id']][$p['module']] = $p['access_level'];
        }
        foreach ($users as &$u) {
            $u['permissions'] = $byUser[(int) $u['id']] ?? [];
        }
        return $users;
    }

    /** @param array{name:string,email:?string,phone:?string,role:string,maid_ref:?string} $data */
    public static function create(array $data, string $password): int
    {
        self::db()->prepare(
            'INSERT INTO users (name, email, phone, password_hash, must_change_password, role, maid_ref)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            $data['name'],
            $data['email'],
            $data['phone'],
            password_hash($password, PASSWORD_DEFAULT),
            $data['role'],
            $data['role'] === 'maid' ? $data['maid_ref'] : null,
        ]);
        return (int) self::db()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        self::db()->prepare(
            'UPDATE users SET name = ?, email = ?, phone = ?, role = ?, maid_ref = ? WHERE id = ?'
        )->execute([
            $data['name'],
            $data['email'],
            $data['phone'],
            $data['role'],
            $data['role'] === 'maid' ? $data['maid_ref'] : null,
            $id,
        ]);
    }

    public static function setPassword(int $id, string $password, bool $mustChange): void
    {
        self::db()->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = ? WHERE id = ?'
        )->execute([password_hash($password, PASSWORD_DEFAULT), $mustChange ? 1 : 0, $id]);
    }

    public static function rehashIfNeeded(array $user, string $password): void
    {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            self::db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
    }

    public static function setActive(int $id, bool $active): void
    {
        self::db()->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    public static function touchLogin(int $id): void
    {
        self::db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    /**
     * Replaces the permission rows of a role='user' account.
     * @param array<string,string> $permissions module => 'view'|'edit'
     */
    public static function setPermissions(int $userId, array $permissions): void
    {
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM permissions WHERE user_id = ?')->execute([$userId]);
            $insert = $db->prepare('INSERT INTO permissions (user_id, module, access_level) VALUES (?, ?, ?)');
            foreach ($permissions as $module => $level) {
                if (in_array($module, Access::MODULES, true) && in_array($level, ['view', 'edit'], true)) {
                    $insert->execute([$userId, $module, $level]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function countActiveAdmins(): int
    {
        return (int) self::db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    }

    public static function maidRefTaken(string $maidRef, int $exceptId = 0): bool
    {
        $stmt = self::db()->prepare('SELECT COUNT(*) FROM users WHERE maid_ref = ? AND id <> ?');
        $stmt->execute([$maidRef, $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function identifierTaken(string $column, string $value, int $exceptId = 0): bool
    {
        if (!in_array($column, ['email', 'phone'], true)) {
            throw new \InvalidArgumentException($column);
        }
        $stmt = self::db()->prepare("SELECT COUNT(*) FROM users WHERE $column = ? AND id <> ?");
        $stmt->execute([$value, $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Readable temporary password: no 0/O/1/l/I confusion, ~57 bits. */
    public static function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $chunks = [];
        for ($c = 0; $c < 3; $c++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $chunks[] = $chunk;
        }
        return implode('-', $chunks);
    }

    private static function withPermissions(array $user): array
    {
        $stmt = self::db()->prepare('SELECT module, access_level FROM permissions WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $user['permissions'] = [];
        foreach ($stmt->fetchAll() as $p) {
            $user['permissions'][$p['module']] = $p['access_level'];
        }
        return $user;
    }
}
