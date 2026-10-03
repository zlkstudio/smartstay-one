<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use One\Auth\Access;
use One\Auth\Auth;
use One\Controllers\AuthController;
use One\Controllers\HousekeepingController;
use One\Controllers\InventoryController;
use One\Controllers\PageController;
use One\Controllers\ReportsController;
use One\Controllers\ReservationsController;
use One\Controllers\UsersController;
use One\Http\Guard;
use One\Http\Router;

// ── Security headers ───────────────────────────────────────────────────────
$nonce = csp_nonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; "
    . "style-src 'self' 'unsafe-inline'; font-src 'self'; "
    . "img-src 'self' data:; connect-src 'self'; manifest-src 'self'; worker-src 'self'; "
    . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(self), geolocation=(), microphone=()');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}
// Authenticated HTML must never sit in a shared or browser cache.
header('Cache-Control: no-store, private');

// ── Errors: log everything, show details only in development ──────────────
set_exception_handler(static function (Throwable $e): void {
    error_log('[ONE] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (Guard::isApi()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'Eroare internă. Încearcă din nou.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $detail = is_dev() ? '<pre style="white-space:pre-wrap">' . h((string) $e) . '</pre>' : '';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<body style="font-family:system-ui;padding:24px;max-width:640px;margin:auto">'
        . '<h1 style="font-size:20px">Ceva nu a mers bine</h1>'
        . '<p>Eroarea a fost înregistrată. Reîncarcă pagina; dacă persistă, anunță administratorul.</p>'
        . $detail . '<p><a href="/">Înapoi la Acasă</a></p></body>';
});

// ── Housekeeping of our own tables: ~1% of requests ────────────────────────
if (random_int(1, 100) === 1) {
    try {
        $pdo = One\Db\Database::get('one');
        $pdo->exec('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()');
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
    } catch (Throwable $e) {
        error_log('[ONE] cleanup skipped: ' . $e->getMessage());
    }
}

// ── Routes ─────────────────────────────────────────────────────────────────
$router = new Router();

$router->get('/install', static fn() => PageController::install());
$router->get('/login', static fn() => AuthController::showLogin());
$router->post('/login', static fn() => AuthController::login());
$router->post('/logout', static fn() => AuthController::logout());

$router->get('/', static fn() => PageController::home());
$router->get('/account', static fn() => AuthController::account());
$router->get('/account/password', static fn() => AuthController::showPassword());
$router->post('/account/password', static fn() => AuthController::changePassword());

// ── Rezervări (Etapa 2) ────────────────────────────────────────────────────
foreach (ReservationsController::TABS as $tab => $meta) {
    $router->get($meta['path'], static fn() => ReservationsController::page($tab));
}
$router->get('/api/reservations/list', static fn() => ReservationsController::list());
$router->get('/api/reservations/recent', static fn() => ReservationsController::recent());
$router->post('/api/reservations/status', static fn() => ReservationsController::status());
$router->post('/api/reservations/nuki', static fn() => ReservationsController::nuki());
$router->get('/api/reservations/whatsapp', static fn() => ReservationsController::whatsapp());
$router->post('/api/reservations/whatsapp', static fn() => ReservationsController::whatsappMark());

// ── Housekeeping (Etapa 2) ─────────────────────────────────────────────────
$router->get('/housekeeping', static fn() => HousekeepingController::index());
$router->get('/housekeeping/intermediate', static fn() => HousekeepingController::intermediate());
$router->get('/housekeeping/checklist/{apartment}', static fn(array $p) => HousekeepingController::checklist($p));
$router->get('/api/housekeeping/checkouts', static fn() => HousekeepingController::checkouts());
$router->get('/api/housekeeping/door-log', static fn() => HousekeepingController::doorLog());
$router->post('/api/housekeeping/assign', static fn() => HousekeepingController::assign());
$router->get('/api/housekeeping/active-guests', static fn() => HousekeepingController::activeGuests());
$router->post('/api/housekeeping/intermediate', static fn() => HousekeepingController::createIntermediate());
$router->post('/api/housekeeping/checklist', static fn() => HousekeepingController::submitChecklist());

// ── Inventar (Etapa 3) ─────────────────────────────────────────────────────
$router->get('/inventory', static fn() => InventoryController::index());
$router->get('/api/inventory/occupancy', static fn() => InventoryController::occupancy());
$router->post('/api/inventory/adjust', static fn() => InventoryController::adjust());
$router->post('/api/inventory/note', static fn() => InventoryController::note());
$router->post('/api/inventory/batch', static fn() => InventoryController::batch());
$router->post('/api/inventory/tech', static fn() => InventoryController::tech());

// ── Rapoarte (Etapa 3) ─────────────────────────────────────────────────────
$router->get('/reports', static fn() => ReportsController::overview());
$router->get('/reports/payments', static fn() => ReportsController::payments());
$router->get('/api/reports/today', static fn() => ReportsController::today());
$router->post('/api/reports/refresh', static fn() => ReportsController::refresh());
$router->post('/api/reports/cleaning', static fn() => ReportsController::addCleaning());
$router->post('/api/reports/cleaning/delete', static fn() => ReportsController::deleteCleaning());

$router->get('/users', static fn() => UsersController::index());
$router->get('/users/new', static fn() => UsersController::create());
$router->post('/users', static fn() => UsersController::store());
$router->get('/users/{id}/edit', static fn(array $p) => UsersController::edit($p));
$router->post('/users/{id}', static fn(array $p) => UsersController::update($p));
$router->post('/users/{id}/reset', static fn(array $p) => UsersController::resetPassword($p));
$router->post('/users/{id}/toggle', static fn(array $p) => UsersController::toggleActive($p));

$router->get('/settings', static fn() => PageController::settings());

// JSON API — every module endpoint opens with Guard::requireAccess().
$router->get('/api/me', static function (): never {
    $user = Guard::requireLogin();
    $modules = [];
    foreach (Access::visibleModules($user) as $module) {
        $modules[$module] = Access::level($user, $module);
    }
    json_response([
        'ok'   => true,
        'user' => [
            'id'      => (int) $user['id'],
            'name'    => $user['name'],
            'role'    => $user['role'],
            'modules' => $modules,
        ],
        'csrf' => Auth::csrfToken(),
    ]);
});

// Unauthenticated liveness probe for deploy-one.sh. Reveals nothing but "up".
$router->get('/api/ping', static fn() => json_response(['ok' => true, 'version' => ONE_VERSION]));

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
