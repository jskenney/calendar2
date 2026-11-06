<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;
use DateTimeZone;

/** A clock stopped at one moment (tests, and "bin/cal5 access --at"). */
final class FixedClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public static function at(string $time, string $timezone = 'UTC'): self
    {
        return new self(new DateTimeImmutable($time, new DateTimeZone($timezone)));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
