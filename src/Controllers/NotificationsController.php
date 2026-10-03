<?php
declare(strict_types=1);

namespace One\Controllers;

use One\Http\Guard;
use One\Notify\Activity;
use One\Notify\Notifier;
use One\Notify\PushSubscriptions;
use One\Notify\WebPush;

/** Admin only: Jurnal (activity log) + this device's push subscription. */
final class NotificationsController
{
    /** GET /activity[?f=inventory] — module actions from audit_log, newest first, in plain words. */
    public static function activity(): never
    {
        $user = Guard::requireAccess('settings', 'edit');
        $filter = (string) ($_GET['f'] ?? 'all');
        $filter = isset(Activity::FILTERS[$filter]) ? $filter : 'all';
        $rows = [];
        foreach (Activity::recent($filter, 200) as $row) {
            $rows[] = Activity::describe($row) + ['at' => (string) $row['created_at'], 'action' => (string) $row['action']];
        }
        view('pages/activity', [
            'user'      => $user,
            'pageTitle' => 'Jurnal activitate',
            'active'    => 'account',
            'backHref'  => '/account',
            'filter'    => $filter,
            'rows'      => $rows,
            'push'      => self::pushState($user),
            'scripts'   => ['assets/js/push.js'],
        ]);
    }

    /** POST /api/push/subscribe {endpoint, keys:{p256dh, auth}} */
    public static function subscribe(): never
    {
        $user = Guard::requireAccess('settings', 'edit');
        Guard::requireCsrf();
        if (!WebPush::isConfigured()) {
            json_response(['ok' => false, 'error' => 'Notificările nu sunt configurate pe server (php bin/push-keys.php).'], 503);
        }
        $in = request_json();
        $endpoint = (string) ($in['endpoint'] ?? '');
        $p256dh = (string) ($in['keys']['p256dh'] ?? '');
        $auth = (string) ($in['keys']['auth'] ?? '');
        if (!PushSubscriptions::validEndpoint($endpoint)
            || !preg_match('/^[A-Za-z0-9_-]{80,100}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{16,30}$/', $auth)) {
            json_response(['ok' => false, 'error' => 'Abonament push invalid.'], 422);
        }
        PushSubscriptions::save((int) $user['id'], $endpoint, $p256dh, $auth, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        json_response(['ok' => true, 'devices' => PushSubscriptions::countFor((int) $user['id'])]);
    }

    /** POST /api/push/unsubscribe {endpoint} */
    public static function unsubscribe(): never
    {
        $user = Guard::requireAccess('settings', 'edit');
        Guard::requireCsrf();
        PushSubscriptions::delete((int) $user['id'], (string) (request_json()['endpoint'] ?? ''));
        json_response(['ok' => true, 'devices' => PushSubscriptions::countFor((int) $user['id'])]);
    }

    /** POST /api/push/test — one notification to the caller's own devices, sent now. */
    public static function test(): never
    {
        $user = Guard::requireAccess('settings', 'edit');
        Guard::requireCsrf();
        if (!WebPush::isConfigured()) {
            json_response(['ok' => false, 'error' => 'Notificările nu sunt configurate pe server.'], 503);
        }
        $result = Notifier::sendTo((int) $user['id'], [
            'title' => 'SmartStay ONE', 'body' => 'Notificările funcționează pe acest dispozitiv.',
            'url' => '/activity', 'tag' => 'test-' . time(),
        ]);
        if ($result['sent'] === 0) {
            json_response(['ok' => false, 'error' => 'Niciun dispozitiv n-a primit testul. Reactivează notificările.'], 502);
        }
        json_response(['ok' => true, 'message' => "Test trimis pe {$result['sent']} dispozitiv(e)."]);
    }

    /** @return array{configured:bool, publicKey:string, devices:int} */
    private static function pushState(array $user): array
    {
        return [
            'configured' => WebPush::isConfigured(),
            'publicKey'  => WebPush::isConfigured() ? WebPush::publicKey() : '',
            'devices'    => PushSubscriptions::countFor((int) $user['id']),
        ];
    }
}
