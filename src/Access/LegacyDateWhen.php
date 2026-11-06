<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

/**
 * The v4 "month day [year]" form, evaluated with v4's own formula so every
 * existing line means exactly what it did:
 *
 *   open if (year >  Y and M <= 12 and D <= 31)
 *        or (year >= Y and month >  M)
 *        or (year == Y and month == M and day >= D)
 *
 * So "99 99 9999" never opens, "1 1 1999" is always open, and an impossible
 * date such as "2 30" opens on the 1st of the next month.  The year defaults
 * to the course year (v4 $YEAR).  Comparisons use whole days (opening at 00:00).
 */
final class LegacyDateWhen implements When
{
    public function __construct(
        public readonly int $month,
        public readonly int $day,
        public readonly ?int $year,
        private readonly string $text,
    ) {
    }

    public function reached(EventContext $context, DateTimeImmutable $now): bool
    {
        $now = $now->setTimezone($context->timezone);
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $day = (int) $now->format('j');
        $y = $this->year ?? $context->courseYear;
        return ($year > $y && 12 >= $this->month && 31 >= $this->day)
            || ($year >= $y && $month > $this->month)
            || ($year === $y && $month === $this->month && $day >= $this->day);
    }

    public function at(EventContext $context): ?DateTimeImmutable
    {
        $y = $this->year ?? $context->courseYear;
        $m = $this->month;
        $d = $this->day;
        if ($m > 12 || $d > 31) {
            return null; // never (for example 99 99 9999)
        }
        if ($m === 0) {
            return new DateTimeImmutable(sprintf('%04d-01-01', $y), $context->timezone);
        }
        if ($d === 0) {
            return new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $context->timezone);
        }
        if (checkdate($m, $d, $y)) {
            return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d), $context->timezone);
        }
        // an impossible day of a real month (2 30): v4 opens on the 1st of the next month
        return (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $context->timezone))->modify('+1 month');
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
