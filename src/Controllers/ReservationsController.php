<?php
declare(strict_types=1);

namespace One\Controllers;

use One\Audit;
use One\Auth\Access;
use One\Http\Guard;
use One\Integrations\GuestAppSync;
use One\Integrations\Nuki;
use One\Integrations\Previo;
use One\Reservations\OutreachRepository;
use One\Reservations\ReservationFeed;
use One\Reservations\StatusRepository;
use RuntimeException;

/**
 * Rezervări — port of the legacy Reservations app (today.php, tomorrow.php,
 * whatsapp.php, generate-link.php + api/*). Pages are shells; data comes from /api/reservations/*.
 */
final class ReservationsController
{
    public const TABS = [
        'today'    => ['label' => 'Astăzi',   'path' => '/reservations'],
        'tomorrow' => ['label' => 'Mâine',    'path' => '/reservations/tomorrow'],
        'whatsapp' => ['label' => 'WhatsApp', 'path' => '/reservations/whatsapp'],
        'link'     => ['label' => 'Link',     'path' => '/reservations/link'],
    ];

    public static function page(string $tab): never
    {
        $user = Guard::requireAccess('reservations', 'view');
        view('pages/reservations/index', [
            'user'      => $user,
            'pageTitle' => 'Rezervări · ' . self::TABS[$tab]['label'],
            'active'    => 'reservations',
            'tab'       => $tab,
            'canEdit'   => Access::can($user, 'reservations', 'edit'),
            'date'      => $tab === 'tomorrow' ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'),
            'styles'    => ['assets/css/modules.css'],
            'scripts'   => ['assets/js/reservations.js'],
        ]);
    }

    // ── API ───────────────────────────────────────────────────────────────

    /** GET /api/reservations/list?day=today|tomorrow */
    public static function list(): never
    {
        $user = Guard::requireAccess('reservations', 'view');
        $day = ($_GET['day'] ?? 'today') === 'tomorrow' ? 'tomorrow' : 'today';
        $date = $day === 'tomorrow' ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');

        $rows = self::previo(static fn(): array => ReservationFeed::checkins($date));
        $statuses = (new StatusRepository($user['name']))->getMany(array_column($rows, 'id'));

        json_response(['ok' => true, 'date' => $date, 'reservations' => $rows, 'statuses' => $statuses]);
    }

    /** POST /api/reservations/status {id, field: city_tax_paid|checkin_completed|notes, value} */
    public static function status(): never
    {
        $user = Guard::requireAccess('reservations', 'edit');
        Guard::requireCsrf();

        $in = request_json();
        $id = trim((string) ($in['id'] ?? ''));
        $field = (string) ($in['field'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $id)) {
            json_response(['ok' => false, 'error' => 'Rezervare invalidă.'], 422);
        }

        $repo = new StatusRepository($user['name']);
        $synced = null;

        if (in_array($field, StatusRepository::FLAGS, true)) {
            $value = (bool) ($in['value'] ?? false);
            $status = $repo->setFlag($id, $field, $value);

            // Same rule as the legacy update_status.php:
            //  checkin_completed → mark/unmark in Guest App (unlocks / re-locks the access code)
            //  city_tax_paid     → resync only when the check-in is already complete
            if ($field === 'checkin_completed') {
                $synced = GuestAppSync::checkin($id, $value, $status['city_tax_paid'], $user['name']);
            } elseif ($status['checkin_completed']) {
                $synced = GuestAppSync::checkin($id, true, $value, $user['name']);
            }
            Audit::log((int) $user['id'], 'reservation.status', 'reservation', $id, [$field => $value]);
        } elseif ($field === 'notes') {
            $notes = $in['value'] === null ? null : trim((string) $in['value']);
            if ($notes !== null && mb_strlen($notes) > 5000) {
                json_response(['ok' => false, 'error' => 'Nota e prea lungă (max. 5000 caractere).'], 422);
            }
            $status = $repo->setNotes($id, $notes === '' ? null : $notes);
        } else {
            json_response(['ok' => false, 'error' => 'Câmp invalid.'], 422);
        }

        json_response(['ok' => true, 'status' => $status, 'guestAppSynced' => $synced]);
    }

    /**
     * POST /api/reservations/nuki {id}
     * The code is recomputed on the server from Previo — the browser never chooses what goes on the lock.
     */
    public static function nuki(): never
    {
        $user = Guard::requireAccess('reservations', 'edit');
        Guard::requireCsrf();

        $id = trim((string) (request_json()['id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $id)) {
            json_response(['ok' => false, 'error' => 'Rezervare invalidă.'], 422);
        }

        $row = self::previo(static fn(): ?array => ReservationFeed::findUpcoming($id));
        if ($row === null) {
            json_response(['ok' => false, 'error' => 'Rezervarea nu e printre check-in-urile de azi sau mâine.'], 404);
        }
        if (!$row['hasNuki'] || $row['nukiCode'] === null) {
            json_response(['ok' => false, 'error' => 'Apartamentul nu are yală Nuki sau oaspetele nu are telefon.'], 422);
        }

        try {
            $result = Nuki::sendKeypadCode($row['apartment'], $row['name'], $row['nukiCode']);
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 502);
        }
        if (!$result['ok']) {
            Audit::log((int) $user['id'], 'reservation.nuki', 'reservation', $id, [
                'apartment' => $row['apartment'],
                'http'      => $result['http'],
                'failed'    => true,
            ]);
            json_response(['ok' => false, 'error' => $result['reason']], 502);
        }

        Audit::log((int) $user['id'], 'reservation.nuki', 'reservation', $id, [
            'apartment' => $row['apartment'],
            'http'      => $result['http'],
        ]);
        json_response([
            'ok'      => true,
            'code'    => $row['nukiCode'],
            'already' => $result['already'],
            'message' => $result['already'] ? 'Codul exista deja pe yală' : 'Cod Nuki trimis',
        ]);
    }

    /** GET /api/reservations/whatsapp — check-outs today / 7 / 14 days ago + "Trimis" marks. */
    public static function whatsapp(): never
    {
        Guard::requireAccess('reservations', 'view');
        if (!Previo::isConfigured()) {
            json_response(['ok' => false, 'error' => 'Lipsește config/previo.php pe server.'], 503);
        }
        $data = ReservationFeed::whatsappWindows();
        $ids = [];
        foreach ($data['windows'] as $rows) {
            array_push($ids, ...array_column($rows, 'id'));
        }
        json_response([
            'ok'      => true,
            'windows' => $data['windows'],
            'dates'   => $data['dates'],
            'errors'  => $data['errors'],
            'sent'    => OutreachRepository::forReservations($ids),
        ]);
    }

    /** POST /api/reservations/whatsapp {id, type: w1|w2|w3, platform, sent} */
    public static function whatsappMark(): never
    {
        $user = Guard::requireAccess('reservations', 'edit');
        Guard::requireCsrf();

        $in = request_json();
        $id = trim((string) ($in['id'] ?? ''));
        $type = (string) ($in['type'] ?? '');
        $platform = isset($in['platform']) ? substr(preg_replace('/[^a-z_]/', '', (string) $in['platform']) ?? '', 0, 20) : null;
        if (!preg_match('/^\d{1,15}$/', $id) || !in_array($type, OutreachRepository::TYPES, true)) {
            json_response(['ok' => false, 'error' => 'Date invalide.'], 422);
        }
        OutreachRepository::mark($id, $type, $platform ?: null, (int) $user['id'], (bool) ($in['sent'] ?? true));
        json_response(['ok' => true, 'sent' => OutreachRepository::forReservations([$id])]);
    }

    /** GET /api/reservations/recent — Generator link (last 4 days of check-ins). */
    public static function recent(): never
    {
        Guard::requireAccess('reservations', 'view');
        json_response(['ok' => true, 'reservations' => self::previo(static fn(): array => ReservationFeed::recent())]);
    }

    /** Runs a Previo-backed read; turns integration failures into a readable JSON error. */
    private static function previo(callable $read): mixed
    {
        try {
            return $read();
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }
}
