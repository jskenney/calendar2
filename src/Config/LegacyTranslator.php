<?php

declare(strict_types=1);

namespace Cal5\Config;

use InvalidArgumentException;

/**
 * Builds a Config from the variables a v4 calendar.php sets
 * (mapping table: docs/design/configuration.md).
 *
 * The goal is identical behaviour, so v4 quirks are kept on purpose:
 * a box is filled by the file whose name sorts first (box_pick = first-by-name),
 * <postmeeting> is left alone unless $REVEALHOUR is set, and the legacy CSS and
 * JavaScript v4 loaded on every page stay on.
 */
final class LegacyTranslator
{
    public const FILE = 'calendar.php';

    /** v4 $CALENDAR_TOOL values */
    private const VIEWS = [
        'calendar_display' => 'month',
        'calendar_display_alt' => 'list',
        'calendar_display_dateless' => 'agenda',
    ];

    /** v4 category arrays in the order calendar-core 4.6 builds them (a later array wins a file name) */
    private const CATEGORY_ORDER = ['MD', 'HTML', 'LINK', 'PDF', 'PPT', 'SRC'];

    /** @var list<Problem> */
    private array $errors = [];
    /** @var list<Problem> */
    private array $warnings = [];
    /** @var array<string, mixed> */
    private array $v = [];

    /** @param array<string, mixed> $globals every global variable (v4 settings are picked out) */
    public static function translate(array $globals): LoadResult
    {
        return (new self())->run($globals);
    }

    /** @param array<string, mixed> $globals */
    private function run(array $globals): LoadResult
    {
        $this->v = LegacyVariables::extract($globals);
        $this->errors = [];
        $this->warnings = [];
        foreach (LegacyVariables::unknown($globals) as $name) {
            $this->warn("\$$name is not a calendar setting and is ignored");
        }

        $number = $this->str('COURSE');
        if ($number === '') {
            $this->error('$COURSE is required');
        }

        $course = new CourseSettings(
            number: $number,
            name: $this->str('COURSENAME'),
            fullName: $this->str('COURSENAMEFULL'),
            title: $this->str('PAGE_TITLE') !== '' ? $this->str('PAGE_TITLE') : $number,
            timezone: date_default_timezone_get(),
        );

        $schedule = new ScheduleSettings($this->weekly(), $this->days(), $this->combine());
        [$boxes, $calendarBoxes] = $this->boxes();

        $config = new Config(
            course: $course,
            semester: $this->semester(),
            schedule: $schedule,
            boxes: $boxes,
            menus: $this->menus('NAVBAR_DROPDOWNS') ?? Defaults::menus(),
            instructorMenus: $this->menus('NAVBAR_DROPDOWNS_INSTRUCTOR'),
            replace: $this->replace(),
            files: new FileSettings(
                access: $this->optionalStr('ACCESS_FILE'),
                virtual: $this->optionalStr('CLASS_FILE'),
                loadPages: array_values(array_unique(array_merge(Defaults::LOAD_PAGES, $this->strList('LOAD_PAGES')))),
                allowedPaths: $this->strList('ALLOWED_PATHS'),
            ),
            security: new SecuritySettings(
                passwordHash: null,
                password: $this->str('ADMIN') !== '' ? $this->str('ADMIN') : null,
                secret: $this->str('SECRET'),
                sessionSeconds: $this->sessionSeconds(),
                lockAlways: ($this->v['LOCK'] ?? null) === true,
                lockFile: is_string($this->v['LOCK'] ?? null) && $this->v['LOCK'] !== '' ? $this->v['LOCK'] : null,
            ),
            display: new DisplaySettings(
                startPage: !empty($this->v['DEFAULT_TODAYS_LECTURE']) ? 'today' : 'home',
                calendarView: $this->calendarView(),
                markdownMenuLevels: $this->markdownLevels(),
                headingWarnings: !array_key_exists('IGNORE_A_TAG_WARNING', $this->v),
                boxPick: 'first-by-name',
                calendarBoxes: $calendarBoxes,
            ),
            style: $this->style(),
            features: new FeatureSettings(
                postmeeting: $this->postmeeting(),
                accessLog: $this->optionalStr('ACCESS_LOG'),
                legacyCss: true,
                legacyJs: Defaults::LEGACY_JS,
                debug: ($this->v['DEBUG_MODE'] ?? null) === true,
            ),
        );

        $checks = ConfigChecks::check($config, self::FILE);
        $errors = array_merge($this->errors, $checks['errors']);
        $warnings = array_merge($this->warnings, $checks['warnings']);
        // a configuration that was read but fails a check can still show the error page (v4)
        $draft = $errors !== [] && $this->errors === [] ? $config->withSecret('cal5-refused-' . bin2hex(random_bytes(16))) : null;
        return new LoadResult(ConfigSource::Legacy, $errors === [] ? $config : null, $errors, $warnings, $draft);
    }

    // ------------------------------------------------------------ settings

    private function semester(): SemesterSettings
    {
        $year = $this->int('YEAR');
        $monthStart = $this->int('MONTH_START');
        $monthEnd = $this->int('MONTH_END');
        $dayStart = $this->int('DAY_START');
        if ($dayStart === null) {
            $dayStart = 1;
            $this->warn('$DAY_START is not set; the semester starts on the 1st of $MONTH_START');
        }
        $start = $end = '1970-01-01';
        if ($year === null || $monthStart === null || $monthEnd === null) {
            $this->error('$YEAR, $MONTH_START and $MONTH_END are required');
        } elseif (!checkdate($monthStart, $dayStart, $year) || $monthEnd < 1 || $monthEnd > 12) {
            $this->error("the semester dates are not valid ({$year}-{$monthStart}-{$dayStart} to month {$monthEnd})");
        } else {
            $start = sprintf('%04d-%02d-%02d', $year, $monthStart, $dayStart);
            $end = Values::lastDayOfMonth($year, $monthEnd); // v4 shows whole months
        }

        $showFrom = $showUntil = null;
        if (($this->v['HIDE_WEEKS'] ?? null) === true && $year !== null && $monthStart !== null && $monthEnd !== null) {
            $from = $this->int('HIDE_BEGINNING_DAY');
            $until = $this->int('HIDE_ENDING_DAY');
            if ($from !== null && checkdate($monthStart, $from, $year)) {
                $showFrom = sprintf('%04d-%02d-%02d', $year, $monthStart, $from);
            }
            if ($until !== null && checkdate($monthEnd, $until, $year)) {
                $showUntil = sprintf('%04d-%02d-%02d', $year, $monthEnd, $until);
            }
        }

        return new SemesterSettings($start, $end, (bool) ($this->v['WEEKENDS'] ?? false), $showFrom, $showUntil);
    }

    /** @return array<string, DayPlan> */
    private function weekly(): array
    {
        $weekly = [];
        foreach ($this->arr('DOW') as $day => $label) {
            // v4 only matched the lower-case three-letter names
            if (!is_string($day) || !in_array($day, Values::WEEKDAYS, true)) {
                $this->warn("\$DOW entry \"$day\" is ignored (v4 only uses mon, tue, wed, thu, fri, sat, sun)");
                continue;
            }
            $plan = $this->plan($label, "\$DOW['$day']");
            if ($plan !== null) {
                $weekly[$day] = $plan;
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
        $year = $this->int('YEAR');
        $days = [];
        if ($year === null) {
            return [];
        }
        foreach ($this->arr('OVERRIDE') as $month => $entries) {
            if (!is_array($entries)) {
                $this->warn("\$OVERRIDE[$month] is not an array and is ignored");
                continue;
            }
            foreach ($entries as $day => $label) {
                if (!is_numeric($month) || !is_numeric($day) || !checkdate((int) $month, (int) $day, $year)) {
                    $this->warn("\$OVERRIDE[$month][$day] is not a real date in $year and is ignored");
                    continue;
                }
                $plan = $this->plan($label, "\$OVERRIDE[$month][$day]");
                if ($plan !== null) {
                    $days[sprintf('%04d-%02d-%02d', $year, (int) $month, (int) $day)] = $plan;
                }
            }
        }
        ksort($days);
        return $days;
    }

    /** @return list<CombineRule> */
    private function combine(): array
    {
        $rules = [];
        foreach ($this->arr('COMBINE') as $type => $set) {
            if (!is_array($set)) {
                continue;
            }
            foreach ($set as $number => $inside) {
                if (!is_numeric($number) || (int) $number < 1 || !is_array($inside)) {
                    $this->warn("\$COMBINE['$type'][$number] is ignored");
                    continue;
                }
                $add = [];
                foreach ($inside as $t) {
                    $add[] = Values::normalizeType((string) $t);
                }
                $rules[] = new CombineRule(Values::normalizeType((string) $type), (int) $number, $add);
            }
        }
        return $rules;
    }

    /**
     * v4: each file name belongs to one box (a later category array wins); every
     * box listed in any array exists, $BOX only decides what the calendar shows.
     *
     * @return array{0: list<Box>, 1: list<string>|null}
     */
    private function boxes(): array
    {
        $md = $this->v['MD'] ?? null;
        if (!is_array($md)) {
            // calendar-core 4.6: every .html name in $HTML also accepts a .md twin
            $md = [];
            foreach ($this->arr('HTML') as $box => $file) {
                if (is_string($file) && preg_match('/\.html?$/i', $file)) {
                    $md[$box] = (string) preg_replace('/\.html?$/i', '.md', $file);
                }
            }
        }

        $fileToBox = []; // file => [box, inline]
        foreach (self::CATEGORY_ORDER as $category) {
            $values = $category === 'MD' ? $md : $this->arr($category);
            foreach ($values as $box => $file) {
                if (!is_string($file) || $file === '') {
                    continue;
                }
                $fileToBox[$file] = [(string) $box, $category === 'SRC'];
            }
        }

        $byBox = [];
        foreach ($fileToBox as $file => [$box, $inline]) {
            $byBox[$box][] = new BoxCandidate((string) $file, $inline);
        }

        $calendarOrder = [];
        foreach ($this->arr('BOX') as $name) {
            if (is_string($name) && $name !== '' && !in_array($name, $calendarOrder, true)) {
                $calendarOrder[] = $name;
            }
        }

        // Boxes on the calendar first (in $BOX order), then the others
        $boxes = [];
        foreach ($calendarOrder as $name) {
            if (isset($byBox[$name])) {
                $boxes[] = new Box($name, $byBox[$name]);
            }
        }
        foreach ($byBox as $name => $candidates) {
            if (!in_array((string) $name, $calendarOrder, true)) {
                $boxes[] = new Box((string) $name, $candidates);
            }
        }

        $shown = array_values(array_filter($calendarOrder, static fn (string $n): bool => isset($byBox[$n])));
        $all = array_map(static fn (Box $b): string => $b->name, $boxes);
        return [$boxes, $shown === $all ? null : $shown];
    }

    /** @return list<MenuItem>|null */
    private function menus(string $variable): ?array
    {
        if (!array_key_exists($variable, $this->v)) {
            return null;
        }
        $items = [];
        foreach ($this->arr($variable) as $type => $icon) {
            $type = Values::normalizeType((string) $type);
            $icon = is_string($icon) ? (string) preg_replace('/^glyphicon-/', '', trim($icon)) : '';
            $items[] = new MenuItem($type, Values::menuLabel($type), $icon);
        }
        return $items;
    }

    /** @return array<string, string> */
    private function replace(): array
    {
        $replace = [];
        foreach ($this->arr('PAGE_MODIFY') as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replace[(string) $key] = (string) $value;
            } else {
                $this->warn("\$PAGE_MODIFY['$key'] is not text and is ignored");
            }
        }
        return $replace;
    }

    private function style(): StyleSettings
    {
        $byType = function (string $variable): array {
            $result = [];
            foreach ($this->arr($variable) as $type => $files) {
                $result[(string) $type] = array_values(array_map('strval', is_array($files) ? $files : [$files]));
            }
            return $result;
        };
        $calendar = $this->str('CUSTOMCSS_CAL');
        return new StyleSettings([], $byType('CUSTOMCSS'), $calendar !== '' ? [$calendar] : [], [], $byType('CUSTOMJS'));
    }

    private function calendarView(): string
    {
        $tool = $this->str('CALENDAR_TOOL');
        if ($tool === '') {
            return 'month';
        }
        if (!isset(self::VIEWS[$tool])) {
            $this->warn("\$CALENDAR_TOOL \"$tool\" is not one of calendar_display, calendar_display_alt, calendar_display_dateless; the month view is used");
            return 'month';
        }
        return self::VIEWS[$tool];
    }

    /** @return list<int> */
    private function markdownLevels(): array
    {
        if (!array_key_exists('MARKDOWN_MENU_LEVELS', $this->v)) {
            return [2];
        }
        $levels = [];
        foreach ($this->arr('MARKDOWN_MENU_LEVELS') as $level) {
            if (is_numeric($level) && (int) $level >= 1 && (int) $level <= 6) {
                $levels[] = (int) $level;
            }
        }
        return $levels;
    }

    private function postmeeting(): string
    {
        if (!array_key_exists('REVEALHOUR', $this->v)) {
            return 'off'; // v4 leaves <postmeeting> content visible without $REVEALHOUR
        }
        $hour = is_numeric($this->v['REVEALHOUR']) ? (int) $this->v['REVEALHOUR'] : 0;
        return sprintf('%02d:00', max(0, min(24, $hour)));
    }

    private function sessionSeconds(): int
    {
        $seconds = $this->int('LOGON_TIME');
        return ($seconds !== null && $seconds > 0) ? $seconds : Defaults::SESSION_SECONDS;
    }

    private function plan(mixed $label, string $where): ?DayPlan
    {
        if (!is_string($label) || trim($label) === '') {
            $this->warn("$where is empty and is ignored");
            return null;
        }
        $type = Values::normalizeType($label);
        if (!Values::isValidType($type)) {
            $this->warn("$where (\"$label\") can not be used as a type and is ignored");
            return null;
        }
        return new DayPlan([new DayEntry($type, trim($label))]);
    }

    // ------------------------------------------------------------ helpers

    private function str(string $name): string
    {
        $value = $this->v[$name] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function optionalStr(string $name): ?string
    {
        $value = $this->str($name);
        return $value === '' ? null : $value;
    }

    private function int(string $name): ?int
    {
        $value = $this->v[$name] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<mixed> */
    private function arr(string $name): array
    {
        $value = $this->v[$name] ?? [];
        if (!is_array($value)) {
            $this->warn("\$$name should be an array and is ignored");
            return [];
        }
        return $value;
    }

    /** @return list<string> */
    private function strList(string $name): array
    {
        return array_values(array_map('strval', array_filter($this->arr($name), 'is_scalar')));
    }

    private function error(string $message): void
    {
        $this->errors[] = new Problem($message, self::FILE);
    }

    private function warn(string $message): void
    {
        $this->warnings[] = new Problem($message, self::FILE);
    }
}
