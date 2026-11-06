<?php

declare(strict_types=1);

namespace Cal5\Config;

/** Dates are YYYY-MM-DD strings in the course time zone. */
final class SemesterSettings
{
    public function __construct(
        public readonly string $start,
        public readonly string $end,
        public readonly bool $weekends,
        public readonly ?string $showFrom = null,
        public readonly ?string $showUntil = null,
    ) {
    }
}
