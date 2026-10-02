<?php
declare(strict_types=1);

namespace One\Housekeeping;

/**
 * Cleaning pay rates, ported from housekeeping/config/app_config.php.
 * The payment report recomputes every line from here (rates are not stored in the database),
 * so fixing a rate here also fixes past reports — same as the legacy app.
 *
 * Unlike the legacy app, an apartment missing from these lists is FLAGGED in the report
 * ("tarif implicit"). It is still paid at the fallback rate so totals match the old report.
 * Stage 4 moves this into "Setări apartamente".
 */
final class Rates
{
    public const STUDIO = 50;
    public const APARTMENT = 60;
    public const FALLBACK = 60;

    public const STUDIOS = ['400', '424', '435', '99', '5', '367', '309', '295', '33'];
    public const APARTMENTS = ['594', '187', '40'];
    /** Checked first: these win over the lists above. */
    public const SPECIAL = ['40' => 85];

    /** @return array{rate:int, known:bool} */
    public static function rateFor(string $apartment, string $type): array
    {
        if ($type === 'intermediate') {
            return ['rate' => Checklist::INTERMEDIATE_RATE, 'known' => true];
        }
        $apartment = trim($apartment);
        if (isset(self::SPECIAL[$apartment])) {
            return ['rate' => self::SPECIAL[$apartment], 'known' => true];
        }
        if (in_array($apartment, self::STUDIOS, true)) {
            return ['rate' => self::STUDIO, 'known' => true];
        }
        if (in_array($apartment, self::APARTMENTS, true)) {
            return ['rate' => self::APARTMENT, 'known' => true];
        }
        return ['rate' => self::FALLBACK, 'known' => false];
    }
}
