<?php

declare(strict_types=1);

namespace Cal5\Config;

final class IniReadResult
{
    /** @param list<Problem> $errors */
    public function __construct(
        public readonly IniDocument $document,
        public readonly array $errors,
    ) {
    }
}
