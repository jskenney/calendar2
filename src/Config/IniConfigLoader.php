<?php

declare(strict_types=1);

namespace Cal5\Config;

use InvalidArgumentException;

/** Builds a Config from calendar.ini.php (format: docs/design/configuration.md). */
final class IniConfigLoader
{
    /** Known keys of the fixed sections. Open sections accept any key. */
    private const KEYS = [
        'course' => ['number', 'name', 'full_name', 'title', 'timezone'],
        'semester' => ['start', 'end', 'weekends', 'show_from', 'show_until'],
        'files' => ['access', 'virtual', 'load_pages', 'allowed_paths'],
        'security' => ['password_hash', 'password', 'secret', 'session_hours', 'lock'],
        'display' => ['start_page', 'calendar_view', 'markdown_menu_levels', 'heading_warnings', 'box_pick', 'calendar_boxes'],
        'features' => ['postmeeting', 'access_log', 'legacy_css', 'legacy_js', 'debug'],
    ];
    private const OPEN_SECTIONS = ['schedule', 'days', 'combine', 'boxes', 'menus', 'instructor_menus', 'replace', 'style'];

    /** @var list<Problem> */
    private array $errors = [];
    /** @var list<Problem> */
    private array $warnings = [];
    private IniDocument $doc;

    public static function fromFile(string $path): LoadResult
    {
        $read = IniReader::readFile($path);
        return (new self())->load($read);
    }

    public static function fromString(string $text, string $file = 'calendar.ini.php'): LoadResult
    {
        return (new self())->load((new IniReader())->parse($text, $file));
    }

    public function load(IniReadResult $read): LoadResult
    {
        $this->doc = $read->document;
        $this->errors = $read->errors;
        $this->warnings = [];
        $this->checkStructure();

        $course = new CourseSettings(
            number: $this->required('course', 'number'),
            name: $this->string('course', 'name'),
            fullName: $this->string('course', 'full_name'),
            title: $this->string('course', 'title') !== '' ? $this->string('course', 'title') : $this->string('course', 'number'),
            timezone: $this->parse('course', 'timezone', [Values::class, 'timezone'], date_default_timezone_get()),
        );

        $start = $this->parse('semester', 'start', [Values::class, 'date'], null, true);
        $end = $this->parse('semester', 'end', [Values::class, 'date'], null, true);
        $semester = new SemesterSettings(
            start: $start ?? '1970-01-01',
            end: $end ?? '1970-01-01',
            weekends: $this->parse('semester', 'weekends', [Values::class, 'bool'], false),
            showFrom: $this->parse('semester', 'show_from', [Values::class, 'date'], null),
            showUntil: $this->parse('semester', 'show_until', [Values::class, 'date'], null),
        );

        $schedule = new ScheduleSettings($this->weekly(), $this->days(), $this->combine());

        $calendarBoxes = $this->doc->get('display', 'calendar_boxes');
        $display = new DisplaySettings(
            startPage: $this->choice('display', 'start_page', DisplaySettings::START_PAGES, 'home'),
            calendarView: $this->choice('display', 'calendar_view', DisplaySettings::CALENDAR_VIEWS, 'month'),
            markdownMenuLevels: $this->parse('display', 'markdown_menu_levels', [self::class, 'levels'], [2]),
            headingWarnings: $this->parse('display', 'heading_warnings', [Values::class, 'bool'], false),
            boxPick: $this->choice('display', 'box_pick', DisplaySettings::BOX_PICKS, 'listed'),
            calendarBoxes: $calendarBoxes !== null ? Values::list($calendarBoxes->value) : null,
        );

        $security = new SecuritySettings(
            passwordHash: $this->optional('security', 'password_hash'),
            password: $this->optional('security', 'password'),
            secret: $this->string('security', 'secret'),
            sessionSeconds: $this->parse('security', 'session_hours', [self::class, 'hoursToSeconds'], Defaults::SESSION_SECONDS),
            lockAlways: $this->lockAlways(),
            lockFile: $this->lockFile(),
        );

        $config = new Config(
            course: $course,
            semester: $semester,
            schedule: $schedule,
            boxes: $this->boxes(),
            menus: $this->menus('menus') ?? Defaults::menus(),
            instructorMenus: $this->menus('instructor_menus'),
            replace: $this->replace(),
            files: new FileSettings(
                access: $this->optional('files', 'access'),
                virtual: $this->optional('files', 'virtual'),
                loadPages: $this->parse('files', 'load_pages', [Values::class, 'list'], Defaults::LOAD_PAGES),
                allowedPaths: $this->parse('files', 'allowed_paths', [Values::class, 'list'], []),
            ),
            security: $security,
            display: $display,
            style: $this->style(),
            features: new FeatureSettings(
                postmeeting: $this->parse('features', 'postmeeting', [Values::class, 'postmeeting'], 'end'),
                accessLog: $this->optional('features', 'access_log'),
                legacyCss: $this->parse('features', 'legacy_css', [Values::class, 'bool'], false),
                legacyJs: $this->parse('features', 'legacy_js', [self::class, 'legacyJs'], []),
                debug: $this->parse('features', 'debug', [Values::class, 'bool'], false),
            ),
        );

        $checks = ConfigChecks::check($config, $this->doc->file);
        $errors = array_merge($this->errors, $checks['errors']);
        $warnings = array_merge($this->warnings, $checks['warnings']);
        // a configuration that was read but fails a check can still show the error page (v4)
        $draft = $errors !== [] && $this->errors === [] ? $config->withSecret('cal5-refused-' . bin2hex(random_bytes(16))) : null;
        return new LoadResult(ConfigSource::Ini, $errors === [] ? $config : null, $errors, $warnings, $draft);
    }

    // ------------------------------------------------------------ sections

    private function checkStructure(): void
    {
        foreach ($this->doc->sectionNames() as $section) {
            if ($section === '') {
                foreach ($this->doc->entries('') as $entry) {
                    $this->warn("\"{$entry->key}\" is not inside a [section] and is ignored", $entry->line);
                }
                continue;
            }
            if (!isset(self::KEYS[$section]) && !in_array($section, self::OPEN_SECTIONS, true)) {
                $this->warn("unknown section [$section] is ignored", $this->doc->sectionLine($section));
                continue;
            }
            if (isset(self::KEYS[$section])) {
                foreach ($this->doc->entries($section) as $entry) {
                    if (!in_array($entry->name(), self::KEYS[$section], true)) {
                        $this->warn("unknown setting \"{$entry->key}\" in [$section] is ignored", $entry->line);
                    }
                }
            }
        }
    }

    /** @return array<string, DayPlan> */
    private function weekly(): array
    {
        $weekly = [];
        foreach ($this->doc->entries('schedule') as $entry) {
            try {
                $day = Values::weekday($entry->key);
                $weekly[$day] = Values::dayPlan($entry->value);
            } catch (InvalidArgumentException $e) {
                $this->error('[schedule] ' . $e->getMessage(), $entry->line);
            }
        }
        $ordered = [];
        foreach (Values::WEEKDAYS as $day) {
            if (isset($weekly[$day])) {
                $ordered[$day] = $weekly[$day];
            }
        }
        return $ordered;
    }

    /** @return array<string, DayPlan> */
    private function days(): array
    {
        $days = [];
        foreach ($this->doc->entries('days') as $entry) {
            try {
                $days[Values::date($entry->key)] = Values::dayPlan($entry->value);
            } catch (InvalidArgumentException $e) {
                $this->error('[days] ' . $e->getMessage(), $entry->line);
            }
        }
        ksort($days);
        return $days;
    }

    /** @return list<CombineRule> */
    private function combine(): array
    {
        $rules = [];
        foreach ($this->doc->entries('combine') as $entry) {
            if (!preg_match('/^(.+?)\s+(\d+)$/', trim($entry->key), $m) || (int) $m[2] < 1) {
                $this->error("[combine] expected \"type number = types\", got \"{$entry->key}\"", $entry->line);
                continue;
            }
            $type = Values::normalizeType($m[1]);
            $add = array_map([Values::class, 'normalizeType'], Values::list($entry->value));
            foreach (array_merge([$type], $add) as $t) {
                if (!Values::isValidType($t)) {
                    $this->error("[combine] \"$t\" can not be used as a type", $entry->line);
                    continue 2;
                }
            }
            $rules[] = new CombineRule($type, (int) $m[2], $add);
        }
        return $rules;
    }

    /** @return list<Box> */
    private function boxes(): array
    {
        if (!$this->doc->hasSection('boxes')) {
            return Defaults::boxes();
        }
        $boxes = [];
        foreach ($this->doc->entries('boxes') as $entry) {
            $candidates = array_map([Values::class, 'candidate'], Values::list($entry->value));
            $candidates = array_values(array_filter($candidates, static fn (BoxCandidate $c): bool => $c->file !== ''));
            if ($candidates === []) {
                $this->warn("[boxes] \"{$entry->key}\" lists no files", $entry->line);
                continue;
            }
            foreach ($candidates as $candidate) {
                if (str_contains($candidate->file, '..') || str_starts_with($candidate->file, '/')) {
                    $this->error("[boxes] \"{$candidate->file}\" must be a file name inside the event directory", $entry->line);
                }
            }
            $boxes[] = new Box($entry->key, $candidates);
        }
        return $boxes;
    }

    /** @return list<MenuItem>|null */
    private function menus(string $section): ?array
    {
        if (!$this->doc->hasSection($section)) {
            return null;
        }
        $items = [];
        foreach ($this->doc->entries($section) as $entry) {
            $type = Values::normalizeType($entry->key);
            if (!Values::isValidType($type)) {
                $this->error("[$section] \"{$entry->key}\" can not be used as a type", $entry->line);
                continue;
            }
            $items[] = Values::menuItem($type, $entry->value);
        }
        return $items;
    }

    /** @return array<string, string> */
    private function replace(): array
    {
        $replace = [];
        foreach ($this->doc->entries('replace') as $entry) {
            $replace[$entry->key] = $entry->value;
        }
        return $replace;
    }

    private function style(): StyleSettings
    {
        $css = $js = $cssCalendar = [];
        $cssByType = $jsByType = [];
        foreach ($this->doc->entries('style') as $entry) {
            $files = Values::list($entry->value);
            $parts = explode('.', $entry->name(), 2);
            $kind = $parts[0];
            $target = $parts[1] ?? '';
            if ($kind !== 'css' && $kind !== 'js') {
                $this->warn("[style] unknown setting \"{$entry->key}\" (use css, css.<type>, css.calendar, js or js.<type>)", $entry->line);
                continue;
            }
            if ($target === '') {
                $kind === 'css' ? array_push($css, ...$files) : array_push($js, ...$files);
            } elseif ($kind === 'css' && $target === 'calendar') {
                array_push($cssCalendar, ...$files);
            } elseif ($kind === 'css') {
                $cssByType[$target] = array_merge($cssByType[$target] ?? [], $files);
            } else {
                $jsByType[$target] = array_merge($jsByType[$target] ?? [], $files);
            }
        }
        return new StyleSettings($css, $cssByType, $cssCalendar, $js, $jsByType);
    }

    private function lockAlways(): bool
    {
        $entry = $this->doc->get('security', 'lock');
        if ($entry === null) {
            return false;
        }
        try {
            return Values::bool($entry->value);
        } catch (InvalidArgumentException) {
            return false; // a file name
        }
    }

    private function lockFile(): ?string
    {
        $entry = $this->doc->get('security', 'lock');
        if ($entry === null) {
            return null;
        }
        try {
            Values::bool($entry->value);
            return null;
        } catch (InvalidArgumentException) {
            return $entry->value;
        }
    }

    // ------------------------------------------------------------ helpers

    private function string(string $section, string $key): string
    {
        return $this->doc->get($section, $key)?->value ?? '';
    }

    private function optional(string $section, string $key): ?string
    {
        $value = $this->doc->get($section, $key)?->value;
        return ($value === null || $value === '') ? null : $value;
    }

    private function required(string $section, string $key): string
    {
        $value = $this->string($section, $key);
        if ($value === '') {
            $this->error("[$section] $key is required", $this->doc->sectionLine($section));
        }
        return $value;
    }

    private function parse(string $section, string $key, callable $parser, mixed $default, bool $required = false): mixed
    {
        $entry = $this->doc->get($section, $key);
        if ($entry === null || $entry->value === '') {
            if ($required) {
                $this->error("[$section] $key is required", $this->doc->sectionLine($section));
            }
            return $default;
        }
        try {
            return $parser($entry->value);
        } catch (InvalidArgumentException $e) {
            $this->error("[$section] $key: " . $e->getMessage(), $entry->line);
            return $default;
        }
    }

    /** @param list<string> $choices */
    private function choice(string $section, string $key, array $choices, string $default): string
    {
        return $this->parse($section, $key, static function (string $value) use ($choices): string {
            $lower = strtolower(trim($value));
            if (!in_array($lower, $choices, true)) {
                throw new InvalidArgumentException('expected one of ' . implode(', ', $choices) . ", got \"$value\"");
            }
            return $lower;
        }, $default);
    }

    /** @return list<int> */
    public static function levels(string $value): array
    {
        $levels = [];
        foreach (Values::list($value) as $item) {
            if (!ctype_digit($item) || (int) $item < 1 || (int) $item > 6) {
                throw new InvalidArgumentException("expected heading levels 1-6, got \"$value\"");
            }
            $levels[] = (int) $item;
        }
        return $levels;
    }

    public static function hoursToSeconds(string $value): int
    {
        if (!is_numeric(trim($value)) || (float) $value <= 0) {
            throw new InvalidArgumentException("expected a number of hours, got \"$value\"");
        }
        return (int) round((float) $value * 3600);
    }

    /** @return list<string> */
    public static function legacyJs(string $value): array
    {
        $items = array_map('strtolower', Values::list($value));
        foreach ($items as $item) {
            if (!in_array($item, FeatureSettings::LEGACY_JS, true)) {
                throw new InvalidArgumentException('expected any of ' . implode(', ', FeatureSettings::LEGACY_JS) . ", got \"$item\"");
            }
        }
        return array_values(array_unique($items));
    }

    private function error(string $message, ?int $line): void
    {
        $this->errors[] = new Problem($message, $this->doc->file, $line);
    }

    private function warn(string $message, ?int $line): void
    {
        $this->warnings[] = new Problem($message, $this->doc->file, $line);
    }
}
