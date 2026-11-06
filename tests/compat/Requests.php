<?php

declare(strict_types=1);

namespace Cal5\Tests\Compat;

use Cal5\Tests\Fixture;

/**
 * Builds the list of requests the compatibility runner sends for a fixture.
 *
 * The list is generated from the fixture's files (not from either
 * implementation), so v4 and Cal5 always get exactly the same requests.
 */
final class Requests
{
    public const ROLES = ['student', 'instructor'];

    /** @return list<array{id: string, role: string, entry: string, query: string}> */
    public static function forFixture(Fixture $fixture): array
    {
        $meta = $fixture->meta;
        $entry = (string) $meta['entry'];
        $queries = [''];
        foreach ($meta['pages'] ?? [] as $page) {
            $queries[] = 'load=' . rawurlencode((string) $page);
        }
        foreach ($meta['views'] ?? [] as $view) {
            $queries[] = 'show=' . rawurlencode((string) $view);
        }
        foreach ($meta['searches'] ?? [] as $term) {
            $queries[] = 'show=calendar_search&search=' . rawurlencode((string) $term);
        }

        $secret = (string) $meta['secret'];
        foreach ($meta['types'] ?? [] as $type) {
            $events = self::eventDirectories($fixture->sourceDir() . '/' . $type);
            // One past the last event checks the "no such event" behaviour
            for ($n = 1; $n <= count($events) + 1; $n++) {
                $queries[] = "type={$type}&event={$n}";
            }
            foreach ($events as $index => $dir) {
                $n = $index + 1;
                foreach (self::files($fixture->sourceDir() . "/$type/$dir") as $file) {
                    $queries[] = 'key=' . sha1($secret . $type . $dir . $file) . "&type={$type}&event={$n}";
                }
            }
            foreach ($meta['links'] ?? [] as $link => $target) {
                // install-time links (e.g. a symlink pointing outside the course)
                $parts = explode('/', (string) $link, 3);
                if (count($parts) === 3 && $parts[0] === $type && ($i = array_search($parts[1], $events, true)) !== false) {
                    $queries[] = 'key=' . sha1($secret . $type . $parts[1] . $parts[2]) . "&type={$type}&event=" . ($i + 1);
                }
            }
        }
        foreach (self::virtualFiles($fixture) as [$type, $event, $virtual, $real]) {
            $queries[] = 'key=' . sha1($secret . $real . $virtual) . "&type={$type}&event={$event}";
        }

        $requests = [];
        foreach (self::ROLES as $role) {
            foreach ($queries as $query) {
                $requests[] = self::make($role, $entry, $query);
            }
            // Extra requests go last: some of them change session state (lock codes, <url> values)
            foreach ($meta['extra_requests'] ?? [] as $extraEntry => $extraQueries) {
                foreach ($extraQueries as $query) {
                    $requests[] = self::make($role, (string) $extraEntry, (string) $query);
                }
            }
        }
        return $requests;
    }

    /** Parameters that send a request somewhere other than an event page. */
    private const NOT_EVENT = ['show', 'load', 'search', 'keyword', 'navbaronly', 'debug', 'edit', 'editor'];

    /** v4's calendar views (?show=). */
    public const CALENDAR_VIEWS = ['calendar_display', 'calendar_display_alt', 'calendar_display_dateless'];

    /**
     * Is the request part of a scope?
     *
     *  - all:    every request
     *  - events: event pages and their files (?type=T&event=N[&key=K]) with a valid
     *            type and event number, on an entry whose configuration works (the
     *            fixture's "error_entries" show v4's error page instead)
     *
     * @param array{entry: string, query: string} $request
     */
    public static function inScope(array $request, string $scope, Fixture $fixture): bool
    {
        if ($scope === 'all') {
            return true;
        }
        if (!in_array($scope, ['events', 'calendars'], true)) {
            throw new \InvalidArgumentException("Unknown scope: $scope");
        }
        if (in_array($request['entry'], $fixture->meta['error_entries'] ?? [], true)) {
            return false;
        }
        parse_str($request['query'], $params);
        if ($scope === 'calendars') {
            // the calendar views, on their own (?show= together with an event is milestone 9)
            return in_array($params['show'] ?? null, self::CALENDAR_VIEWS, true)
                && array_diff(array_keys($params), ['show', 'print', 'answers', 'lock']) === [];
        }
        foreach (self::NOT_EVENT as $name) {
            if (array_key_exists($name, $params)) {
                return false;
            }
        }
        $type = $params['type'] ?? null;
        $event = $params['event'] ?? null;
        return is_string($type) && in_array($type, $fixture->meta['types'] ?? [], true)
            && is_string($event) && preg_match('/^[1-9][0-9]{0,5}$/', $event) === 1;
    }

    /** @return array{id: string, role: string, entry: string, query: string} */
    private static function make(string $role, string $entry, string $query): array
    {
        return ['id' => "$role $entry?$query", 'role' => $role, 'entry' => $entry, 'query' => $query];
    }

    /** Event directories of a type, in v4 order (natural sort, no hidden names). @return list<string> */
    public static function eventDirectories(string $typeDir): array
    {
        if (!is_dir($typeDir)) {
            return [];
        }
        $dirs = [];
        foreach (scandir($typeDir) ?: [] as $entry) {
            if ($entry[0] !== '.' && is_dir("$typeDir/$entry")) {
                $dirs[] = $entry;
            }
        }
        sort($dirs, SORT_NATURAL);
        return $dirs;
    }

    /** Every file below an event directory, as relative paths (hidden ones included). @return list<string> */
    public static function files(string $eventDir, string $prefix = ''): array
    {
        $files = [];
        foreach (scandir($eventDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$eventDir/$entry";
            if (is_dir($path) && !is_link($path)) {
                $files = array_merge($files, self::files($path, $prefix . $entry . '/'));
            } else {
                $files[] = $prefix . $entry;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Virtual file lines from the fixture's virtual file ("files.virtual" in fixture.json,
     * default virtual/cal4.virtual).
     *
     * @return list<array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function virtualFiles(Fixture $fixture): array
    {
        $file = $fixture->sourceDir() . '/' . ($fixture->meta['virtual_file'] ?? 'virtual/cal4.virtual');
        if (!is_file($file)) {
            return [];
        }
        $result = [];
        foreach (file($file) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $fields = str_contains($line, "\t") ? preg_split('/\t+/', $line) : preg_split('/\s+/', $line);
            if ($fields !== false && count($fields) === 4 && ctype_digit($fields[3])) {
                $result[] = [$fields[2], (int) $fields[3], $fields[0], $fields[1]];
            }
        }
        return $result;
    }
}
