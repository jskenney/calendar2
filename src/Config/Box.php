<?php

declare(strict_types=1);

namespace Cal5\Config;

/** A calendar box (title, homework, ...) and the files that can fill it. */
final class Box
{
    /** @param list<BoxCandidate> $candidates */
    public function __construct(public readonly string $name, public readonly array $candidates)
    {
    }
}
