<?php

declare(strict_types=1);

namespace Cal5\Tests;

final class Response
{
    /** @param list<string> $headers raw header lines (status line first) */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /** First value of a header (case-insensitive), or null. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strcasecmp(trim($parts[0]), $name) === 0) {
                return trim($parts[1]);
            }
        }
        return null;
    }

    /** @return list<string> all values of a header */
    public function headerValues(string $name): array
    {
        $values = [];
        foreach ($this->headers as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strcasecmp(trim($parts[0]), $name) === 0) {
                $values[] = trim($parts[1]);
            }
        }
        return $values;
    }

    /** @return list<string> PHP warnings, notices, deprecations and errors printed in the body */
    public function phpErrors(): array
    {
        // The whole message up to the end of its line (callers shorten it after removing paths)
        $pattern = '#(?:<b>)?(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught [A-Za-z\\\\]+)(?:</b>)?:\s*([^\r\n]{0,1000}?)(?:<br\s*/?>|\r|\n|$)#';
        if (!preg_match_all($pattern, $this->body, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $errors = [];
        foreach ($matches as $m) {
            $errors[] = $m[1] . ': ' . trim(strip_tags($m[2]));
        }
        return $errors;
    }
}
