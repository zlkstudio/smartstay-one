<?php
declare(strict_types=1);

namespace One\Housekeeping;

use DateTimeImmutable;
use One\Audit;
use One\Db\Database;
use One\Integrations\Nuki;
use One\Integrations\NukiCode;
use One\Integrations\Previo;
use One\Notify\Notifier;
use One\Properties;
use PDO;
use Throwable;

/**
 * Times check-out cleanings from the Nuki log and removes the departed guest's code.
 *
 *  start = a maid's own keypad code ("Ioana Menaj", "Cristina Menaj") unlocks an apartment that has
 *          a check-out today → session row + the departed guest's code is deleted from the lock.
 *  end   = the next lock (≥ 3 min later) by the maid herself or without a name (button, auto-lock).
 *          A lock by someone else (Romeo from the app) does NOT end it. → push to admin + manager.
 *
 * One session per check-out: an apartment with 2 check-outs today can have 2 sessions; a maid
 * coming back in after the end (forgot something) does not start a new one.
 * Runs from bin/cleaning-cron.php every minute and, as a fallback, from /api/housekeeping/sessions
 * (at most once a minute, file lock) — so it works even before the cron is set up.
 */
final class CleaningTracker
{
    /** Target minutes by length of stay: [max nights => [studio, apartment]]. */
    public const TARGETS = [
        1           => ['studio' => 30, 'apartment' => 35],
        3           => ['studio' => 35, 'apartment' => 40],
        5           => ['studio' => 40, 'apartment' => 45],
        PHP_INT_MAX => ['studio' => 50, 'apartment' => 60],
    ];
    public const BUCKETS = ['1' => '1 noapte', '2-3' => '2–3 nopți', '4-5' => '4–5 nopți', '6+' => '6+ nopți'];

    private const MIN_MINUTES = 3;          // a lock sooner than this is the maid locking behind her
    private const SYNC_EVERY = 55;          // seconds
    private const DEPARTURES_TTL = 1200;    // Previo re-read at most every 20 min

    // ── Rules ─────────────────────────────────────────────────────────────

    public static function unitFor(string $apartment): string
    {
        return in_array($apartment, Rates::STUDIOS, true) ? 'studio' : 'apartment';
    }

    /** Unknown length of stay → the longest target (never rushes the maid). */
    public static function targetFor(?int $nights, string $unit): int
    {
        foreach (self::TARGETS as $max => $t) {
            if ($nights !== null && $nights <= $max) {
                return $t[$unit];
            }
        }
        return self::TARGETS[PHP_INT_MAX][$unit];
    }

    public static function bucketFor(?int $nights): ?string
    {
        return match (true) {
            $nights === null || $nights < 1 => null,
            $nights === 1 => '1',
            $nights <= 3 => '2-3',
            $nights <= 5 => '4-5',
            default => '6+',
        };
    }

    /** Normalized Nuki name → maid display name ("ioana menaj" => "Ioana"). config/nuki.php 'maid_names' overrides. */
    public static function maidNukiNames(): array
    {
        $override = [];
        if (Nuki::isConfigured()) {
            $cfg = require ONE_ROOT . '/config/nuki.php';
            $override = (array) ($cfg['maid_names'] ?? []);
        }
        $out = [];
        foreach (config('maids', []) as $key => $display) {
            $nuki = (string) ($override[$key] ?? ($display . ' Menaj'));
            $out[Nuki::normalizeName($nuki)] = (string) $display;
        }
        return $out;
    }

    private static function maidOf(string $logName, array $names): ?string
    {
        $norm = Nuki::normalizeName($logName);
        foreach ($names as $nuki => $display) {
            if ($norm === $nuki || str_starts_with($norm, $nuki . ' ')) {
                return $display;
            }
        }
        return null;
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    /**
     * Today's sessions for the cards: who, started, done. No target, duration or stay details —
     * the cards (maids included) never show them; 'pace' only sets how fast the bar fills.
     * @return list<array{apartment:string, maid:string, startedAt:string, endedAt:?string, pace:int}>
     */
    public static function today(): array
    {
        $stmt = self::db()->prepare(
            'SELECT apartment, maid_name, target_min, started_at, ended_at
             FROM cleaning_sessions WHERE cleaning_date = ? ORDER BY started_at'
        );
        $stmt->execute([date('Y-m-d')]);
        return array_map(static fn(array $r): array => [
            'apartment' => (string) $r['apartment'],
            'maid'      => (string) $r['maid_name'],
            'startedAt' => self::iso((string) $r['started_at']),
            'endedAt'   => $r['ended_at'] !== null ? self::iso((string) $r['ended_at']) : null,
            'pace'      => (int) $r['target_min'],
        ], $stmt->fetchAll());
    }

    // ── Sync ──────────────────────────────────────────────────────────────

    /**
     * Reads today's Nuki log for the check-out apartments and opens / closes sessions.
     * $force (cron) ignores the once-a-minute throttle but never runs twice at the same time.
     * @return array{status:string, opened:int, closed:int, errors:array<string,string>}
     */
    public static function sync(bool $force = false): array
    {
        $result = ['status' => 'ok', 'opened' => 0, 'closed' => 0, 'errors' => []];
        if (!Nuki::isConfigured()) {
            return ['status' => 'no-nuki'] + $result;
        }
        $dir = ONE_ROOT . '/storage/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return ['status' => 'no-cache-dir'] + $result;
        }
        $lock = fopen("$dir/cleaning-sync.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['status' => 'busy'] + $result;
        }
        try {
            $stamp = "$dir/cleaning-sync.stamp";
            if (!$force && is_file($stamp) && time() - (int) filemtime($stamp) < self::SYNC_EVERY) {
                return ['status' => 'fresh'] + $result;
            }
            @touch($stamp);

            $date = date('Y-m-d');
            $plan = self::departures($date);
            $apartments = array_values(array_filter(array_keys($plan['out']), static fn($a): bool => Nuki::hasLock((string) $a)));
            if (!$apartments) {
                return ['status' => 'nothing'] + $result;
            }
            $logs = Nuki::logEntries($apartments, $date, 50, $errors);
            $result['errors'] = $errors;
            $maids = self::maidNukiNames();

            foreach ($logs as $apartment => $events) {
                $apartment = (string) $apartment;
                [$opened, $closed] = self::replay($apartment, $date, $events, $maids, $plan);
                $result['opened'] += $opened;
                $result['closed'] += $closed;
            }
            return $result;
        } catch (Throwable $e) {
            error_log('[ONE] cleaning sync: ' . $e->getMessage());
            return ['status' => 'error: ' . $e->getMessage()] + $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Walks the day's events against the sessions already stored — idempotent: a start or an end
     * already recorded is recognised by its exact time. @return array{0:int, 1:int} [opened, closed]
     */
    private static function replay(string $apartment, string $date, array $events, array $maids, array $plan): array
    {
        $pdo = self::db();
        $stmt = $pdo->prepare('SELECT * FROM cleaning_sessions WHERE apartment = ? AND cleaning_date = ? ORDER BY started_at');
        $stmt->execute([$apartment, $date]);
        $sessions = $stmt->fetchAll();
        $departures = $plan['out'][$apartment] ?? [];
        $opened = $closed = 0;
        $current = null;
        foreach ($sessions as $s) {
            if ($s['ended_at'] === null) {
                $current = $s;
            }
        }

        foreach ($events as $e) {
            if (!$e['ok']) {
                continue;
            }
            $maid = self::maidOf($e['name'], $maids);

            if ($maid !== null && in_array($e['action'], Nuki::UNLOCK_ACTIONS, true)) {
                if ($current !== null && $current['ended_at'] === null) {
                    continue;   // already cleaning: opening the door again changes nothing
                }
                $known = array_filter($sessions, static fn(array $s): bool => $s['started_at'] === $e['at']);
                $later = array_filter($sessions, static fn(array $s): bool => $s['started_at'] > $e['at']);
                if ($known || $later || count($sessions) >= max(1, count($departures))) {
                    continue;   // recorded already, older than what we know, or every check-out has its cleaning
                }
                $session = self::open($apartment, $date, count($sessions) + 1, $maid, $e['at'], $departures, $plan['keep'][$apartment] ?? []);
                if ($session !== null) {
                    $sessions[] = $session;
                    $current = $session;
                    $opened++;
                }
                continue;
            }

            if ($current !== null && $current['ended_at'] === null && in_array($e['action'], Nuki::LOCK_ACTIONS, true)
                && ($e['name'] === '' || $maid !== null)
                && strtotime($e['at']) - strtotime($current['started_at']) >= self::MIN_MINUTES * 60) {
                $via = trim(($maid ?? '') . ($e['via'] !== '' ? ' · ' . $e['via'] : ''), ' ·');
                if (self::close($current, $e['at'], $via)) {
                    $closed++;
                }
                $current['ended_at'] = $e['at'];
            }
        }
        return [$opened, $closed];
    }

    /** New session + deletes the departed guest's code. null when another run inserted it first. */
    private static function open(string $apartment, string $date, int $round, string $maid, string $at, array $departures, array $keep): ?array
    {
        $departure = $departures[$round - 1] ?? ($departures ? end($departures) : null);
        $nights = $departure['nights'] ?? null;
        $unit = self::unitFor($apartment);
        $target = self::targetFor($nights, $unit);

        $insert = self::db()->prepare(
            'INSERT IGNORE INTO cleaning_sessions
             (cleaning_date, apartment, round_no, maid_name, reservation_id, nights, unit, target_min, started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$date, $apartment, $round, $maid, $departure['reservationId'] ?? null, $nights, $unit, $target, $at]);
        if ($insert->rowCount() === 0) {
            return null;
        }
        $id = (int) self::db()->lastInsertId();

        // The guest has left: their keypad code goes. Codes of the guests still to come stay.
        $code = ['status' => 'skipped', 'note' => 'check-out necunoscut'];
        if ($departure !== null) {
            $later = array_slice($departures, $round);   // other check-outs today, not cleaned yet
            $keepCodes = [...($keep['codes'] ?? []), ...array_filter(array_column($later, 'code'))];
            $keepNames = [...($keep['names'] ?? []), ...array_column($later, 'guest')];
            try {
                $code = Nuki::removeGuestCode($apartment, (string) $departure['guest'], $departure['code'], $keepCodes, $keepNames);
            } catch (Throwable $e) {
                error_log('[ONE] guest code cleanup: ' . $e->getMessage());
                $code = ['status' => 'failed', 'note' => 'eroare Nuki'];
            }
        }
        self::db()->prepare('UPDATE cleaning_sessions SET code_status = ?, code_note = ? WHERE id = ?')
            ->execute([$code['status'], mb_substr($code['note'], 0, 255), $id]);

        Audit::log(null, 'housekeeping.cleaning_start', 'apartment', $apartment, [
            'maid' => $maid, 'nights' => $nights, 'unit' => $unit, 'target' => $target,
            'at' => substr($at, 11, 5), 'code' => $code['status'], 'codeNote' => $code['note'],
        ]);

        $stmt = self::db()->prepare('SELECT * FROM cleaning_sessions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Ends the session and sends the time to admin + manager (once, even with two runs at once). */
    private static function close(array $session, string $at, string $via): bool
    {
        $minutes = (int) round((strtotime($at) - strtotime((string) $session['started_at'])) / 60);
        $update = self::db()->prepare(
            'UPDATE cleaning_sessions SET ended_at = ?, duration_min = ?, end_via = ?, notified = 1
             WHERE id = ? AND ended_at IS NULL'
        );
        $update->execute([$at, $minutes, mb_substr($via, 0, 60), (int) $session['id']]);
        if ($update->rowCount() === 0) {
            return false;
        }

        $apartment = (string) $session['apartment'];
        $target = (int) $session['target_min'];
        $nights = $session['nights'] !== null ? (int) $session['nights'] : null;
        $meta = [
            'maid' => (string) $session['maid_name'], 'minutes' => $minutes, 'target' => $target,
            'nights' => $nights, 'unit' => (string) $session['unit'],
            'from' => substr((string) $session['started_at'], 11, 5), 'to' => substr($at, 11, 5),
            'code' => (string) ($session['code_status'] ?? ''),
        ];
        Audit::log(null, 'housekeeping.cleaning_done', 'apartment', $apartment, $meta);
        Notifier::queue(self::message($apartment, $meta) + [
            'url' => '/settings/cleaning-times',
            'tag' => 'clean-' . preg_replace('/[^0-9]/', '', $apartment) . '-' . (int) $session['id'],
        ], ['admin', 'manager']);
        return true;
    }

    /** Push / Jurnal text. @return array{title:string, body:string} */
    public static function message(string $apartment, array $m): array
    {
        $minutes = (int) ($m['minutes'] ?? 0);
        $target = (int) ($m['target'] ?? 0);
        $diff = $minutes - $target;
        $stay = ($m['nights'] ?? null) !== null ? $m['nights'] . ((int) $m['nights'] === 1 ? ' noapte' : ' nopți') : 'nopți necunoscute';
        $verdict = $diff > 0 ? "⚠ +$diff min peste țintă" : '✓ în țintă';
        $code = match ($m['code'] ?? '') {
            'deleted'   => ' · cod oaspete șters',
            'not_found' => ' · codul oaspetelui nu era pe yală',
            'failed'    => ' · codul oaspetelui NU s-a putut șterge',
            default     => '',
        };
        return [
            'title' => "Curățenie gata · Apt $apartment · $minutes min",
            'body'  => sprintf('%s · %s–%s · țintă %d min (%s, %s) · %s%s',
                $m['maid'] ?? '', $m['from'] ?? '', $m['to'] ?? '', $target, $stay,
                ($m['unit'] ?? '') === 'studio' ? 'studio' : 'apartament', $verdict, $code),
        ];
    }

    // ── Previo: who leaves today (nights, code) and who still comes (codes to keep) ──

    /**
     * @return array{out: array<string, list<array{reservationId:string, guest:string, code:?string, nights:?int, checkOut:string}>>,
     *               keep: array<string, array{codes:list<string>, names:list<string>}>}
     * Cached 20 min in storage/cache (names + door codes: never under public/).
     */
    public static function departures(string $date): array
    {
        $file = ONE_ROOT . "/storage/cache/hk-departures-$date.json";
        if (is_file($file) && time() - (int) filemtime($file) < self::DEPARTURES_TTL) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['out'], $data['keep'])) {
                return $data;
            }
        }

        $out = [];
        foreach (Previo::forDay($date, 'check-out') as $r) {
            $apartment = Previo::apartment($r);
            if ($apartment === '' || Properties::isParking($apartment)) {
                continue;
            }
            $from = substr((string) $r->term->from, 0, 10);
            $to = substr((string) $r->term->to, 0, 10);
            $nights = null;
            if ($from !== '' && $to !== '') {
                $nights = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days ?: null;
            }
            $phone = Previo::contactPhone($r);
            $out[Nuki::cleanApartment($apartment)][] = [
                'reservationId' => (string) $r->resId,
                'guest'         => Previo::contactName($r),
                'code'          => $phone !== '' ? NukiCode::fromPhone($phone) : null,
                'nights'        => $nights,
                'checkOut'      => strlen((string) $r->term->to) > 10 ? date('H:i', (int) strtotime((string) $r->term->to)) : '11:00',
            ];
        }
        foreach ($out as &$list) {
            usort($list, static fn(array $a, array $b): int => strcmp($a['checkOut'], $b['checkOut']));
        }
        unset($list);

        // Codes for today's and tomorrow's arrivals are often on the lock already — never touch them.
        $keep = [];
        $tomorrow = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        foreach ([...Previo::forDay($date, 'check-in'), ...Previo::forDay($tomorrow, 'check-in')] as $r) {
            $apartment = Nuki::cleanApartment(Previo::apartment($r));
            $phone = Previo::contactPhone($r);
            $code = $phone !== '' ? NukiCode::fromPhone($phone) : null;
            $keep[$apartment] ??= ['codes' => [], 'names' => []];
            if ($code !== null) {
                $keep[$apartment]['codes'][] = $code;
            }
            if (($name = Previo::contactName($r)) !== '') {
                $keep[$apartment]['names'][] = $name;
            }
        }

        $data = ['out' => $out, 'keep' => $keep];
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($file, 0640);
        return $data;
    }

    private static function iso(string $datetime): string
    {
        return (new DateTimeImmutable($datetime))->format(DATE_ATOM);
    }

    private static function db(): PDO
    {
        return Database::get('one');
    }
}
