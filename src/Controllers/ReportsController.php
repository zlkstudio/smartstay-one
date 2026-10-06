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
use One\Properties;
use One\Reports\Analytics;
use One\Reports\OperationsReport;
use One\Reports\Period;
use One\Stays;
use One\Users\UserRepository;
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

    /**
     * Shell only: tabs, period bar and a skeleton, sent at once so the tab opens instantly.
     * The numbers (Previo + Analytics, the slow part) come from /reports/body once the page is up.
     */
    public static function overview(): never
    {
        $user = Guard::requireAccess('reports', 'view');
        if (Access::isMaid($user)) {   // Menajera vede doar curățeniile ei, nu ocuparea/veniturile.
            redirect('/reports/payments');
        }
        $period = Period::fromQuery($_GET);
        view('pages/reports/overview', [
            'user'      => $user,
            'pageTitle' => 'Rapoarte',
            'active'    => 'reports',
            'tab'       => 'overview',
            'report'    => null,
            'period'    => $period,
            'page'      => null,
            'error'     => null,
            'deferred'  => true,
            'cacheable' => true,
            'canEdit'   => Access::can($user, 'reports', 'edit'),
            'styles'    => ['assets/css/modules.css'],
            'scripts'   => ['assets/js/reports.js'],
        ]);
    }

    /** GET /reports/body?p=… — the computed overview as an HTML fragment (no layout), for reports.js. */
    public static function overviewBody(): never
    {
        $user = Guard::requireAccess('reports', 'view');
        if (Access::isMaid($user)) {
            http_response_code(403);
            exit;
        }
        $period = Period::fromQuery($_GET);
        $report = null;
        $error = null;
        $page = null;
        try {
            $report = OperationsReport::cached(3600);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        if ($report !== null) {
            try {
                $page = self::overviewData($period, $report);
            } catch (RuntimeException $e) {
                $error = 'Istoricul Previo nu s-a putut încărca: ' . $e->getMessage();
            }
        }
        header('Cache-Control: no-store');
        view('pages/reports/overview', [
            'user'     => $user,
            'tab'      => 'overview',
            'report'   => $report,
            'period'   => $period,
            'page'     => $page,
            'error'    => $error,
            'deferred' => false,
            'canEdit'  => Access::can($user, 'reports', 'edit'),
        ], null);
    }

    /**
     * Every section of Prezentare for one period, all computed by Analytics on the same stays.
     * "Azi" keeps the operational defaults: channels and movement over the last 30 days,
     * occupancy chart 30 nights back + 14 ahead.
     */
    private static function overviewData(Period $period, array $report): array
    {
        $today = date('Y-m-d');
        $roster = $report['roster']['apartments'] ?? [];
        $stays = Stays::between($period->earliest(), $period->latest());

        $a = new Analytics($stays, $roster, substr($period->earliest(), 0, 4) . '-01-01', OperationsReport::vatRate());
        $last30 = [date('Y-m-d', strtotime('-29 days')), $today];
        $wide = $period->isToday() ? $last30 : [$period->from, $period->to];

        $kpis = $a->kpis($period->from, $period->to);
        $compare = null;
        if ($period->compareFrom !== null) {
            $c = $a->kpis($period->compareFrom, (string) $period->compareTo);
            $compare = $c['covered'] && ($c['occupied'] > 0 || $c['revenue'] > 0) ? $c : null;
        }
        $mtd = $period->key === 'this_month' && $today >= $period->from && $today < $period->to
            ? $a->kpis($period->from, $today) : null;

        $chart = $period->isToday()
            ? [date('Y-m-d', strtotime('-' . (OperationsReport::PAST_DAYS - 1) . ' days')), date('Y-m-d', strtotime('+' . OperationsReport::NEXT_DAYS . ' days'))]
            : [$period->from, $period->to];
        $series = $a->series($chart[0], $chart[1]);

        $apartments = $a->apartments($period->from, $period->to);
        $details = [];
        foreach ($a->activeApartments() as $apt) {
            $details[$apt] = $a->apartmentDetail($apt, $today);
        }

        return [
            'kpis'       => $kpis,
            'compare'    => $compare,
            'mtd'        => $mtd,
            'chart'      => ['from' => $chart[0], 'to' => $chart[1], 'bars' => self::bucket($series)],
            'ahead'      => $period->isToday() ? $a->kpis(date('Y-m-d', strtotime('+1 day')), $chart[1]) : null,
            'wide'       => ['from' => $wide[0], 'to' => $wide[1], 'label' => $period->isToday() ? 'ultimele 30 de zile' : $period->label],
            'channels'   => $a->channels($wide[0], $wide[1]),
            'apartments' => $apartments,
            'details'    => $details,
            'monthly'    => $a->monthly((int) date('Y'), $today),
            'weekdays'   => $a->weekdays($wide[0], $wide[1]),
            'stayLength' => $a->lengthOfStay($wide[0], $wide[1]),
            'guests'     => $a->guests($period->from, $period->to),
            'vatRemoved' => $a->vatRemoved(),
            'today'      => $today,
        ];
    }

    /**
     * Chart bars: one per night up to 62 nights, otherwise one per week (Monday-based),
     * so a whole year stays readable on a phone.
     * @return list<array{from:string, to:string, occupied:int, total:int, pct:float, revenue:float, adr:?float, revpar:?float}>
     */
    private static function bucket(array $series): array
    {
        $daily = count($series) <= 62;
        $out = [];
        foreach ($series as $d) {
            $key = $daily ? $d['date'] : date('o-W', strtotime($d['date']));
            $b = &$out[$key];
            $b ??= ['from' => $d['date'], 'to' => $d['date'], 'occupied' => 0, 'total' => 0, 'revenue' => 0.0];
            $b['to'] = $d['date'];
            $b['occupied'] += $d['occupied'];
            $b['total'] += $d['total'];
            $b['revenue'] += $d['revenue'];
            unset($b);
        }
        return array_values(array_map(static fn(array $b): array => $b + [
            'pct'    => $b['total'] ? $b['occupied'] * 100 / $b['total'] : 0.0,
            'adr'    => $b['occupied'] ? $b['revenue'] / $b['occupied'] : null,
            'revpar' => $b['total'] ? $b['revenue'] / $b['total'] : null,
        ], $out));
    }

    public static function payments(): never
    {
        $user = Guard::requireAccess('reports', 'view');
        $ownMaid = Access::isMaid($user) ? self::ownMaidName($user) : null;
        [$from, $to, $preset] = self::period();
        $data = null;
        $error = null;
        try {
            $data = MaidPayments::build($from, $to, $ownMaid);
        } catch (Throwable $e) {
            error_log('[ONE] payments report: ' . $e->getMessage());
            $error = 'Baza Housekeeping nu răspunde. Încearcă din nou.';
        }
        if ($ownMaid !== null && $data !== null) {
            // Intern, niciodată către menajeră: „Checklist x2" și „tarif implicit".
            $data['unknown'] = [];
            foreach ($data['maids'] as &$m) {
                foreach ($m['lines'] as &$l) {
                    $l['double'] = false;
                    $l['known'] = true;
                }
                unset($l);
            }
            unset($m);
        }
        $phones = [];
        try {
            $phones = $ownMaid === null ? UserRepository::maidPhones() : [];
        } catch (Throwable $e) {
            error_log('[ONE] maid phones: ' . $e->getMessage());
        }
        // Fallback: config('maid_phones') => ['ioana' => '0784…'] for maids without an ONE account.
        foreach ((array) config('maid_phones', []) as $key => $phone) {
            $phones[(string) $key] ??= (string) $phone;
        }
        $phones = array_map(static fn(string $p): string => Properties::whatsappPhone($p), $phones);

        view('pages/reports/payments', [
            'user'      => $user,
            'pageTitle' => $ownMaid !== null ? 'Curățeniile mele' : 'Plata menajerelor',
            'ownMaid'   => $ownMaid,
            'active'    => 'reports',
            'tab'       => 'payments',
            'from'      => $from,
            'to'        => $to,
            'preset'    => $preset,
            'data'      => $data,
            'error'     => $error,
            'maids'     => config('maids', []),
            'phones'    => $phones,
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
        if (Access::isMaid($user)) {
            Guard::forbidden($user);
        }
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
            Stays::year((int) date('Y'), true);
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

        // again = confirmed in the UI: several check-outs in the same apartment on the same day.
        $again = !empty($in['again']);
        $created = CleaningRepository::recordCleaning((string) $maid, $apartment, $date, $type, null, $again);
        if (!$created) {
            json_response(['ok' => false, 'code' => 'duplicate',
                'error' => "Există deja o curățenie $apartment pentru $maid în acea zi."], 409);
        }
        Audit::log((int) $user['id'], 'report.cleaning_add', 'cleaning', $apartment, [
            'maid' => $maid, 'date' => $date, 'type' => $type, 'again' => $again,
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

    /** Numele menajerei din cont, așa cum e scris în cleaning_records ("Ioana"). */
    private static function ownMaidName(array $user): string
    {
        $name = Access::maidName($user);
        if ($name === null) {
            error_log("[ONE] maid user {$user['id']} has unknown maid_ref '" . ($user['maid_ref'] ?? '') . "'");
            Guard::forbidden($user);
        }
        return $name;
    }

    private static function date(string $value): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
