<?php

declare(strict_types=1);

namespace Cal5\Schedule;

use Cal5\Config\MeetingTime;

/** Something on a calendar day: an event of a type (class 3), or a label such as a holiday. */
final class ScheduledEvent
{
    public function __construct(
        public readonly string $date,
        public readonly string $type,
        /** As written in the configuration ("Final Exam", "HOLIDAY") */
        public readonly string $label,
        /** Event number, or null when the type has no event for this day (a holiday, or more days than material) */
        public readonly ?int $number,
        public readonly ?MeetingTime $meeting,
        /** Added by a [combine] rule (v4 $COMBINE) */
        public readonly bool $combined = false,
    ) {
    }
}
