<?php
declare(strict_types=1);

namespace One\Integrations;

/**
 * Read-only view of the Guest App check-in state (data/checkin/{reservationId}/status.json).
 *
 * Same server, same user: the files are read directly from the Guest App's shared data dir
 * (~/shared/guest-app/data/checkin — the symlink target, so it survives Guest App deploys).
 * Override in config/app.php: 'guest_app_checkin_dir' => '/home/…/shared/guest-app/data/checkin'.
 * ONE never writes there: tax / check-in changes still go through GuestAppSync.
 */
final class GuestAppCheckins
{
    public static function dir(): string
    {
        $configured = (string) config('guest_app_checkin_dir', '');
        return rtrim($configured !== '' ? $configured : dirname(ONE_ROOT) . '/shared/guest-app/data/checkin', '/');
    }

    public static function isAvailable(): bool
    {
        return is_dir(self::dir()) && is_readable(self::dir());
    }

    /** @return array<string,mixed>|null status.json, or null when the guest never started the check-in */
    public static function status(string $reservationId): ?array
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $reservationId);
        if ($safe === '' || $safe !== $reservationId) {
            return null;
        }
        $file = self::dir() . "/$safe/status.json";
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Cash the guest was told to leave in LEI on the kitchen table (check-in method "cash"),
     * while the team has not yet marked the tax as collected. null = nothing to pick up.
     */
    public static function cashToCollect(string $reservationId): ?float
    {
        $s = self::status($reservationId);
        if ($s === null || empty($s['formCompleted']) || empty($s['cashInApartment']) || !empty($s['taxPaid'])) {
            return null;
        }
        $due = $s['cashDue'] ?? $s['breakdown']['total'] ?? $s['taxAmount'] ?? null;
        return is_numeric($due) && (float) $due > 0 ? round((float) $due, 2) : null;
    }
}
