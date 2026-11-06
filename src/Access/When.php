<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

/** One side of a release rule: when something opens (or closes). */
interface When
{
    /** True if this moment has arrived at $now. */
    public function reached(EventContext $context, DateTimeImmutable $now): bool;

    /**
     * The moment itself, for display, or null when it never arrives
     * (or can not be worked out: a relative time for an event that is not on the calendar).
     */
    public function at(EventContext $context): ?DateTimeImmutable;

    /** The rule text as written. */
    public function __toString(): string;
}
