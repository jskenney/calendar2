<?php

declare(strict_types=1);

namespace Cal5\Content;

use Cal5\Config\Problem;
use Cal5\Config\Values;

/**
 * Reads the virtual file (v4 $CLASS_FILE, usually virtual/cal4.virtual):
 *
 *     virtual-name <TAB> real-file <TAB> type <TAB> event-number
 *
 * Same rules as calendar-core 4.6: # comments and blank lines are skipped,
 * tab-separated lines may contain spaces, other lines are split on whitespace.
 */
final class VirtualFiles
{
    /**
     * @param list<array{type: string, number: int, name: string, real: string}> $entries in file order
     * @param list<Problem> $problems
     */
    public function __construct(public readonly array $entries, public readonly array $problems)
    {
    }

    public static function read(?string $path, string $courseDir, string $label): self
    {
        if ($path === null) {
            return new self([], []);
        }
        $full = str_starts_with($path, '/') ? $path : $courseDir . '/' . $path;
        if (!file_exists($full)) {
            return new self([], []);
        }
        $lines = @file($full);
        if ($lines === false) {
            return new self([], [new Problem('the virtual file can not be read', $label)]);
        }
        $entries = [];
        $problems = [];
        foreach ($lines as $index => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $fields = str_contains($line, "\t")
                ? array_values(array_filter(array_map('trim', (array) preg_split('/\t+/', $line)), 'strlen'))
                : (array) preg_split('/\s+/', $line);
            if (count($fields) === 4 && preg_match('/^[1-9][0-9]*$/', (string) $fields[3])) {
                $entries[] = [
                    'type' => Values::normalizeType((string) $fields[2]),
                    'number' => (int) $fields[3],
                    'name' => (string) $fields[0],
                    'real' => (string) $fields[1],
                ];
            } else {
                $problems[] = new Problem('ignored (expected: virtual-name, real-file, type, event-number): ' . $line, $label, $index + 1);
            }
        }
        return new self($entries, $problems);
    }
}
