<?php
declare(strict_types=1);

namespace One\Inventory;

/**
 * Linen stock thresholds, ported 1:1 from inventory/db.php::getStockLevel().
 * Studios are the default; the big apartments and the central depot ("Boxa") have their own.
 * Stage 4 moves this into "Setări apartamente".
 */
final class Stock
{
    /** Apartments with more beds → higher thresholds. Anything else is a studio. */
    public const BIG = ['187', '594'];
    public const DEPOT = 'Boxa';

    public const LABELS = ['critical' => 'Critic', 'warning' => 'Stoc redus', 'ok' => 'OK'];

    /** @return 'critical'|'warning'|'ok' */
    public static function level(string $apartment, int $linen): string
    {
        if (self::isDepot($apartment)) {
            return $linen < 5 ? 'critical' : ($linen <= 12 ? 'warning' : 'ok');
        }
        if (in_array(trim($apartment), self::BIG, true)) {
            return $linen <= 3 ? 'critical' : ($linen === 4 ? 'warning' : 'ok');
        }
        return $linen <= 1 ? 'critical' : ($linen === 2 ? 'warning' : 'ok');
    }

    public static function isDepot(string $apartment): bool
    {
        return strcasecmp(trim($apartment), self::DEPOT) === 0;
    }
}
