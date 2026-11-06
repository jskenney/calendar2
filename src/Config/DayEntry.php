<?php

declare(strict_types=1);

namespace Cal5\Config;

/** One thing that happens on a day: a type (directory name), its label as written, and an optional meeting time. */
final class DayEntry
{
    public function __construct(
        /** Normalized type, also the content directory name ("final-exam") */
        public readonly string $type,
        /** As written in the configuration ("Final Exam"), used for display */
        public readonly string $label,
        public readonly ?MeetingTime $meeting = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->label . ($this->meeting !== null ? ' ' . $this->meeting : '');
    }
}
