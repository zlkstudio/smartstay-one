<?php
declare(strict_types=1);

namespace One;

/**
 * Facts about the units that come back from Previo. Shared by Rezervări and Housekeeping.
 * Stage 4 moves this into "Setări apartamente"; until then it lives in code (no secrets here).
 */
final class Properties
{
    /**
     * Previo returns parking spots as bookable objects. They are never apartments:
     * no reservation card, no cleaning. Union of the legacy lists
     * (reservations/get_reservations.php + housekeeping/config/app_config.php).
     */
    public const PARKING_UNITS = ['58', '88', '143', '165', '166', '167', '174', '192'];

    /**
     * Units Previo returns that are not part of the rented portfolio: no occupancy, no channels,
     * no "libere la noapte" (Rapoarte + Acasă). Matched on the Previo object name, case-insensitive.
     */
    public const REPORT_EXCLUDED = ['40', 'daily'];

    public static function isReportExcluded(string $objectName): bool
    {
        $name = strtolower(trim($objectName));
        $name = preg_replace('/^(ap\.?|apt\.?|apartament)\s*/', '', $name) ?? $name;
        foreach (self::REPORT_EXCLUDED as $excluded) {
            if ($name === $excluded || ($excluded === 'daily' && str_starts_with($name, 'daily'))) {
                return true;
            }
        }
        return false;
    }

    /** "P-167", "P 167", "Parcare 88", "Parking 174", or a known parking id → "P-167". Else null. */
    public static function parkingLabel(string $objectName): ?string
    {
        $name = trim($objectName);
        if ($name === '') {
            return null;
        }
        if (preg_match('/\bP[\s\-]?(\d{2,4})\b/i', $name, $m)) {
            return 'P-' . $m[1];
        }
        if (preg_match('/(?:Parcare|Parking)\s+(?:P[\s\-]?)?(\d{2,4})/i', $name, $m)) {
            return 'P-' . $m[1];
        }
        return in_array($name, self::PARKING_UNITS, true) ? 'P-' . $name : null;
    }

    public static function isParking(string $objectName): bool
    {
        return self::parkingLabel($objectName) !== null;
    }

    /** Romanian number? Same rule as the legacy WhatsApp helper (07…, +407…, 407…, 00407…, +4007…). */
    public static function isRomanianPhone(string $phone): bool
    {
        $p = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $p = preg_replace('/^(\+?)4007/', '${1}407', $p) ?? $p;
        return (bool) preg_match('/^(07|\+407|407|00407)/', $p);
    }

    /** Digits for wa.me / api.whatsapp.com: 0784… → 40784…, +44… → 44…, 0044… → 44…. */
    public static function whatsappPhone(string $phone): string
    {
        $p = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $p = preg_replace('/^(\+?)4007/', '${1}407', $p) ?? $p;
        return normalize_phone($p);
    }
}
