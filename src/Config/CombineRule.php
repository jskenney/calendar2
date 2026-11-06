<?php

declare(strict_types=1);

namespace Cal5\Config;

/** Add events to the Nth event of a type, wherever it falls (v4 $COMBINE). */
final class CombineRule
{
    /** @param list<string> $add normalized types */
    public function __construct(
        public readonly string $type,
        public readonly int $number,
        public readonly array $add,
    ) {
    }
}
