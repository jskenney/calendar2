<?php

declare(strict_types=1);

namespace Cal5\Config;

/** A meeting time range such as 08:00-08:50 (24-hour, course time zone). */
final class MeetingTime
{
    public function __construct(public readonly string $start, public readonly string $end)
    {
    }

    public function __toString(): string
    {
        return $this->start . '-' . $this->end;
    }
}
