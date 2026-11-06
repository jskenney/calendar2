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
        /**
         * When the configuration was read but fails a check (for example the published
         * sample secret): the configuration with a random secret, so the course can show
         * its error page around the usual header (v4 did the same).  Null otherwise.
         */
        public readonly ?Config $draft = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->config !== null && $this->errors === [];
    }
}
