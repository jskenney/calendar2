<?php

declare(strict_types=1);

namespace Cal5\Compare;

/**
 * Differences between v4 and Cal5 that were decided on (docs/design/compatibility.md,
 * "Intended differences").  Each rule turns the v4 observation into what Cal5 is
 * meant to answer instead, so the comparison stays strict about everything else.
 * Used by the compatibility suite (tests/compat) and by bin/cal5 compare.
 */
final class IntendedDifferences
{
    /** Cal5 sends audio and video inline with their real type (v4: an octet-stream attachment). */
    private const MEDIA = ['video/mp4', 'video/webm', 'video/ogg', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav'];

    /**
     * @param array<string, mixed> $want the v4 observation
     * @param array<string, mixed> $got the Cal5 observation
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the pair to compare
     */
    public static function apply(array $want, array $got): array
    {
        if (($want['kind'] ?? '') === 'download' && ($got['kind'] ?? '') === 'page'
            && ($want['content_type'] ?? '') === 'text/plain' && (isset($want['markers']) || isset($want['text']))) {
            // .txt files are shown as a page in the layout (v4 sent them as text/plain):
            // the same text must be there (markers in the test suite, the text in bin/cal5 compare)
            $field = isset($want['markers']) ? 'markers' : 'text';
            return [
                ['status' => $want['status'], $field => $want[$field]],
                ['status' => $got['status'], $field => $got[$field] ?? null, 'php_errors' => $got['php_errors'] ?? []],
            ];
        }
        if (($want['kind'] ?? '') === 'page' && isset($want['event_links'])) {
            // v4's month view linked the instructor's day numbers even on days without
            // material (?navbaronly=1&type=holiday&event=, a dead link); Cal5 does not
            $want['event_links'] = array_values(array_filter($want['event_links'], static fn (string $link): bool => str_contains($link, '&event=')));
        }
        if (($want['kind'] ?? '') === 'download' && ($got['kind'] ?? '') === 'download') {
            if ($want['content_type'] === 'image/jpg') {
                $want['content_type'] = 'image/jpeg'; // the registered type
            }
            if ($want['content_type'] === 'application/octet-stream' && in_array($got['content_type'], self::MEDIA, true)) {
                $want['content_type'] = $got['content_type'];
                $want['disposition'] = 'inline';
            }
        }
        return [$want, $got];
    }
}
