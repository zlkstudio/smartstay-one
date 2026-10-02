<?php
declare(strict_types=1);

namespace One\Integrations;

/**
 * Propagates the staff "Check-in form" toggle to the Guest App, which unlocks
 * (or re-locks) the guest's access code. Copied from reservations/includes/GuestAppSync.php.
 *
 * config/checkin-sync.php (gitignored, PROTECTED_CONFIGS):
 *   ['guest_app_base_url' => 'https://smartstay.ro/guest-app', 'admin_token' => '…']
 * admin_token must equal 'admin_token' in guest-app/config/checkin.php.
 *
 * Best-effort: a Guest App outage never blocks the toggle — it is only logged.
 */
final class GuestAppSync
{
    public static function isConfigured(): bool
    {
        $cfg = self::config();
        return $cfg !== null && !empty($cfg['guest_app_base_url']) && !empty($cfg['admin_token']);
    }

    public static function guestAppUrl(): string
    {
        $cfg = self::config();
        return rtrim((string) ($cfg['guest_app_base_url'] ?? 'https://smartstay.ro/guest-app'), '/');
    }

    /** @return bool true when the Guest App accepted the change */
    public static function checkin(string $reservationId, bool $completed, bool $taxPaid, string $staffName): bool
    {
        if (!self::isConfigured()) {
            error_log("[ONE] GuestAppSync: config/checkin-sync.php lipsă — sync omis pentru $reservationId");
            return false;
        }
        $cfg = self::config();
        $payload = json_encode([
            'reservationId' => $reservationId,
            'action'        => $completed ? 'mark' : 'unmark',
            'taxPaid'       => $taxPaid,
            'taxMethod'     => 'reservations-toggle',
            'staffName'     => $staffName,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::guestAppUrl() . '/api/admin_mark_checkin.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Admin-Token: ' . $cfg['admin_token']],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            error_log(sprintf(
                '[ONE] GuestAppSync failed res=%s action=%s HTTP=%d err=%s resp=%s',
                $reservationId,
                $completed ? 'mark' : 'unmark',
                $status,
                $error,
                substr((string) $response, 0, 300)
            ));
            return false;
        }
        return true;
    }

    private static function config(): ?array
    {
        static $config = false;
        if ($config !== false) {
            return $config;
        }
        $path = ONE_ROOT . '/config/checkin-sync.php';
        $data = is_file($path) ? require $path : null;
        return $config = is_array($data) ? $data : null;
    }
}
