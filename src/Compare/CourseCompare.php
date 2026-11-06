<?php

declare(strict_types=1);

namespace Cal5\Compare;

use Cal5\Config\ConfigLoader;
use Cal5\Config\LegacyCapture;
use Cal5\Course;
use RuntimeException;

/**
 * bin/cal5 compare (milestone 10): serves a copy of a v4 course with v4 and another copy
 * with Cal5, side by side, and crawls both as a student and as the instructor.
 *
 * It starts from the start page, the ?load= pages, the calendar views, search, every event
 * and every file key of the course (hidden ones too, so release rules are checked where
 * files are served), and follows every link either implementation shows.  Each address is
 * requested from both, and the answers are compared (Observation): status, page or file,
 * the keyed and event links on pages, the text of the course content, and for files their
 * type, disposition and bytes.  The decided differences (IntendedDifferences) are applied.
 *
 * The course itself is never changed: both copies live in a work directory (large files
 * are hard-linked when possible) that is removed afterwards.
 */
final class CourseCompare
{
    /** Parameters not followed: they change state (login, lock codes) or only change the look. */
    private const SKIP = ['password', 'lock', 'as', 'debug', 'edit', 'editor', 'csrf', 'print', 'nocss'];
    private const LARGE = 5_000_000;

    private string $work;
    private bool $refused = false;
    /** @var list<array<string, mixed>> */
    private array $differences = [];
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private readonly string $courseDir,
        private readonly string $v4Dir,
        private readonly string $cal5Dir,
        private readonly ?string $password = null,
        private readonly int $max = 3000,
        private readonly bool $keep = false,
    ) {
    }

    /** @return array{course: string, entry: string, roles: array<string, array<string, int>>, differences: list<array<string, mixed>>, work: ?string} */
    public function run(): array
    {
        $entry = 'calendar.php';
        if (!is_file($this->courseDir . '/' . $entry)) {
            throw new RuntimeException('compare needs a v4 course (calendar.php); this course has none');
        }
        if (!is_file($this->v4Dir . '/calendar_main.php') || is_file($this->v4Dir . '/src/App.php')) {
            throw new RuntimeException("{$this->v4Dir} is not a v4 calendar-core checkout");
        }
        $this->work = sys_get_temp_dir() . '/cal5-compare-' . bin2hex(random_bytes(4));
        $servers = [];
        try {
            $copies = [];
            foreach (['v4' => $this->v4Dir, 'cal5' => $this->cal5Dir] as $name => $library) {
                $copies[$name] = "{$this->work}/$name/course";
                $this->copyTree($this->courseDir, $copies[$name], true);
                symlink((string) realpath($library), "{$copies[$name]}/calendar");
            }
            // the settings, read through the Cal5 copy (calendar.php loads calendar/calendar_main.php)
            $result = ConfigLoader::forCourse($copies['cal5'], LegacyCapture::run($copies['cal5'] . '/' . $entry));
            $config = $result->config ?? $result->draft
                ?? throw new RuntimeException('Cal5 can not read this course\'s configuration (run: php calendar/bin/cal5 check)');
            // a refused configuration (the published sample secret, ...) keeps no login, in v4 either
            $password = $result->config === null ? null : ($this->password ?? $config->security->password);
            $this->refused = $result->config === null;
            $course = Course::load($copies['cal5'], $config);
            foreach ($copies as $name => $copy) {
                $log = $config->features->accessLog;
                if ($log !== null && !str_starts_with($log, '/') && is_file("$copy/$log")) {
                    unlink("$copy/$log");
                    touch("$copy/$log"); // the copy's own empty log, never the course's
                }
                $servers[$name] = new PhpServer($copy, "{$this->work}/$name", $name === 'cal5');
            }
            $seeds = $this->seeds($course, $config->files->loadPages);
            $roles = [];
            foreach (['student' => null, 'instructor' => $password] as $role => $login) {
                if ($role === 'instructor' && ($login === null || $login === '')) {
                    continue;
                }
                $roles[$role] = $this->crawl($role, $servers['v4'], $servers['cal5'], $entry, $seeds, $login);
            }
        } finally {
            foreach ($servers as $server) {
                $server->stop();
            }
            if (!$this->keep) {
                self::remove($this->work);
            }
        }
        return ['course' => (string) realpath($this->courseDir), 'entry' => $entry, 'roles' => $roles, 'refused' => $this->refused,
            'differences' => $this->differences, 'work' => $this->keep ? $this->work : null];
    }

    /**
     * @param list<string> $loadPages
     * @return list<string>
     */
    private function seeds(Course $course, array $loadPages): array
    {
        $seeds = ['', 'show=calendar_display', 'show=calendar_display_alt', 'show=calendar_display_dateless',
            'show=calendar_search&search=the', 'show=calendar_keyword', 'show=calendar_file_selector'];
        foreach (array_merge($loadPages, ['instructor']) as $page) {
            $seeds[] = 'load=' . rawurlencode($page);
        }
        foreach ($course->content->types as $type => $events) {
            foreach ($events as $event) {
                $seeds[] = 'type=' . rawurlencode($type) . '&event=' . $event->number;
                foreach ($event->files as $file) {
                    $seeds[] = 'key=' . $file->key . '&type=' . rawurlencode($type) . '&event=' . $event->number;
                }
            }
        }
        return $seeds;
    }

    /**
     * @param list<string> $seeds
     * @return array<string, int>
     */
    private function crawl(string $role, PhpServer $v4, PhpServer $cal5, string $entry, array $seeds, ?string $password): array
    {
        $old = new HttpSession($v4);
        $new = new HttpSession($cal5);
        if ($password !== null) {
            $old->get($entry); // the nonce cookie first, as a browser has it
            $new->get($entry);
            $old->get("$entry?password=" . rawurlencode($password));
            $new->get("$entry?password=" . rawurlencode($password));
            if (!str_contains($new->get($entry)['body'], 'data-instructor="yes"')) {
                throw new RuntimeException('could not log in to Cal5 as the instructor (use --password)');
            }
        }
        $queue = array_values(array_unique(array_map(static fn (string $q): string => self::normalize($q) ?? $q, $seeds)));
        $seen = array_fill_keys($queue, true);
        $stats = ['requests' => 0, 'match' => 0, 'differ' => 0, 'pages' => 0, 'files' => 0];
        while ($queue !== [] && $stats['requests'] < $this->max) {
            $query = array_shift($queue);
            $path = $entry . ($query === '' ? '' : '?' . $query);
            $a = $old->get($path);
            $b = $new->get($path);
            $stats['requests']++;
            parse_str($query, $params);
            // the text is compared on content pages; Cal5's own pages (the guide, the error page) are different on purpose
            $content = ($query === '' || isset($params['load']) || (isset($params['type'], $params['event']) && !isset($params['show']) && !isset($params['navbaronly'])))
                && !in_array($params['load'] ?? '', ['instructor', 'error'], true)
                && !$this->refused; // a refused configuration: both show their error page, worded differently
            $want = Observation::of($a, $entry, $content, true);
            $got = Observation::of($b, $entry, $content, false);
            $stats[$got['kind'] === 'page' ? 'pages' : 'files']++;
            $problems = $this->compare($want, $got);
            if (preg_match('/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>:/', $b['body'])) {
                $problems[] = ['field' => 'php errors', 'v4' => '', 'cal5' => 'PHP warnings on the page'];
            }
            if ($problems === []) {
                $stats['match']++;
            } else {
                $stats['differ']++;
                $this->differences[] = ['role' => $role, 'url' => $path, 'problems' => $problems];
            }
            if ($got['kind'] === 'page' || $want['kind'] === 'page') {
                foreach (array_merge(Observation::links($a['body'], $entry)['all'], Observation::links($b['body'], $entry)['all']) as $link) {
                    $link = self::normalize($link);
                    if ($link !== null && !isset($seen[$link])) {
                        $seen[$link] = true;
                        $queue[] = $link;
                    }
                }
            }
        }
        $stats['not visited'] = count($queue);
        return $stats;
    }

    /**
     * @param array<string, mixed> $want
     * @param array<string, mixed> $got
     * @return list<array{field: string, v4: string, cal5: string}>
     */
    private function compare(array $want, array $got): array
    {
        [$want, $got] = IntendedDifferences::apply($want, $got);
        $problems = [];
        foreach (array_unique(array_merge(array_keys($want), array_keys($got))) as $field) {
            if ($field === 'php_errors') {
                continue;
            }
            $a = $want[$field] ?? null;
            $b = $got[$field] ?? null;
            if ($a === $b) {
                continue;
            }
            if ($field === 'text' && is_string($a) && is_string($b)) {
                [$a, $b] = self::firstDifference($a, $b);
            } elseif (is_array($a) || is_array($b)) {
                $missing = array_values(array_diff((array) $a, (array) $b));
                $extra = array_values(array_diff((array) $b, (array) $a));
                [$a, $b] = [$missing === [] ? '' : 'only in v4: ' . implode(', ', array_slice($missing, 0, 5)),
                    $extra === [] ? '' : 'only in Cal5: ' . implode(', ', array_slice($extra, 0, 5))];
            }
            $problems[] = ['field' => $field, 'v4' => self::short($a), 'cal5' => self::short($b)];
        }
        return $problems;
    }

    /** @return array{0: string, 1: string} the two texts around the first word that differs */
    public static function firstDifference(string $a, string $b): array
    {
        $x = explode(' ', $a);
        $y = explode(' ', $b);
        $i = 0;
        while ($i < count($x) && $i < count($y) && $x[$i] === $y[$i]) {
            $i++;
        }
        $from = max(0, $i - 6);
        return ['…' . implode(' ', array_slice($x, $from, 16)) . '…', '…' . implode(' ', array_slice($y, $from, 16)) . '…'];
    }

    private static function short(mixed $value): string
    {
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }

    /** A link worth following, without the parameters that change state or only the look; null to skip. */
    public static function normalize(string $query): ?string
    {
        parse_str($query, $params);
        foreach (self::SKIP as $name) {
            if (array_key_exists($name, $params)) {
                if ($name === 'print' || $name === 'nocss') {
                    unset($params[$name]);
                    continue;
                }
                return null;
            }
        }
        foreach ($params as $value) {
            if (!is_string($value)) {
                return null;
            }
        }
        ksort($params); // the same address however its parameters are ordered
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Copy a course: small files copied, large ones hard-linked when possible; symbolic links kept as they are. */
    private function copyTree(string $from, string $to, bool $top): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ($top && $entry === 'calendar')) {
                continue;
            }
            $source = "$from/$entry";
            $target = "$to/$entry";
            if (is_link($source)) {
                symlink((string) readlink($source), $target);
            } elseif (is_dir($source)) {
                $this->copyTree($source, $target, false);
            } elseif (is_file($source)) {
                if (filesize($source) < self::LARGE || !@link($source, $target)) {
                    copy($source, $target);
                }
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("$path/$entry");
                }
            }
            @rmdir($path);
        }
    }
}
