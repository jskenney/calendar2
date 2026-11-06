<?php

declare(strict_types=1);

namespace Cal5\Access;

use Cal5\Config\Problem;

/** The rules of an access file, by target (a later line for the same target wins, as in v4). */
final class AccessRules
{
    /**
     * @param array<string, Rule> $rules target => rule
     * @param list<Problem> $problems ignored lines, for the instructor
     */
    public function __construct(
        public readonly array $rules,
        public readonly array $problems = [],
        /** The access file exists but can not be read: nothing is shown to students (v4) */
        public readonly bool $unreadable = false,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function get(string $target): ?Rule
    {
        return $this->rules[$target] ?? null;
    }
}
