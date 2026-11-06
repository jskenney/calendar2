<?php

declare(strict_types=1);

namespace Cal5\Config;

final class DisplaySettings
{
    public const START_PAGES = ['home', 'today'];
    public const CALENDAR_VIEWS = ['month', 'list', 'agenda'];
    public const BOX_PICKS = ['listed', 'first-by-name'];

    /**
     * @param list<int> $markdownMenuLevels
     * @param list<string>|null $calendarBoxes boxes shown on the calendar, in order (null = every box)
     */
    public function __construct(
        public readonly string $startPage,
        public readonly string $calendarView,
        public readonly array $markdownMenuLevels,
        public readonly bool $headingWarnings,
        /** "listed": first candidate in the listed order; "first-by-name": the v4 rule, the file whose name sorts first */
        public readonly string $boxPick,
        public readonly ?array $calendarBoxes,
    ) {
    }
}
