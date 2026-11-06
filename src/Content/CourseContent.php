<?php

declare(strict_types=1);

namespace Cal5\Content;

use Cal5\Config\Problem;

/** Every event of every type found in a course directory. */
final class CourseContent
{
    /**
     * @param array<string, list<EventFolder>> $types type => events (index 0 is event 1); only types with events
     * @param list<Problem> $problems warnings for the instructor
     */
    public function __construct(
        public readonly array $types,
        public readonly array $problems = [],
    ) {
    }

    public function hasEvents(string $type): bool
    {
        return isset($this->types[$type]) && $this->types[$type] !== [];
    }

    public function event(string $type, int $number): ?EventFolder
    {
        return $number >= 1 ? ($this->types[$type][$number - 1] ?? null) : null;
    }

    /** @return list<EventFolder> */
    public function events(string $type): array
    {
        return $this->types[$type] ?? [];
    }
}
