<?php

declare(strict_types=1);

namespace Cal5\Schedule;

final class CalendarDay
{
    /** @param list<ScheduledEvent> $entries */
    public function __construct(public readonly string $date, public readonly array $entries)
    {
    }
}
