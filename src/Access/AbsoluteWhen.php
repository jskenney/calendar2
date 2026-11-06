<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

/** "2026-09-14" or "2026-09-14 13:30" in the course time zone. */
final class AbsoluteWhen implements When
{
    public function __construct(
        public readonly string $date,
        public readonly string $time,
        private readonly string $text,
    ) {
    }

    public function reached(EventContext $context, DateTimeImmutable $now): bool
    {
        return $now >= $this->at($context);
    }

    public function at(EventContext $context): DateTimeImmutable
    {
        return self::dateTime($this->date, $this->time, $context);
    }

    /** "24:00" means midnight at the end of the day. */
    public static function dateTime(string $date, string $time, EventContext $context): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return (new DateTimeImmutable($date . ' 00:00', $context->timezone))->setTime($h, $m);
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
