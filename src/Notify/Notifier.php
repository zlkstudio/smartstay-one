<?php
declare(strict_types=1);

namespace One\Notify;

use One\Db\Database;

/**
 * Audit → push to admins. Audit::log() calls fromAudit() for every row; the actions in PUSH_ACTIONS
 * (extendable via config/app.php 'push' => ['actions' => [...]]) become a notification on every
 * device an active admin subscribed from "Contul meu".
 *
 * Sending happens after the response is flushed (fastcgi/litespeed_finish_request), so a slow push
 * service never delays the +/− tap or the checklist upload. Failures are only logged.
 */
final class Notifier
{
    public const PUSH_ACTIONS = [
        'housekeeping.checklist',
        'inventory.adjust', 'inventory.batch', 'inventory.note', 'inventory.tech',
    ];

    /** @var array<string, array{payload:array, topic:string, except:?int}> tag => message */
    private static array $queue = [];
    private static bool $hooked = false;

    public static function fromAudit(?int $userId, string $action, ?string $targetId, array $meta): void
    {
        if (!in_array($action, self::actions(), true) || !WebPush::isConfigured()) {
            return;
        }
        try {
            $text = Activity::describe([
                'action' => $action, 'target_id' => $targetId, 'meta' => $meta,
                'actor_name' => $userId !== null ? self::userName($userId) : null,
            ]);
            $apt = preg_replace('/[^A-Za-z0-9]/', '', (string) $targetId);
            $tag = match ($action) {
                'inventory.adjust' => "inv-$apt-$userId",
                'inventory.note'   => "need-$apt",
                'inventory.tech'   => "tech-$apt",
                'housekeeping.checklist' => "chk-$apt-" . (int) ($meta['submission'] ?? 1) . '-' . date('md'),
                default            => str_replace('.', '-', $action) . "-$apt-" . date('His'),
            };
            if ($action === 'inventory.adjust' && $userId !== null && ($burst = Activity::inventoryBurst($userId, (string) $targetId))) {
                $text['body'] = self::userName($userId) . ': ' . $burst;
            }
            $notifySelf = (bool) ((config('push', [])['notify_self'] ?? false));
            // Autosaved notes fire while typing: update the notification quietly instead of buzzing each time.
            $renotify = !in_array($action, ['inventory.note', 'inventory.tech'], true);
            self::queue($text + ['tag' => $tag, 'renotify' => $renotify], $notifySelf ? null : $userId);
        } catch (\Throwable $e) {
            error_log('[ONE] push queue failed: ' . $e->getMessage());
        }
    }

    /** @param array{title:string, body:string, url:string, tag:string} $payload */
    public static function queue(array $payload, ?int $exceptUserId = null): void
    {
        self::$queue[$payload['tag']] = ['payload' => $payload, 'except' => $exceptUserId];
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'flushAfterResponse']);
        }
    }

    /** Sends right now to one admin's own devices (the "Trimite un test" button). @return array{sent:int, failed:int} */
    public static function sendTo(int $userId, array $payload): array
    {
        $subs = PushSubscriptions::forAdmins(null, $userId);
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
                $subs = PushSubscriptions::forAdmins($msg['except']);
                if (!$subs) {
                    continue;
                }
                $results = WebPush::sendMany($subs, self::encode($msg['payload']), ['topic' => $tag, 'ttl' => 86400]);
                PushSubscriptions::applyResults($results);
            } catch (\Throwable $e) {
                error_log('[ONE] push send failed: ' . $e->getMessage());
            }
        }
    }

    /** @return list<string> */
    private static function actions(): array
    {
        $extra = (array) ((config('push', [])['actions'] ?? []));
        return array_values(array_unique(array_merge(self::PUSH_ACTIONS, array_map('strval', $extra))));
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
