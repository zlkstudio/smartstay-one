<?php
declare(strict_types=1);

namespace One\Controllers;

use One\Audit;
use One\Auth\Access;
use One\Http\Guard;
use One\Inventory\InventoryRepository;
use One\Inventory\Stock;
use One\Stays;
use RuntimeException;

/**
 * Inventar — port of the legacy Inventory app (index.php, update.php, update_necesar.php,
 * api/sync_previo.php). Stock comes from the database (instant); today's check-in / check-out
 * status comes from Previo through Stays (cached 5 min), loaded after the page.
 */
final class InventoryController
{
    public const FILTERS = [
        'all'      => 'Toate',
        'critical' => 'Pe roșu',
        'checkin'  => 'Check-in azi',
        'checkout' => 'Check-out azi',
    ];

    public static function index(): never
    {
        $user = Guard::requireAccess('inventory', 'view');

        $rows = InventoryRepository::all();
        $last = Audit::latestFor('inventory');
        foreach ($rows as &$row) {
            $row['depot'] = Stock::isDepot($row['apartment']);
            $row['level'] = Stock::level($row['apartment'], $row['items']['lenjerie']);
            $row['last'] = $last[$row['apartment']] ?? null;
        }
        unset($row);

        // Depot last; otherwise lowest linen first (critical on top), then apartment number.
        usort($rows, static fn(array $a, array $b): int =>
            [$a['depot'], $a['items']['lenjerie']] <=> [$b['depot'], $b['items']['lenjerie']]
            ?: strnatcmp($a['apartment'], $b['apartment']));

        $filter = (string) ($_GET['filter'] ?? 'all');
        view('pages/inventory/index', [
            'user'      => $user,
            'pageTitle' => 'Inventar',
            'active'    => 'inventory',
            'rows'      => $rows,
            'canEdit'   => Access::can($user, 'inventory', 'edit'),
            'filter'    => isset(self::FILTERS[$filter]) ? $filter : 'all',
            'styles'    => ['assets/css/modules.css'],
            'scripts'   => ['assets/js/inventory.js'],
        ]);
    }

    /** GET /api/inventory/occupancy — today's check-in / check-out / in-house per apartment. */
    public static function occupancy(): never
    {
        Guard::requireAccess('inventory', 'view');
        try {
            $stays = Stays::window();
        } catch (RuntimeException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        $out = [];
        foreach (Stays::dayStatus($stays, date('Y-m-d')) as $apartment => $s) {
            $out[$apartment] = [
                'checkIn'  => $s['checkIn'] ? ['time' => $s['checkIn']['checkInTime'], 'guest' => $s['checkIn']['guest'], 'option' => !empty($s['checkIn']['option'])] : null,
                'checkOut' => $s['checkOut'] ? ['time' => $s['checkOut']['checkOutTime'], 'guest' => $s['checkOut']['guest']] : null,
                'staying'  => $s['staying'] ? ['guest' => $s['staying']['guest'], 'until' => $s['staying']['checkOut']] : null,
            ];
        }
        json_response(['ok' => true, 'date' => date('Y-m-d'), 'apartments' => $out]);
    }

    /** POST /api/inventory/adjust {apartment, item, delta: 1|-1} */
    public static function adjust(): never
    {
        $user = Guard::requireAccess('inventory', 'edit');
        Guard::requireCsrf();
        $in = request_json();
        $apartment = self::apartment($in['apartment'] ?? null);
        $item = (string) ($in['item'] ?? '');
        $delta = (int) ($in['delta'] ?? 0);
        if (!isset(InventoryRepository::ITEMS[$item]) || ($delta !== 1 && $delta !== -1)) {
            json_response(['ok' => false, 'error' => 'Articol invalid.'], 422);
        }

        $value = InventoryRepository::adjust($apartment, $item, $delta);
        if ($value === null) {
            json_response(['ok' => false, 'error' => "Apartamentul $apartment nu există în inventar."], 404);
        }
        Audit::log((int) $user['id'], 'inventory.adjust', 'inventory', $apartment, [
            'item' => $item, 'delta' => $delta, 'value' => $value,
        ]);

        $response = ['ok' => true, 'value' => $value];
        if ($item === 'lenjerie') {
            $response['level'] = Stock::level($apartment, $value);
        }
        json_response($response);
    }

    /** POST /api/inventory/note {apartment, note} — "Necesar", autosaved from the card. */
    public static function note(): never
    {
        $user = Guard::requireAccess('inventory', 'edit');
        Guard::requireCsrf();
        $in = request_json();
        $apartment = self::apartment($in['apartment'] ?? null);
        $note = (string) ($in['note'] ?? '');
        if (mb_strlen($note) > InventoryRepository::NOTE_MAX) {
            json_response(['ok' => false, 'error' => 'Nota e prea lungă (max. ' . InventoryRepository::NOTE_MAX . ' caractere).'], 422);
        }
        if (!InventoryRepository::setNote($apartment, $note)) {
            json_response(['ok' => false, 'error' => "Apartamentul $apartment nu există în inventar."], 404);
        }
        Audit::log((int) $user['id'], 'inventory.note', 'inventory', $apartment, ['length' => mb_strlen(trim($note))]);
        json_response(['ok' => true]);
    }

    /**
     * POST /api/inventory/batch {apartment, op: "set"|"box"|"undo", deltas?}
     * "set" takes one guest set out, "box" adds one supplier box, "undo" reverses the
     * deltas the previous batch actually applied (sent back by the client, bounded).
     */
    public static function batch(): never
    {
        $user = Guard::requireAccess('inventory', 'edit');
        Guard::requireCsrf();
        $in = request_json();
        $apartment = self::apartment($in['apartment'] ?? null);
        $op = (string) ($in['op'] ?? '');

        if (isset(InventoryRepository::BATCHES[$op])) {
            $deltas = InventoryRepository::BATCHES[$op];
        } elseif ($op === 'undo' && is_array($in['deltas'] ?? null)) {
            $deltas = [];
            foreach ($in['deltas'] as $item => $delta) {
                if (!isset(InventoryRepository::ITEMS[$item]) || !is_int($delta) || abs($delta) > 20) {
                    json_response(['ok' => false, 'error' => 'Anulare invalidă.'], 422);
                }
                $deltas[$item] = -$delta;
            }
        } else {
            json_response(['ok' => false, 'error' => 'Operație invalidă.'], 422);
        }

        $result = InventoryRepository::applyDeltas($apartment, $deltas);
        if ($result === null) {
            json_response(['ok' => false, 'error' => "Apartamentul $apartment nu există în inventar."], 404);
        }
        Audit::log((int) $user['id'], 'inventory.batch', 'inventory', $apartment, ['op' => $op, 'applied' => $result['applied']]);

        $labels = ['set' => 'Set scăzut', 'box' => 'Cutie adăugată', 'undo' => 'Operația a fost anulată'];
        json_response([
            'ok'      => true,
            'values'  => $result['values'],
            'applied' => $result['applied'],
            'level'   => Stock::level($apartment, $result['values']['lenjerie']),
            'message' => $labels[$op] . " · $apartment",
        ]);
    }

    /** POST /api/inventory/tech {apartment, tvApp?: bool, tech?: string} — "Tehnic" on the back. */
    public static function tech(): never
    {
        $user = Guard::requireAccess('inventory', 'edit');
        Guard::requireCsrf();
        $in = request_json();
        $apartment = self::apartment($in['apartment'] ?? null);
        $tvApp = array_key_exists('tvApp', $in) ? (bool) $in['tvApp'] : null;
        $tech = array_key_exists('tech', $in) ? (string) $in['tech'] : null;
        if ($tvApp === null && $tech === null) {
            json_response(['ok' => false, 'error' => 'Nimic de salvat.'], 422);
        }
        if ($tech !== null && mb_strlen($tech) > InventoryRepository::NOTE_MAX) {
            json_response(['ok' => false, 'error' => 'Nota e prea lungă.'], 422);
        }
        if (!InventoryRepository::setTech($apartment, $tvApp, $tech)) {
            json_response(['ok' => false, 'error' => "Apartamentul $apartment nu există în inventar."], 404);
        }
        Audit::log((int) $user['id'], 'inventory.tech', 'inventory', $apartment, array_filter([
            'tvApp' => $tvApp, 'length' => $tech === null ? null : mb_strlen(trim($tech)),
        ], static fn($v): bool => $v !== null));
        json_response(['ok' => true]);
    }

    /** Apartment numbers or "Boxa": letters, digits, space, dash — max 10 (column width). */
    private static function apartment(mixed $raw): string
    {
        $apartment = trim((string) $raw);
        if (!preg_match('/^[\p{L}\d][\p{L}\d \-]{0,9}$/u', $apartment)) {
            json_response(['ok' => false, 'error' => 'Apartament invalid.'], 422);
        }
        return $apartment;
    }
}
