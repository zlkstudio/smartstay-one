<?php
declare(strict_types=1);

namespace One\Auth;

/**
 * Role → module access matrix. The single place that decides who sees what.
 * Checked server-side on every page and API call; the navigation only mirrors it.
 */
final class Access
{
    public const MODULES = ['reservations', 'housekeeping', 'inventory', 'reports'];
    public const ADMIN_MODULES = ['users', 'settings'];

    public const LABELS = [
        'reservations' => 'Rezervări',
        'housekeeping' => 'Housekeeping',
        'inventory'    => 'Inventar',
        'reports'      => 'Rapoarte',
        'users'        => 'Utilizatori',
        'settings'     => 'Setări',
    ];

    public const ROLE_LABELS = [
        'admin'   => 'Admin',
        'manager' => 'Manager',
        'maid'    => 'Menajeră',
        'user'    => 'Utilizator',
    ];

    /**
     * Menajeră: Curățenie + Inventar (edit), Rezervări (doar citire), Rapoarte (doar citire, și acolo
     * doar propriile curățenii — ReportsController filtrează după maid_ref).
     */
    private const MAID = [
        'housekeeping' => 'edit',
        'inventory'    => 'edit',
        'reservations' => 'view',
        'reports'      => 'view',
    ];

    /**
     * @param array{role:string, permissions?:array<string,string>} $user
     * @return 'edit'|'view'|null
     */
    public static function level(array $user, string $module): ?string
    {
        return match ($user['role']) {
            'admin'   => 'edit',
            'manager' => in_array($module, self::MODULES, true) ? 'edit' : null,
            'maid'    => self::MAID[$module] ?? null,
            'user'    => in_array($module, self::MODULES, true) ? ($user['permissions'][$module] ?? null) : null,
            default   => null,
        };
    }

    public static function can(array $user, string $module, string $needed = 'view'): bool
    {
        $level = self::level($user, $module);
        if ($level === null) {
            return false;
        }
        return $needed === 'view' || $level === 'edit';
    }

    /** Modules this user may open, in navigation order. @return list<string> */
    public static function visibleModules(array $user): array
    {
        return array_values(array_filter(
            [...self::MODULES, ...self::ADMIN_MODULES],
            static fn(string $m): bool => self::can($user, $m)
        ));
    }

    public static function isMaid(array $user): bool
    {
        return ($user['role'] ?? '') === 'maid';
    }

    /** Numele menajerei din cont, exact ca în cleaning_records ("Ioana"); null dacă maid_ref nu e în config. */
    public static function maidName(array $user): ?string
    {
        $maids = config('maids', []);
        $ref = (string) ($user['maid_ref'] ?? '');
        return $ref !== '' && isset($maids[$ref]) ? (string) $maids[$ref] : null;
    }

    /** Where a user lands after login (menajera are și ea Acasă, cu propriul overview). */
    public static function homePath(array $user): string
    {
        return '/';
    }
}
