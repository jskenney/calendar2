<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

/** The current time, injectable so tests can check minute boundaries. */
interface Clock
{
    public function now(): DateTimeImmutable;
}
