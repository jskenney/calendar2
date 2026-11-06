<?php

declare(strict_types=1);

namespace Cal5\Config;

/** The outcome of loading a course's configuration. */
final class LoadResult
{
    /**
     * @param list<Problem> $errors   the course can not run until these are fixed
     * @param list<Problem> $warnings shown to the instructor only
     */
    public function __construct(
        public readonly ConfigSource $source,
        public readonly ?Config $config,
        public readonly array $errors,
        public readonly array $warnings,
    ) {
    }

    public function ok(): bool
    {
        return $this->config !== null && $this->errors === [];
    }
}
