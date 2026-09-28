<?php
declare(strict_types=1);

namespace One\Controllers;

use One\Auth\Access;
use One\Http\Guard;
use One\System\HealthCheck;

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

        view('pages/home', [
            'user'      => $user,
            'pageTitle' => 'Acasă',
            'active'    => 'home',
            'modules'   => $modules,
            'health'    => $user['role'] === 'admin' ? HealthCheck::summary() : null,
        ]);
    }

    /** Stage 1: module shells. Access is enforced exactly as it will be in Stage 2. */
    public static function module(string $module): never
    {
        $user = Guard::requireAccess($module, 'view');
        view('pages/module', [
            'user'      => $user,
            'pageTitle' => Access::LABELS[$module],
            'active'    => $module,
            'module'    => $module,
            'level'     => Access::level($user, $module),
            'legacyUrl' => $user['role'] === 'maid' ? null : (config('legacy_urls', [])[$module] ?? null),
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
        ]);
    }

    public static function install(): never
    {
        view('pages/install', ['pageTitle' => 'Instalează SmartStay ONE', 'installGate' => false], 'layout-bare');
    }
}
