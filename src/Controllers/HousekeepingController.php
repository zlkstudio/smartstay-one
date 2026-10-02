<?php
declare(strict_types=1);

namespace One\Controllers;

use One\Audit;
use One\Auth\Access;
use One\Auth\Auth;
use One\Housekeeping\Checklist;
use One\Housekeeping\ChecklistMailer;
use One\Housekeeping\CleaningRepository;
use One\Housekeeping\HousekeepingFeed;
use One\Http\Guard;
use RuntimeException;

/**
 * Housekeeping — port of the legacy app (index.php, intermediate.php, cleaning.php,
 * cleaning_form.php, checklist.js, send_email.php + endpoints).
 *
 * Maids: see and submit ONLY the apartments assigned to them today (filtered here,
 * by $user['maid_ref'] → config('maids')). Admin / manager / user-edit: allocation,
 * intermediate cleanings, and checklists on behalf of the assigned maid.
 */
final class HousekeepingController
{
    public const TABS = [
        'checkout'     => ['label' => 'Check-out',   'path' => '/housekeeping'],
        'intermediate' => ['label' => 'Intermediară', 'path' => '/housekeeping/intermediate'],
    ];

    public static function index(): never
    {
        $user = Guard::requireAccess('housekeeping', 'view');
        $today = date('Y-m-d');

        if (self::isMaid($user)) {
            $maid = self::maidName($user);
            $apartments = CleaningRepository::apartmentsOf($maid, $today);
            view('pages/housekeeping/maid', [
                'user'       => $user,
                'pageTitle'  => 'Curățenie',
                'active'     => 'housekeeping',
                'maid'       => $maid,
                'apartments' => $apartments,
                'counts'     => CleaningRepository::submissionCounts($apartments, $today),
                'styles'     => ['assets/css/modules.css'],
            ]);
        }

        view('pages/housekeeping/index', [
            'user'      => $user,
            'pageTitle' => 'Curățenie · Check-out',
            'active'    => 'housekeeping',
            'tab'       => 'checkout',
            'canEdit'   => Access::can($user, 'housekeeping', 'edit'),
            'maids'     => config('maids', []),
            'styles'    => ['assets/css/modules.css'],
            'scripts'   => ['assets/js/housekeeping.js'],
        ]);
    }

    public static function intermediate(): never
    {
        $user = self::requireStaff('view');
        view('pages/housekeeping/index', [
            'user'      => $user,
            'pageTitle' => 'Curățenie · Intermediară',
            'active'    => 'housekeeping',
            'tab'       => 'intermediate',
            'canEdit'   => Access::can($user, 'housekeeping', 'edit'),
            'maids'     => config('maids', []),
            'styles'    => ['assets/css/modules.css'],
            'scripts'   => ['assets/js/housekeeping.js'],
        ]);
    }

    /** GET /housekeeping/checklist/{apartment} — today's checklist for one apartment. */
    public static function checklist(array $params): never
    {
        $user = Guard::requireAccess('housekeeping', 'view');
        $apartment = (string) $params['apartment'];
        $today = date('Y-m-d');

        $maid = self::checklistOwner($user, $apartment, $today);
        $count = CleaningRepository::submissionCounts([$apartment], $today)[$apartment];

        view('pages/housekeeping/checklist', [
            'user'       => $user,
            'pageTitle'  => 'Checklist · Apt ' . $apartment,
            'active'     => 'housekeeping',
            'backHref'   => '/housekeeping',
            'apartment'  => $apartment,
            'maid'       => $maid,
            'date'       => $today,
            'count'      => $count,
            'locked'     => $count >= Checklist::MAX_SUBMISSIONS,
            'canSubmit'  => Access::can($user, 'housekeeping', 'edit'),
            'sections'   => Checklist::sectionsFor($apartment),
            'photoAreas' => Checklist::photoAreas($apartment),
            'csrf'       => Auth::csrfToken(),
            'styles'     => ['assets/css/modules.css'],
            'scripts'    => ['assets/js/checklist.js'],
        ]);
    }

    // ── API ───────────────────────────────────────────────────────────────

    /** GET /api/housekeeping/checkouts — today's check-outs + who has which apartment. */
    public static function checkouts(): never
    {
        self::requireStaff('view');
        $today = date('Y-m-d');
        try {
            $rows = HousekeepingFeed::checkouts($today);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 502);
        }
        $assignments = CleaningRepository::assignmentsForDate($today);
        $counts = CleaningRepository::submissionCounts(array_column($rows, 'apartment'), $today);
        foreach ($rows as &$row) {
            $row['assigned'] = array_values(array_unique(array_column($assignments[$row['apartment']] ?? [], 'maid')));
            $row['submissions'] = $counts[$row['apartment']] ?? 0;
        }
        unset($row);
        json_response(['ok' => true, 'date' => $today, 'checkouts' => $rows]);
    }

    /** POST /api/housekeeping/assign {maid: key, apartments: [..]} */
    public static function assign(): never
    {
        $user = self::requireStaff('edit');
        Guard::requireCsrf();
        $in = request_json();
        $maid = self::maidFromKey((string) ($in['maid'] ?? ''));
        $apartments = self::apartmentList($in['apartments'] ?? []);
        if (!$apartments) {
            json_response(['ok' => false, 'error' => 'Selectează cel puțin un apartament.'], 422);
        }
        CleaningRepository::assign($maid, $apartments, date('Y-m-d'));
        Audit::log((int) $user['id'], 'housekeeping.assign', 'maid', $maid, ['apartments' => $apartments]);
        json_response(['ok' => true, 'message' => count($apartments) === 1
            ? "Apartamentul {$apartments[0]} a fost alocat către $maid."
            : count($apartments) . " apartamente alocate către $maid."]);
    }

    /** POST /api/housekeeping/unassign {apartment} — removes today's pending allocation. */
    public static function unassign(): never
    {
        $user = self::requireStaff('edit');
        Guard::requireCsrf();
        $apartment = self::apartmentList([(string) (request_json()['apartment'] ?? '')])[0] ?? null;
        if ($apartment === null) {
            json_response(['ok' => false, 'error' => 'Apartament invalid.'], 422);
        }
        $removed = CleaningRepository::unassign($apartment, date('Y-m-d'));
        Audit::log((int) $user['id'], 'housekeeping.assign', 'apartment', $apartment, ['unassigned' => $removed]);
        json_response(['ok' => true, 'message' => $removed ? "Alocarea pentru $apartment a fost anulată." : 'Nu era nimic de anulat.']);
    }

    /** GET /api/housekeeping/active-guests — in-house guests + today's intermediate cleanings. */
    public static function activeGuests(): never
    {
        self::requireStaff('view');
        try {
            $rows = HousekeepingFeed::inHouse();
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 502);
        }
        json_response([
            'ok'    => true,
            'rate'  => Checklist::INTERMEDIATE_RATE,
            'guests' => $rows,
            'done'  => CleaningRepository::intermediatesOn(date('Y-m-d')),
        ]);
    }

    /** POST /api/housekeeping/intermediate {maid: key, apartment, reservationId} — 30 RON flat. */
    public static function createIntermediate(): never
    {
        $user = self::requireStaff('edit');
        Guard::requireCsrf();
        $in = request_json();
        $maid = self::maidFromKey((string) ($in['maid'] ?? ''));
        $apartment = self::apartmentList([(string) ($in['apartment'] ?? '')])[0] ?? null;
        $reservationId = preg_match('/^[A-Za-z0-9_-]{1,50}$/', (string) ($in['reservationId'] ?? ''))
            ? (string) $in['reservationId'] : null;
        if ($apartment === null) {
            json_response(['ok' => false, 'error' => 'Apartament invalid.'], 422);
        }
        CleaningRepository::recordCleaning($maid, $apartment, date('Y-m-d'), 'intermediate', $reservationId);
        Audit::log((int) $user['id'], 'housekeeping.intermediate', 'apartment', $apartment, ['maid' => $maid]);
        json_response([
            'ok'      => true,
            'maid'    => $maid,
            'message' => "Curățenie intermediară înregistrată pentru $apartment (" . Checklist::INTERMEDIATE_RATE . ' RON).',
        ]);
    }

    /**
     * POST /api/housekeeping/checklist (multipart) — apartment, sections[], photo1..3, requirements[].
     * Max 2 submissions per apartment + day; only the first is billed; e-mail with photos.
     */
    public static function submitChecklist(): never
    {
        $user = Guard::requireAccess('housekeeping', 'edit');
        Guard::requireCsrf();
        $today = date('Y-m-d');

        $apartment = self::apartmentList([(string) ($_POST['apartment'] ?? '')])[0] ?? null;
        if ($apartment === null) {
            json_response(['ok' => false, 'error' => 'Apartament invalid.'], 422);
        }
        $maid = self::checklistOwner($user, $apartment, $today);

        // Photos: 3 mandatory, validated by content (not by file name — iPhones send HEIC names).
        $requirements = json_decode((string) ($_POST['requirements'] ?? '[]'), true);
        $requirements = is_array($requirements) ? array_values($requirements) : [];
        $photos = [];
        for ($i = 1; $i <= Checklist::PHOTOS; $i++) {
            $file = $_FILES["photo$i"] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                json_response(['ok' => false, 'error' => "Fotografia $i lipsește sau nu s-a încărcat. Reîncearcă."], 422);
            }
            if ($file['size'] > 10 * 1024 * 1024) {
                json_response(['ok' => false, 'error' => "Fotografia $i e prea mare (max. 10 MB)."], 422);
            }
            $info = @getimagesize($file['tmp_name']);
            if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                json_response(['ok' => false, 'error' => "Fotografia $i nu este o imagine validă."], 422);
            }
            $requirement = mb_substr(trim((string) ($requirements[$i - 1] ?? "Foto $i")), 0, 80);
            $slug = preg_replace('/[^a-zA-Z0-9_-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $requirement) ?: "Photo$i");
            $ext = image_type_to_extension($info[2], false) ?: 'jpg';
            $photos[] = [
                'path'        => $file['tmp_name'],
                'filename'    => "Apartment_{$apartment}_{$slug}.$ext",
                'requirement' => $requirement,
                'size'        => (int) $file['size'],
            ];
        }

        // Checked items, rebuilt from OUR checklist definition (labels never come from the browser).
        $checked = json_decode((string) ($_POST['checked'] ?? '[]'), true);
        $checked = is_array($checked) ? array_flip(array_map('strval', $checked)) : [];
        $report = [];
        foreach (Checklist::sectionsFor($apartment) as $key => [$title, $items]) {
            $report[$key] = ['title' => $title, 'items' => []];
            foreach ($items as $id => $label) {
                $report[$key]['items'][$label] = isset($checked[$id]);
            }
        }

        try {
            $number = CleaningRepository::addSubmission($maid, $apartment, $today, null, self::isMaid($user) ? $maid : $user['name']);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 409) {
                json_response(['ok' => false, 'code' => 'limit_reached',
                    'error' => "Checklist-ul pentru apartamentul $apartment a fost deja finalizat."], 409);
            }
            throw $e;
        }

        // Only the first pass is paid; the second is a verification pass.
        if ($number === 1) {
            CleaningRepository::recordCleaning($maid, $apartment, $today, 'checkout');
            CleaningRepository::markAssignmentCompleted($maid, $apartment, $today);
        }

        $mailed = ChecklistMailer::send($apartment, $maid, $today, $number, $report, $photos, $user['name']);
        Audit::log((int) $user['id'], 'housekeeping.checklist', 'apartment', $apartment, [
            'maid' => $maid, 'submission' => $number, 'mailed' => $mailed,
        ]);

        json_response([
            'ok'      => true,
            'mailed'  => $mailed,
            'pending' => CleaningRepository::pendingOf($maid, $today),
            'message' => $mailed
                ? "Checklist-ul și fotografiile au fost trimise pentru apartamentul $apartment!"
                : "Checklist-ul pentru $apartment a fost salvat, dar e-mailul nu a plecat. Anunță administratorul.",
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private static function isMaid(array $user): bool
    {
        return $user['role'] === 'maid';
    }

    /** Maid display name as stored in smartconcept_cleaning ("Ioana"). */
    private static function maidName(array $user): string
    {
        $maids = config('maids', []);
        $ref = (string) ($user['maid_ref'] ?? '');
        if ($ref === '' || !isset($maids[$ref])) {
            error_log("[ONE] maid user {$user['id']} has unknown maid_ref '$ref'");
            Guard::forbidden($user);
        }
        return (string) $maids[$ref];
    }

    private static function maidFromKey(string $key): string
    {
        $maids = config('maids', []);
        if (!isset($maids[$key])) {
            json_response(['ok' => false, 'error' => 'Alege menajera din listă.'], 422);
        }
        return (string) $maids[$key];
    }

    /** Housekeeping staff screens (allocation, intermediates): never for maids. */
    private static function requireStaff(string $level): array
    {
        $user = Guard::requireAccess('housekeeping', $level);
        if (self::isMaid($user)) {
            Guard::forbidden($user);
        }
        return $user;
    }

    /**
     * Whose checklist this is. A maid: only apartments assigned to her today (else 403).
     * Staff: the maid assigned to the apartment today (no assignment → nobody to pay → 403).
     */
    private static function checklistOwner(array $user, string $apartment, string $date): string
    {
        if (self::isMaid($user)) {
            $maid = self::maidName($user);
            if (!in_array($apartment, CleaningRepository::apartmentsOf($maid, $date), true)) {
                Guard::forbidden($user);
            }
            return $maid;
        }
        $maid = CleaningRepository::maidFor($apartment, $date);
        if ($maid === null) {
            if (Guard::isApi()) {
                json_response(['ok' => false, 'error' => "Apartamentul $apartment nu e alocat nimănui azi."], 422);
            }
            redirect('/housekeeping?unassigned=' . rawurlencode($apartment));
        }
        return $maid;
    }

    /** @return list<string> digits-only apartment numbers */
    private static function apartmentList(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $value) {
            $value = trim((string) $value);
            if (preg_match('/^\d{1,10}$/', $value)) {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }
}
