<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Content\EventFolder;
use Cal5\Schedule\ScheduledEvent;

/** One entry of a calendar day, with the boxes the viewer may see. */
final class CalendarEntry
{
    /** @param list<CalendarBox> $boxes the calendar's boxes other than the title, in calendar order */
    public function __construct(
        public readonly ScheduledEvent $scheduled,
        /** The event's directory, or null (a holiday, or a day without material) */
        public readonly ?EventFolder $folder,
        /** The title box, when the viewer may see it (it links to the event page) */
        public readonly ?CalendarBox $title,
        public readonly array $boxes,
    ) {
    }

    /** "CLASS 3", "FINAL EXAM" (v4: the type in capitals, "-" as a space) */
    public function heading(): string
    {
        $label = str_replace('-', ' ', $this->scheduled->type);
        return $label . ($this->scheduled->number !== null ? ' ' . $this->scheduled->number : '');
    }

    public function name(): string
    {
        return $this->folder?->name ?? '';
    }
}
