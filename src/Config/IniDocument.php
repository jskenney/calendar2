<?php

declare(strict_types=1);

namespace Cal5\Config;

/** The parsed contents of an INI-style file: sections of entries, in file order. */
final class IniDocument
{
    /**
     * @param array<string, list<IniEntry>> $sections lower-case section name => entries
     * @param array<string, int> $sectionLines lower-case section name => line of its header
     */
    public function __construct(
        public readonly string $file,
        private readonly array $sections,
        private readonly array $sectionLines = [],
    ) {
    }

    /** @return list<string> section names in file order ('' = entries before any section) */
    public function sectionNames(): array
    {
        return array_keys($this->sections);
    }

    public function hasSection(string $section): bool
    {
        return isset($this->sections[strtolower($section)]);
    }

    public function sectionLine(string $section): ?int
    {
        return $this->sectionLines[strtolower($section)] ?? null;
    }

    /** @return list<IniEntry> */
    public function entries(string $section): array
    {
        return $this->sections[strtolower($section)] ?? [];
    }

    /** The last entry with this key (later lines win), or null. */
    public function get(string $section, string $key): ?IniEntry
    {
        $found = null;
        foreach ($this->entries($section) as $entry) {
            if ($entry->name() === strtolower($key)) {
                $found = $entry;
            }
        }
        return $found;
    }

    /** @return list<IniEntry> every entry with this key, in order */
    public function all(string $section, string $key): array
    {
        return array_values(array_filter(
            $this->entries($section),
            static fn (IniEntry $e): bool => $e->name() === strtolower($key),
        ));
    }
}
