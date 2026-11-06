<?php

declare(strict_types=1);

namespace Cal5\Config;

/** What happens on a day: one or more entries ("lab + class"). */
final class DayPlan
{
    /** @param list<DayEntry> $entries */
    public function __construct(public readonly array $entries)
    {
    }

    public function __toString(): string
    {
        return implode(' + ', array_map('strval', $this->entries));
    }

    /** @return list<array{type: string, label: string, meeting: ?string}> */
    public function toArray(): array
    {
        return array_map(static fn (DayEntry $e): array => [
            'type' => $e->type,
            'label' => $e->label,
            'meeting' => $e->meeting !== null ? (string) $e->meeting : null,
        ], $this->entries);
    }
}
