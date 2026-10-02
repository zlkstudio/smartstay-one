<?php
declare(strict_types=1);

namespace One\Controllers;

use One\Auth\Access;
use One\Http\Guard;
use One\Integrations\Nuki;
use One\Inventory\InventoryRepository;
use One\System\HealthCheck;
use Throwable;

final class PageController
{
    public static function home(): never
    {
        $user = Guard::requireLogin();
        if ($user['role'] === 'maid') {
            redirect('/housekeeping');
        }

        $modules = array_values(array_filter(
            Access::MODULES,
            static fn(string $m): bool => Access::can($user, $m)
        ));

        // Inventory-only users get the critical-stock line server-side (one fast query);
        // users with Rapoarte get the full indicators from /api/reports/today (home.js).
        $canReports = Access::can($user, 'reports');
        $critical = null;
        if (!$canReports && Access::can($user, 'inventory')) {
            try {
                $critical = InventoryRepository::critical();
            } catch (Throwable $e) {
                error_log('[ONE] home critical stock: ' . $e->getMessage());
            }
        }

        view('pages/home', [
            'user'       => $user,
            'pageTitle'  => 'Acasă',
            'active'     => 'home',
            'modules'    => $modules,
            'canReports' => $canReports,
            'canInventory' => Access::can($user, 'inventory'),
            'critical'   => $critical,
            'styles'     => ['assets/css/modules.css'],
            'scripts'    => $canReports ? ['assets/js/home.js'] : [],
        ]);
    }

    public static function settings(): never
    {
        $user = Guard::requireAccess('settings', 'edit');
        view('pages/settings', [
            'user'      => $user,
            'pageTitle' => 'Setări',
            'active'    => 'settings',
            'backHref'  => '/account',
            'report'    => HealthCheck::full(),
            'nukiLocks' => Nuki::isConfigured() ? Nuki::smartlocks() : [],
            // Live check against the Nuki API only on request (≈2 calls per lock).
            'nukiCheck' => isset($_GET['nuki']) && Nuki::isConfigured() ? self::nukiCheck() : null,
        ]);
    }

    /** @return list<array> */
    private static function nukiCheck(): array
    {
        $out = [];
        foreach (array_keys(Nuki::smartlocks()) as $apartment) {
            try {
                $out[] = Nuki::inspect((string) $apartment);
            } catch (Throwable $e) {
                $out[] = ['apartment' => (string) $apartment, 'lock' => '', 'ok' => false, 'name' => null,
                    'online' => null, 'keypad' => null, 'codes' => null, 'problem' => $e->getMessage()];
            }
        }
        return $out;
    }

    public static function install(): never
    {
        view('pages/install', ['pageTitle' => 'Instalează SmartStay ONE', 'installGate' => false], 'layout-bare');
    }
}
