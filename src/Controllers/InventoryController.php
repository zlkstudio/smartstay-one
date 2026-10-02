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
                'checkIn'  => $s['checkIn'] ? ['time' => $s['checkIn']['checkInTime'], 'guest' => $s['checkIn']['guest']] : null,
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
