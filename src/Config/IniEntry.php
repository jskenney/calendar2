<?php

declare(strict_types=1);

namespace Cal5\Config;

/** One "key = value" line of an INI-style file. */
final class IniEntry
{
    public function __construct(
        /** The key as written (case kept, trimmed) */
        public readonly string $key,
        /** The value with quotes and comments removed */
        public readonly string $value,
        public readonly int $line,
    ) {
    }

    /** Lower-case key, used for lookups. */
    public function name(): string
    {
        return strtolower($this->key);
    }
}
