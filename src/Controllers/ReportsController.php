<?php
declare(strict_types=1);

namespace One\Controllers;

use DateTimeImmutable;
use One\Audit;
use One\Auth\Access;
use One\Housekeeping\CleaningRepository;
use One\Http\Guard;
use One\Inventory\InventoryRepository;
use One\Reports\MaidPayments;
use One\Reports\OperationsReport;
use RuntimeException;
use Throwable;

/**
 * Rapoarte — operations overview (Previo, cached in report_cache) and the maid payment report
 * (port of housekeeping/public/admin/weekly_payment_report.php + add/delete_cleaning.php).
 */
final class ReportsController
{
    public const TABS = [
        'overview' => ['label' => 'Prezentare', 'path' => '/reports'],
        'payments' => ['label' => 'Plata menajerelor', 'path' => '/reports/payments'],
    ];

    public const PRESETS = [
        'prev_week'  => 'Săptămâna trecută',
        'this_week'  => 'Săptămâna asta',
        'prev_month' => 'Luna trecută',
        'this_month' => 'Luna asta',
    ];

    private const MAX_RANGE_DAYS = 93;

    public static function overview(): never
    {
        $user = Guard::requireAccess('reports', 'view');
        $report = null;
        $error = null;
        try {
            $report = OperationsReport::cached(3600);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        view('pages/reports/overview', [
            'user'      => $user,
            'pageTitle' => 'Rapoarte',
            'active'    => 'reports',
            'tab'       => 'overview',
            'report'    => $report,
            'error'     => $error,
            'canEdit'   => Access::can($user, 'reports', 'edit'),
            'styles'    => ['assets/css/modules.css'],
            'scripts'   => ['assets/js/reports.js'],
        ]);
    }

    public static function payments(): never
    {
        $user = Guard::requireAccess('reports', 'view');
        [$from, $to, $preset] = self::period();
        $data = null;
        $error = null;
        try {
            $data = MaidPayments::build($from, $to);
        } catch (Throwable $e) {
            error_log('[ONE] payments report: ' . $e->getMessage());
            $error = 'Baza Housekeeping nu răspunde. Încearcă din nou.';
        }
        view('pages/reports/payments', [
            'user'      => $user,
            'pageTitle' => 'Plata menajerelor',
            'active'    => 'reports',
            'tab'       => 'payments',
            'from'      => $from,
            'to'        => $to,
            'preset'    => $preset,
            'data'      => $data,
            'error'     => $error,
            'maids'     => config('maids', []),
            'canEdit'   => Access::can($user, 'reports', 'edit'),
            'styles'    => ['assets/css/modules.css'],
            'scripts'   => ['assets/js/reports.js'],
        ]);
    }

    // ── API ───────────────────────────────────────────────────────────────

    /** GET /api/reports/today — Home indicators. Never older than 15 minutes. */
    public static function today(): never
    {
        $user = Guard::requireAccess('reports', 'view');
        try {
            $report = OperationsReport::cached(900);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 502);
        }
        $out = [
            'ok'         => true,
            'total'      => $report['roster']['total'],
            'today'      => $report['today'],
            'avgPast'    => $report['occupancy']['avgPast'],
            'computedAt' => local_time($report['computedAt'], 'H:i'),
        ];
        if (Access::can($user, 'inventory')) {
            try {
                $out['critical'] = InventoryRepository::critical();
            } catch (Throwable $e) {
                error_log('[ONE] home critical stock: ' . $e->getMessage());
            }
        }
        json_response($out);
    }

    /** POST /api/reports/refresh — recompute now (fresh Previo read). */
    public static function refresh(): never
    {
        $user = Guard::requireAccess('reports', 'edit');
        Guard::requireCsrf();
        try {
            OperationsReport::cached(0, true);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 502);
        }
        Audit::log((int) $user['id'], 'report.refresh', 'report', OperationsReport::KEY);
        json_response(['ok' => true, 'message' => 'Rapoartele au fost recalculate.']);
    }

    /** POST /api/reports/cleaning {maid: key, apartment, date, type} — manual payment line. */
    public static function addCleaning(): never
    {
        $user = Guard::requireAccess('reports', 'edit');
        Guard::requireCsrf();
        $in = request_json();

        $maids = config('maids', []);
        $maid = $maids[(string) ($in['maid'] ?? '')] ?? null;
        $apartment = trim((string) ($in['apartment'] ?? ''));
        $date = self::date((string) ($in['date'] ?? ''));
        $type = (string) ($in['type'] ?? 'checkout');

        if ($maid === null) {
            json_response(['ok' => false, 'error' => 'Alege menajera din listă.'], 422);
        }
        if (!preg_match('/^\d{1,10}$/', $apartment)) {
            json_response(['ok' => false, 'error' => 'Numărul apartamentului e invalid.'], 422);
        }
        if ($date === null || $date > date('Y-m-d')) {
            json_response(['ok' => false, 'error' => 'Data e invalidă (nu poate fi în viitor).'], 422);
        }
        if (!in_array($type, ['checkout', 'intermediate'], true)) {
            json_response(['ok' => false, 'error' => 'Tip de curățenie invalid.'], 422);
        }

        $created = CleaningRepository::recordCleaning((string) $maid, $apartment, $date, $type);
        if (!$created) {
            json_response(['ok' => false, 'error' => "Există deja o curățenie $apartment pentru $maid în acea zi."], 409);
        }
        Audit::log((int) $user['id'], 'report.cleaning_add', 'cleaning', $apartment, [
            'maid' => $maid, 'date' => $date, 'type' => $type,
        ]);
        json_response(['ok' => true, 'message' => "Curățenie adăugată: $apartment · $maid."]);
    }

    /** POST /api/reports/cleaning/delete {id} */
    public static function deleteCleaning(): never
    {
        $user = Guard::requireAccess('reports', 'edit');
        Guard::requireCsrf();
        $id = (int) (request_json()['id'] ?? 0);
        $record = $id > 0 ? CleaningRepository::findRecord($id) : null;
        if ($record === null) {
            json_response(['ok' => false, 'error' => 'Înregistrarea nu mai există.'], 404);
        }
        CleaningRepository::deleteRecord($id);
        Audit::log((int) $user['id'], 'report.cleaning_delete', 'cleaning', (string) $record['apartment_number'], [
            'id'   => $id,
            'maid' => $record['maid_name'],
            'date' => $record['cleaning_date'],
            'type' => $record['cleaning_type'],
        ]);
        json_response(['ok' => true, 'message' => 'Curățenia a fost ștearsă din raport.']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Report period from ?preset= or ?from=&to=. Default: last week, Monday–Sunday.
     * @return array{0:string, 1:string, 2:?string} [from, to, preset|null]
     */
    private static function period(): array
    {
        $from = self::date((string) ($_GET['from'] ?? ''));
        $to = self::date((string) ($_GET['to'] ?? ''));
        if ($from !== null && $to !== null) {
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            $max = (new DateTimeImmutable($from))->modify('+' . (self::MAX_RANGE_DAYS - 1) . ' days')->format('Y-m-d');
            return [$from, min($to, $max), null];
        }

        $preset = (string) ($_GET['preset'] ?? 'prev_week');
        $preset = isset(self::PRESETS[$preset]) ? $preset : 'prev_week';
        $monday = new DateTimeImmutable('monday this week');
        $firstOfMonth = new DateTimeImmutable('first day of this month');
        [$a, $b] = match ($preset) {
            'this_week'  => [$monday, $monday->modify('+6 days')],
            'prev_month' => [$firstOfMonth->modify('-1 month'), $firstOfMonth->modify('-1 day')],
            'this_month' => [$firstOfMonth, $firstOfMonth->modify('last day of this month')],
            default      => [$monday->modify('-7 days'), $monday->modify('-1 day')],
        };
        return [$a->format('Y-m-d'), $b->format('Y-m-d'), $preset];
    }

    private static function date(string $value): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
