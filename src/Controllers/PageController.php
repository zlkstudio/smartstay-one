<?php
declare(strict_types=1);

namespace One\Controllers;

use DateTimeImmutable;
use One\Auth\Access;
use One\Housekeeping\CleaningRepository;
use One\Http\Guard;
use One\Integrations\Nuki;
use One\Inventory\InventoryRepository;
use One\Reports\MaidPayments;
use One\Stays;
use One\System\HealthCheck;
use Throwable;

final class PageController
{
    public static function home(): never
    {
        $user = Guard::requireLogin();
        if (Access::isMaid($user)) {
            self::maidHome($user);
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

    /**
     * Acasă pentru Menajeră: curățeniile ei de azi, ce e liber de preluat, check-out-urile de mâine
     * (cu sosirile din aceeași zi = prioritate), totalul săptămânii și lenjeriile pe roșu.
     * Fără nume de oaspeți. Previo vine din Stays (cache 5 min), restul din baze — un Previo căzut nu strică pagina.
     */
    private static function maidHome(array $user): never
    {
        $maid = Access::maidName($user);
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $mine = [];
        $taken = [];
        $error = null;
        try {
            foreach (CleaningRepository::assignmentsForDate($today) as $apt => $rows) {
                $taken[$apt] = true;
                $maids = array_column($rows, 'maid');
                if ($maid !== null && in_array($maid, $maids, true)) {
                    $done = in_array('completed', array_column($rows, 'status'), true);
                    $mine[(string) $apt] = ['apartment' => (string) $apt, 'done' => $done];
                }
            }
            if ($mine) {
                foreach (CleaningRepository::submissionCounts(array_keys($mine), $today) as $apt => $n) {
                    if ($n > 0 && isset($mine[$apt])) {
                        $mine[$apt]['done'] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[ONE] maid home assignments: ' . $e->getMessage());
            $error = 'Baza Housekeeping nu răspunde. Încearcă din nou.';
        }

        $todayStatus = $tomorrowStatus = null;
        try {
            $stays = Stays::window();
            $todayStatus = Stays::dayStatus($stays, $today);
            $tomorrowStatus = Stays::dayStatus($stays, $tomorrow);
        } catch (Throwable $e) {
            error_log('[ONE] maid home stays: ' . $e->getMessage());
        }

        // Ale ei: ora de check-out și sosirea din aceeași zi (dacă e).
        foreach ($mine as $apt => &$row) {
            $st = $todayStatus[$apt] ?? null;
            $row['out'] = $st['checkOut']['checkOutTime'] ?? null;
            $row['in'] = $st['checkIn']['checkInTime'] ?? null;
        }
        unset($row);
        $mine = array_values($mine);
        usort($mine, static fn(array $a, array $b): int => [$a['done'], $a['apartment']] <=> [$b['done'], $b['apartment']]);

        $free = [];
        $tomorrowList = [];
        if ($todayStatus !== null) {
            foreach ($todayStatus as $apt => $st) {
                if ($st['checkOut'] && !isset($taken[$apt])) {
                    $free[] = (string) $apt;
                }
            }
            natsort($free);
            foreach ($tomorrowStatus as $apt => $st) {
                if ($st['checkOut']) {
                    $tomorrowList[] = [
                        'apartment' => (string) $apt,
                        'out'       => $st['checkOut']['checkOutTime'],
                        'in'        => $st['checkIn']['checkInTime'] ?? null,
                    ];
                }
            }
            usort($tomorrowList, static fn(array $a, array $b): int => strnatcmp($a['apartment'], $b['apartment']));
        }

        $week = null;
        if ($maid !== null) {
            $monday = new DateTimeImmutable('monday this week');
            try {
                $w = MaidPayments::build($monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d'), $maid);
                $week = ['count' => $w['count'], 'total' => $w['total']];
            } catch (Throwable $e) {
                error_log('[ONE] maid home week: ' . $e->getMessage());
            }
        }

        $critical = null;
        try {
            $critical = InventoryRepository::critical();
        } catch (Throwable $e) {
            error_log('[ONE] home critical stock: ' . $e->getMessage());
        }

        view('pages/home-maid', [
            'user'      => $user,
            'pageTitle' => 'Acasă',
            'active'    => 'home',
            'mine'      => $mine,
            'free'      => array_values($free),
            'tomorrow'  => $tomorrowList,
            'previoOk'  => $todayStatus !== null,
            'week'      => $week,
            'critical'  => $critical,
            'error'     => $maid === null ? 'Contul tău nu e legat de o menajeră din listă. Anunță administratorul.' : $error,
            'styles'    => ['assets/css/modules.css'],
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
