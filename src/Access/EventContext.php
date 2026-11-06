<?php

declare(strict_types=1);

namespace Cal5\Access;

use Cal5\Config\MeetingTime;
use DateTimeImmutable;
use DateTimeZone;

/** What a rule needs to know about the event a file belongs to. */
final class EventContext
{
    public function __construct(
        /** The event's calendar date (YYYY-MM-DD), or null when it is not on the calendar */
        public readonly ?string $date,
        public readonly ?MeetingTime $meeting,
        public readonly DateTimeZone $timezone,
        /** The year used by v4 rules written as "month day" (v4 $YEAR: the semester's start year) */
        public readonly int $courseYear,
    ) {
    }

    /** Midnight at the start of the event's date, or null. */
    public function midnight(): ?DateTimeImmutable
    {
        return $this->date === null ? null : new DateTimeImmutable($this->date . ' 00:00', $this->timezone);
    }
}
