<?php
declare(strict_types=1);

namespace One\Reports;

use DateTimeImmutable;

/**
 * A report period: an inclusive range of NIGHTS [from, to] plus the period it is compared with.
 * Built from ?p=<preset> or ?from=&to= (custom). One parameter drives every section of
 * Rapoarte · Prezentare — no section has its own copy of the date logic.
 */
final class Period
{
    public const PRESETS = [
        'today'      => 'Azi',
        '7d'         => '7 zile',
        '30d'        => '30 zile',
        'this_month' => 'Luna asta',
        'prev_month' => 'Luna trecută',
        'this_year'  => 'Anul ăsta',
    ];

    private const MAX_DAYS = 400;

    private function __construct(
        public readonly string $key,        // preset key or 'custom'
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,       // "2 oct 2026", "1–31 oct 2026"…
        public readonly ?string $compareFrom,
        public readonly ?string $compareTo,
        public readonly string $compareLabel, // "vs săpt. trecută"
    ) {
    }

    /** @param array<string,mixed> $query usually $_GET */
    public static function fromQuery(array $query, ?string $today = null): self
    {
        $today ??= date('Y-m-d');
        $from = self::date((string) ($query['from'] ?? ''));
        $to = self::date((string) ($query['to'] ?? ''));
        if ($from !== null && $to !== null) {
            return self::custom($from, $to);
        }
        $key = (string) ($query['p'] ?? 'today');
        return self::preset(isset(self::PRESETS[$key]) ? $key : 'today', $today);
    }

    public static function preset(string $key, ?string $today = null): self
    {
        $t = new DateTimeImmutable($today ?? date('Y-m-d'));
        $month = $t->modify('first day of this month');
        $year = (int) $t->format('Y');

        return match ($key) {
            '7d' => self::rolling('7d', $t, 7),
            '30d' => self::rolling('30d', $t, 30),
            'this_month' => new self(
                'this_month', $month->format('Y-m-d'), $month->modify('last day of this month')->format('Y-m-d'),
                self::monthLabel($month) . ' (toată luna)',
                $month->modify('-1 month')->format('Y-m-d'), $month->modify('-1 day')->format('Y-m-d'), 'vs luna trecută'
            ),
            'prev_month' => new self(
                'prev_month', $month->modify('-1 month')->format('Y-m-d'), $month->modify('-1 day')->format('Y-m-d'),
                self::monthLabel($month->modify('-1 month')),
                $month->modify('-2 months')->format('Y-m-d'), $month->modify('-1 month -1 day')->format('Y-m-d'), 'vs luna dinainte'
            ),
            'this_year' => new self(
                'this_year', "$year-01-01", "$year-12-31", "$year (tot anul)",
                ($year - 1) . '-01-01', ($year - 1) . '-12-31', 'vs ' . ($year - 1)
            ),
            default => new self(
                'today', $t->format('Y-m-d'), $t->format('Y-m-d'), 'noaptea de azi',
                $t->modify('-7 days')->format('Y-m-d'), $t->modify('-7 days')->format('Y-m-d'), 'vs săpt. trecută'
            ),
        };
    }

    public static function custom(string $from, string $to): self
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);
        if ($a->diff($b)->days >= self::MAX_DAYS) {
            $b = $a->modify('+' . (self::MAX_DAYS - 1) . ' days');
        }
        $days = $a->diff($b)->days + 1;
        return new self(
            'custom', $a->format('Y-m-d'), $b->format('Y-m-d'),
            self::rangeLabel($a, $b),
            $a->modify("-$days days")->format('Y-m-d'), $a->modify('-1 day')->format('Y-m-d'),
            'vs perioada anterioară'
        );
    }

    public function days(): int
    {
        return (new DateTimeImmutable($this->from))->diff(new DateTimeImmutable($this->to))->days + 1;
    }

    public function isToday(): bool
    {
        return $this->key === 'today';
    }

    /** Earliest night any section of the page needs (period, comparison, trend year). */
    public function earliest(): string
    {
        return min($this->from, $this->compareFrom ?? $this->from, date('Y') . '-01-01');
    }

    public function latest(): string
    {
        return max($this->to, date('Y') . '-12-31');
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function rolling(string $key, DateTimeImmutable $t, int $days): self
    {
        $from = $t->modify('-' . ($days - 1) . ' days');
        return new self(
            $key, $from->format('Y-m-d'), $t->format('Y-m-d'), self::rangeLabel($from, $t),
            $from->modify("-$days days")->format('Y-m-d'), $from->modify('-1 day')->format('Y-m-d'),
            'vs perioada anterioară'
        );
    }

    private const MONTHS = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    private const MONTHS_LONG = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie',
        'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

    public static function monthShort(int $month): string
    {
        return self::MONTHS[$month - 1] ?? '';
    }

    private static function monthLabel(DateTimeImmutable $d): string
    {
        return ucfirst(self::MONTHS_LONG[(int) $d->format('n') - 1]) . ' ' . $d->format('Y');
    }

    private static function rangeLabel(DateTimeImmutable $a, DateTimeImmutable $b): string
    {
        $fmt = static fn(DateTimeImmutable $d): string => $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1];
        return $fmt($a) . ' – ' . $fmt($b) . ' ' . $b->format('Y');
    }

    private static function date(string $value): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
