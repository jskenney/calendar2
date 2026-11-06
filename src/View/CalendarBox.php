<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\Window;
use Cal5\Content\ContentFile;

/** A box of a calendar entry (title, homework, ...) as the viewer sees it. */
final class CalendarBox
{
    public function __construct(
        public readonly string $name,
        public readonly ContentFile $file,
        /** Students can see it now */
        public readonly bool $visible,
        public readonly Window $window,
        /** The text pasted into the calendar ("inline:" boxes, v4 $SRC), or null */
        public readonly ?string $inline,
        /** For the instructor: "opens" (not yet), "closed" (no longer, or never), "" (visible now) */
        public readonly string $state,
    ) {
    }

    /** "homework-solution" -> "Homework Solution" (v4) */
    public function label(): string
    {
        return ucwords(str_replace('-', ' ', $this->name));
    }
}
