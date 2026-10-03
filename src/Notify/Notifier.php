<?php
declare(strict_types=1);

namespace One\Notify;

use One\Db\Database;

/**
 * Audit → push. Audit::log() calls fromAudit() for every row; only these become notifications:
 *  - checklist trimis                       → admin, manager
 *  - lenjeriile unui apartament intră pe roșu → admin, manager, menajere
 *  - „Necesar” / „Tehnic” modificate        → admin, manager
 * Sets, boxes and towels stay in the Jurnal only. Extra actions: config/app.php 'push' => ['actions' => [...]]
 * (sent to admin + manager). The person who did the action is never notified (unless 'notify_self').
 *
 * Sending happens after the response is flushed (fastcgi/litespeed_finish_request), so a slow push
 * service never delays the +/− tap or the checklist upload. Failures are only logged.
 */
final class Notifier
{
    /** Roles that can subscribe a device. */
    public const ROLES = ['admin', 'manager', 'maid'];
    private const STAFF = ['admin', 'manager'];

    private const AUDIENCE = [
        'housekeeping.checklist' => self::STAFF,
        'inventory.note'         => self::STAFF,
        'inventory.tech'         => self::STAFF,
    ];

    /** @var array<string, array{payload:array, roles:list<string>, except:?int}> tag => message */
    private static array $queue = [];
    private static bool $hooked = false;

    public static function canReceive(?array $user): bool
    {
        return $user !== null && in_array($user['role'] ?? '', self::ROLES, true);
    }

    public static function fromAudit(?int $userId, string $action, ?string $targetId, array $meta): void
    {
        if (!WebPush::isConfigured()) {
            return;
        }
        try {
            $actor = $userId !== null ? self::userName($userId) : '';
            $except = (bool) ((config('push', [])['notify_self'] ?? false)) ? null : $userId;
            $apt = (string) $targetId;
            $tagApt = preg_replace('/[^A-Za-z0-9]/', '', $apt);

            // Linen of an apartment just went red (from +/− or "scade un set") → everyone, maids included.
            $left = Activity::linenTurnedRed($action, $apt, $meta);
            if ($left !== null) {
                self::queue([
                    'title' => "Lenjerii pe roșu · Apt $apt",
                    'body'  => ($left === 1 ? 'A mai rămas o lenjerie' : "Au mai rămas $left lenjerii") . ($actor !== '' ? " · $actor" : '') . '.',
                    'url'   => '/inventory?filter=critical',
                    'tag'   => "red-$tagApt",
                ], self::ROLES, $except);
            }

            $roles = self::AUDIENCE[$action] ?? (in_array($action, self::extraActions(), true) ? self::STAFF : null);
            if ($roles === null) {
                return;
            }
            $text = Activity::describe(['action' => $action, 'target_id' => $targetId, 'meta' => $meta, 'actor_name' => $actor]);
            $tag = match ($action) {
                'inventory.note' => "need-$tagApt",
                'inventory.tech' => "tech-$tagApt",
                'housekeeping.checklist' => "chk-$tagApt-" . (int) ($meta['submission'] ?? 1) . '-' . date('md'),
                default => str_replace('.', '-', $action) . "-$tagApt-" . date('His'),
            };
            // Autosaved notes fire while typing: update the notification quietly instead of buzzing each time.
            $renotify = !in_array($action, ['inventory.note', 'inventory.tech'], true);
            self::queue($text + ['tag' => $tag, 'renotify' => $renotify], $roles, $except);
        } catch (\Throwable $e) {
            error_log('[ONE] push queue failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array{title:string, body:string, url:string, tag:string} $payload
     * @param list<string> $roles
     */
    public static function queue(array $payload, array $roles, ?int $exceptUserId = null): void
    {
        self::$queue[$payload['tag']] = ['payload' => $payload, 'roles' => $roles, 'except' => $exceptUserId];
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'flushAfterResponse']);
        }
    }

    /** Sends right now to one user's own devices (the "Trimite un test" button). @return array{sent:int, failed:int} */
    public static function sendTo(int $userId, array $payload): array
    {
        $subs = PushSubscriptions::forRoles(self::ROLES, null, $userId);
        $results = WebPush::sendMany($subs, self::encode($payload), ['urgency' => 'high', 'ttl' => 600]);
        PushSubscriptions::applyResults($results);
        $ok = count(array_filter($results, static fn(int $s): bool => $s >= 200 && $s < 300));
        return ['sent' => $ok, 'failed' => count($subs) - $ok];
    }

    /** @internal shutdown function */
    public static function flushAfterResponse(): void
    {
        if (!self::$queue) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        $queue = self::$queue;
        self::$queue = [];
        foreach ($queue as $tag => $msg) {
            try {
                $subs = PushSubscriptions::forRoles($msg['roles'], $msg['except']);
                if (!$subs) {
                    continue;
                }
                $urgency = str_starts_with($tag, 'red-') ? 'high' : 'normal';
                $results = WebPush::sendMany($subs, self::encode($msg['payload']), ['topic' => $tag, 'ttl' => 86400, 'urgency' => $urgency]);
                PushSubscriptions::applyResults($results);
            } catch (\Throwable $e) {
                error_log('[ONE] push send failed: ' . $e->getMessage());
            }
        }
    }

    /** @return list<string> */
    private static function extraActions(): array
    {
        return array_map('strval', (array) ((config('push', [])['actions'] ?? [])));
    }

    private static function encode(array $payload): string
    {
        // Push services cap the payload at ~4 KB; keep the body short.
        $payload['body'] = mb_strimwidth((string) $payload['body'], 0, 300, '…');
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function userName(int $userId): string
    {
        static $cache = [];
        if (!isset($cache[$userId])) {
            $stmt = Database::get('one')->prepare('SELECT name FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $cache[$userId] = (string) ($stmt->fetchColumn() ?: '');
        }
        return $cache[$userId];
    }
}
