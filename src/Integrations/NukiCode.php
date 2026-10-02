<?php
declare(strict_types=1);

namespace One\Integrations;

/**
 * Door code derived from the guest's phone — identical in Guest App, Reservations and the cron:
 * last 6 digits → every 0 becomes 1 → if it starts with "12", position 2 becomes 1.
 * The guest sees this exact code in the Guest App, so never change it here alone.
 */
final class NukiCode
{
    public static function fromPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        $code = str_replace('0', '1', str_pad(substr($digits, -6), 6, '0', STR_PAD_LEFT));
        if (str_starts_with($code, '12')) {
            $code[1] = '1';
        }
        return $code;
    }
}
