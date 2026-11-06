<?php

declare(strict_types=1);

namespace Cal5\Cli;

use Cal5\Config\ConfigLoader;
use Cal5\Config\ConfigSource;
use Cal5\Config\IniWriter;
use Cal5\Config\LegacyCapture;
use Cal5\Config\LoadResult;
use Cal5\Config\Problem;
use Cal5\Version;
use RuntimeException;

/** The bin/cal5 command line tool. */
final class Cli
{
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;
    /** @var resource */
    private $in;

    /**
     * @param resource|null $out
     * @param resource|null $err
     * @param resource|null $in
     */
    public function __construct($out = null, $err = null, $in = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->in = $in ?? STDIN;
    }

    /** @param list<string> $argv */
    public function main(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        [$options, $arguments] = self::parse(array_slice($argv, 2));
        try {
            return match ($command) {
                'version', '--version' => $this->version(),
                'help', '--help', '-h' => $this->help(),
                'translate' => $this->translate($options, $arguments),
                'check' => $this->check($options),
                'events' => $this->events($options),
                'access' => $this->access($options),
                'hash-password' => $this->hashPassword($options),
                'audit' => $this->audit($options),
                'compare' => $this->compare($options),
                'init' => $this->init($options),
                'build-compat-css' => $this->buildCompatCss(),
                default => $this->fail("Unknown command: $command\nRun: php calendar/bin/cal5 help", 2),
            };
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 1);
        }
    }

    private function version(): int
    {
        $this->say('Cal5 ' . Version::VERSION);
        return 0;
    }

    private function help(): int
    {
        $this->say(<<<TXT
Usage (run in the course directory): php calendar/bin/cal5 <command> [options]

Commands:
  translate [calendar.php] [-o FILE] [--force] [--hash-password]
                 Write calendar.ini.php from a v4 calendar.php.  Once it
                 exists, calendar.ini.php is used instead of calendar.php
                 (delete it to go back).  -o - prints it instead.
                 --hash-password stores the password as a hash.
  check [--json] [--course DIR]
                 Validate the course configuration and summarise it.
  events [--json] [--course DIR]
                 List every event: its directory, calendar date and the
                 file that fills each box (and content problems).
  access [--at "YYYY-MM-DD HH:MM"] [--json] [--course DIR]
                 When students can see each file (the release rule that
                 applies, opening and locking times) and whether they can
                 now, or at the --at time (course time zone).
  hash-password [--stdin]
                 Print a password hash for [security] password_hash.
  audit [--json] [--course DIR]
                 What the course's pages use from v4's front end (Bootstrap
                 and Skeleton classes, glyphicons, jQuery, DataTables,
                 Chart.js, ...), whether Cal5 handles it, and the [features]
                 lines to use.  Exits with 1 if something is not handled.
  version        Print the Cal5 version
  help           Show this help

  init [--number=N --name=NAME --start=YYYY-MM-DD --end=YYYY-MM-DD
        --class-days="mon wed fri" --lab-days=thu --timezone=ZONE
        --password-stdin] [--yes] [--course=DIR]
                 Set up a new course (installer.sh runs this): asks for what
                 is not given, and writes calendar.ini.php (random secret,
                 hashed password), index.php, starter pages, a first class
                 and example release rules.  Never touches an existing course.
  compare [--v4 DIR] [--password P] [--max N] [--json] [--keep] [--course DIR]
                 Serve copies of a v4 course with v4 and with Cal5 side by
                 side, follow every page and file link as a student and as
                 the instructor, and report every difference.  --v4 is a
                 calendar-core (v4) checkout; by default calendar-core-v4/
                 or the course's calendar/ link when it is v4.  The course
                 is not changed.  Exits with 1 if anything differs.
TXT);
        return 0;
    }

    /**
     * @param array<string, string|true> $options
     * @param list<string> $arguments
     */
    private function translate(array $options, array $arguments): int
    {
        $source = $arguments[0] ?? 'calendar.php';
        $result = \Cal5\Config\LegacyTranslator::translate(LegacyCapture::run($source));
        $this->problems($result);
        if (!$result->ok() || $result->config === null) {
            return $this->fail('Not translated: fix the errors above first.', 1);
        }

        $hash = null;
        if (isset($options['hash-password'])) {
            $password = $result->config->security->password;
            if ($password === null) {
                return $this->fail('--hash-password: calendar.php has no $ADMIN password', 1);
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }

        $writer = new IniWriter();
        $ini = $writer->write($result->config, $hash);
        foreach ($writer->problems() as $problem) {
            fwrite($this->err, "warning: $problem\n");
        }

        $target = is_string($options['o'] ?? null) ? (string) $options['o'] : dirname($source) . '/' . ConfigLoader::INI_FILE;
        if ($target === '-') {
            fwrite($this->out, $ini);
            return 0;
        }
        if (is_file($target) && !isset($options['force'])) {
            return $this->fail("$target already exists (use --force to replace it)", 1);
        }
        if (file_put_contents($target, $ini) === false) {
            return $this->fail("Could not write $target", 1);
        }
        @chmod($target, 0640);
        $this->say("Wrote $target.");
        $this->say('The course now uses it instead of calendar.php; delete it to go back.');
        if ($hash === null && $result->config->security->password !== null) {
            $this->say('The password is stored as plain text, as in calendar.php (use --hash-password to store a hash).');
        }
        return 0;
    }

    /** @param array<string, string|true> $options */
    private function check(array $options): int
    {
        $course = is_string($options['course'] ?? null) ? (string) $options['course'] : '.';
        if (!is_dir($course)) {
            return $this->fail("$course is not a directory", 1);
        }
        $globals = [];
        if (is_file($course . '/calendar.php')) {
            if (is_file($course . '/' . ConfigLoader::INI_FILE)) {
                // Only needed for the "calendar.php is ignored" notice
                try {
                    $globals = LegacyCapture::run($course . '/calendar.php');
                } catch (RuntimeException) {
                    $globals = [];
                }
            } else {
                $globals = LegacyCapture::run($course . '/calendar.php');
            }
        }
        $result = ConfigLoader::forCourse($course, $globals);

        if (isset($options['json'])) {
            $data = [
                'source' => $result->source->value,
                'ok' => $result->ok(),
                'errors' => array_map('strval', $result->errors),
                'warnings' => array_map('strval', $result->warnings),
                'config' => $result->config?->toArray(),
            ];
            fwrite($this->out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return $result->ok() ? 0 : 1;
        }

        if ($result->source === ConfigSource::None) {
            return $this->fail('No configuration found (calendar.ini.php or a v4 calendar.php).', 1);
        }
        $this->say('Configuration: ' . ($result->source === ConfigSource::Ini ? 'calendar.ini.php' : 'calendar.php (v4, translated)'));
        $this->problems($result);
        $config = $result->config;
        if ($config !== null) {
            $this->say('Course:    ' . trim($config->course->number . ' ' . $config->course->name) . " ({$config->course->timezone})");
            $this->say("Semester:  {$config->semester->start} to {$config->semester->end}" . ($config->semester->weekends ? ' (with weekends)' : ''));
            foreach ($config->schedule->weekly as $day => $plan) {
                $this->say("Weekly:    $day = $plan");
            }
            foreach ($config->schedule->days as $date => $plan) {
                $this->say("Day:       $date = $plan");
            }
            foreach ($config->boxes as $box) {
                $this->say("Box:       {$box->name} = " . implode(', ', array_map('strval', $box->candidates)));
            }
            $this->say('Login:     ' . ($config->security->loginEnabled()
                ? ($config->security->passwordHash !== null ? 'password hash' : 'plain-text password')
                : 'disabled'));
        }
        $this->say($result->ok() ? 'OK' : 'NOT OK: the course can not run until the errors are fixed.');
        return $result->ok() ? 0 : 1;
    }

    /** @param array<string, string|true> $options */
    private function events(array $options): int
    {
        $courseDir = is_string($options['course'] ?? null) ? (string) $options['course'] : '.';
        $result = $this->loadCourseConfig($courseDir);
        if ($result->config === null) {
            $this->problems($result);
            return $this->fail('The configuration has errors (run: php calendar/bin/cal5 check).', 1);
        }
        $course = \Cal5\Course::load($courseDir, $result->config);

        if (isset($options['json'])) {
            $types = [];
            foreach ($course->content->types as $type => $events) {
                foreach ($events as $event) {
                    $types[$type][] = [
                        'number' => $event->number,
                        'directory' => $event->directory,
                        'name' => $event->name,
                        'date' => $course->schedule->dateOf($type, $event->number),
                        'boxes' => $event->boxes,
                        'files' => array_keys($event->files),
                    ];
                }
            }
            $days = [];
            foreach ($course->schedule->days as $date => $day) {
                foreach ($day->entries as $entry) {
                    $days[$date][] = ['type' => $entry->type, 'label' => $entry->label, 'number' => $entry->number,
                        'meeting' => $entry->meeting !== null ? (string) $entry->meeting : null, 'combined' => $entry->combined];
                }
            }
            $data = ['types' => $types, 'days' => $days, 'problems' => array_map('strval', $course->problems())];
            fwrite($this->out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return 0;
        }

        $unscheduled = [];
        foreach ($course->content->types as $type => $events) {
            foreach ($events as $event) {
                $date = $course->schedule->dateOf($type, $event->number);
                if ($date === null) {
                    $unscheduled[] = "$type {$event->number} ({$event->directory})";
                }
                $boxes = [];
                foreach ($event->boxes as $box => $file) {
                    $boxes[] = "$box=$file";
                }
                $this->say(sprintf('%-12s %3d  %-10s  %-28s %s', $type, $event->number, $date ?? '-', $event->directory, implode(' ', $boxes)));
            }
        }
        if ($unscheduled !== []) {
            $this->say('');
            $this->say('Not on the calendar (students never see these): ' . implode(', ', $unscheduled));
        }
        foreach ($course->problems() as $problem) {
            fwrite($this->err, 'warning: ' . $problem . "\n");
        }
        return 0;
    }

    /** @param array<string, string|true> $options */
    private function access(array $options): int
    {
        $courseDir = is_string($options['course'] ?? null) ? (string) $options['course'] : '.';
        $result = $this->loadCourseConfig($courseDir);
        if ($result->config === null) {
            $this->problems($result);
            return $this->fail('The configuration has errors (run: php calendar/bin/cal5 check).', 1);
        }
        $course = \Cal5\Course::load($courseDir, $result->config);
        $clock = null;
        if (is_string($options['at'] ?? null)) {
            try {
                $clock = \Cal5\Access\FixedClock::at((string) $options['at'], $result->config->course->timezone);
            } catch (\Exception) {
                return $this->fail('--at: expected a time such as "2026-09-14 13:30"', 2);
            }
        }
        $policy = \Cal5\Access\AccessPolicy::forCourse($course, $clock);
        $now = $policy->now();

        $rows = [];
        foreach ($course->content->types as $type => $events) {
            foreach ($events as $event) {
                foreach ($event->files as $name => $file) {
                    $window = $policy->window($event, $file);
                    $rows[] = [
                        'type' => $type,
                        'event' => $event->number,
                        'file' => $name,
                        'box' => $file->box,
                        'rule' => $window->rule !== null ? (string) $window->rule : null,
                        'line' => $window->rule?->line,
                        'opens' => $window->opens()?->format(DATE_ATOM),
                        'locks' => $window->closes()?->format(DATE_ATOM),
                        'window' => $window->describe(),
                        'visible' => $policy->visibleToStudents($event, $file, $now),
                    ];
                }
            }
        }

        if (isset($options['json'])) {
            $data = ['at' => $now->format(DATE_ATOM), 'files' => $rows, 'problems' => array_map('strval', $policy->problems())];
            fwrite($this->out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return 0;
        }
        $this->say('Students, at ' . $now->setTimezone(new \DateTimeZone($result->config->course->timezone))->format('D j M Y H:i T') . ':');
        foreach ($rows as $row) {
            $this->say(sprintf('%-7s %-12s %3d  %-24s %s%s', $row['visible'] ? 'visible' : 'hidden', $row['type'], $row['event'], $row['file'],
                $row['window'], $row['rule'] !== null ? "   [{$row['rule']}]" : ''));
        }
        foreach ($policy->problems() as $problem) {
            fwrite($this->err, 'warning: ' . $problem . "\n");
        }
        return 0;
    }

    /** @param array<string, string|true> $options */
    private function audit(array $options): int
    {
        $courseDir = is_string($options['course'] ?? null) ? (string) $options['course'] : '.';
        $result = $this->loadCourseConfig($courseDir);
        if ($result->config === null) {
            $this->problems($result);
            return $this->fail('The configuration has errors (run: php calendar/bin/cal5 check).', 1);
        }
        $course = \Cal5\Course::load($courseDir, $result->config);
        $report = (new \Cal5\Audit\CourseAudit($course))->run();
        if (isset($options['json'])) {
            fwrite($this->out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return $report['attention'] === [] ? 0 : 1;
        }
        $this->say("Audit of {$report['files']} files (pages, Markdown, root pages, the course's CSS and JS)");
        foreach ($report['findings'] as $group => $items) {
            if ($items === []) {
                continue;
            }
            $this->say('');
            $this->say(ucfirst($group) . ':');
            foreach ($items as $name => $files) {
                $this->say(sprintf('  %-40s %3d file%s  %s', $name, count($files), count($files) === 1 ? ' ' : 's',
                    implode(', ', array_slice($files, 0, 3)) . (count($files) > 3 ? ', ...' : '')));
            }
        }
        $features = $result->config->features;
        $this->say('');
        $this->say('Suggested [features] for calendar.ini.php:');
        $this->say('  legacy_css = ' . $report['suggest']['legacy_css']);
        $this->say('  legacy_js  = ' . $report['suggest']['legacy_js']
            . ($report['libraries'] !== [] ? '   ; loads ' . implode(', ', $report['libraries']) . ' on the pages that use them' : ''));
        $this->say('Now: legacy_css = ' . ($features->legacyCss ? 'yes' : 'no') . ', legacy_js = ' . (implode(', ', $features->legacyJs) ?: '(none)')
            . ($result->source === ConfigSource::Legacy ? ' (v4 configuration: both are on)' : ''));
        $this->say('');
        if ($report['attention'] === []) {
            $this->say('Nothing needs attention: Cal5 handles everything these pages use.');
            return 0;
        }
        $this->say('Needs attention:');
        foreach ($report['attention'] as $line) {
            $this->say('  - ' . $line);
        }
        return 1;
    }

    /** @param array<string, string|true> $options */
    private function compare(array $options): int
    {
        $courseDir = is_string($options['course'] ?? null) ? (string) $options['course'] : '.';
        $v4 = is_string($options['v4'] ?? null) ? (string) $options['v4'] : null;
        if ($v4 === null) {
            foreach (["$courseDir/calendar-core-v4", "$courseDir/calendar"] as $candidate) {
                if (is_file("$candidate/calendar_main.php") && !is_file("$candidate/src/App.php")) {
                    $v4 = $candidate;
                    break;
                }
            }
        }
        if ($v4 === null) {
            return $this->fail('Where is v4? Use --v4 DIR (a calendar-core checkout), or keep it as calendar-core-v4/ in the course.', 2);
        }
        $compare = new \Cal5\Compare\CourseCompare(
            $courseDir,
            $v4,
            dirname(__DIR__, 2),
            is_string($options['password'] ?? null) ? (string) $options['password'] : null,
            is_string($options['max'] ?? null) ? max(1, (int) $options['max']) : 3000,
            isset($options['keep']),
        );
        if (!isset($options['json'])) {
            fwrite($this->err, "Serving the course with v4 and with Cal5 and comparing every page and file...\n");
        }
        $report = $compare->run();
        if (isset($options['json'])) {
            fwrite($this->out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return $report['differences'] === [] ? 0 : 1;
        }
        $this->say('Course: ' . $report['course']);
        foreach ($report['roles'] as $role => $stats) {
            $this->say(sprintf('  %-10s %4d addresses (%d pages, %d files): %d the same, %d different%s', $role, $stats['requests'],
                $stats['pages'], $stats['files'], $stats['match'], $stats['differ'],
                $stats['not visited'] > 0 ? ", {$stats['not visited']} not visited (--max)" : ''));
        }
        if ($report['refused']) {
            $this->say('  (the configuration is refused, for example the published sample secret: both show their error page, and the instructor is not compared)');
        } elseif (!isset($report['roles']['instructor'])) {
            $this->say('  (instructor not compared: the password is stored as a hash; use --password)');
        }
        if ($report['work'] !== null) {
            $this->say('  copies kept in ' . $report['work']);
        }
        if ($report['differences'] === []) {
            $this->say('');
            $this->say('No differences: Cal5 shows this course as v4 does (see docs/design/compatibility.md for the intended differences).');
            return 0;
        }
        $this->say('');
        $this->say('Differences:');
        foreach (array_slice($report['differences'], 0, 200) as $difference) {
            $this->say("  {$difference['role']} {$difference['url']}");
            foreach ($difference['problems'] as $problem) {
                $this->say("      {$problem['field']}:");
                $this->say('        v4:   ' . ($problem['v4'] === '' ? '-' : $problem['v4']));
                $this->say('        Cal5: ' . ($problem['cal5'] === '' ? '-' : $problem['cal5']));
            }
        }
        if (count($report['differences']) > 200) {
            $this->say('  ... and ' . (count($report['differences']) - 200) . ' more (use --json)');
        }
        return 1;
    }

    /** @param array<string, string|true> $options */
    private function init(array $options): int
    {
        $dir = is_string($options['course'] ?? null) ? (string) $options['course'] : '.';
        // --yes or --password-stdin: no questions (standard input carries the password); defaults for the rest
        $quiet = isset($options['yes']) || isset($options['password-stdin']);
        $ask = function (string $option, string $question, string $default = '') use ($options, $quiet): string {
            if (is_string($options[$option] ?? null)) {
                return trim((string) $options[$option]);
            }
            if ($quiet) {
                return $default;
            }
            fwrite($this->err, $question . ($default !== '' ? " [$default]" : '') . ': ');
            $answer = trim((string) fgets($this->in));
            return $answer === '' ? $default : $answer;
        };
        $year = (int) date('Y');
        $answers = [
            'number' => $ask('number', 'Course number (for example SI460)'),
            'name' => $ask('name', 'Course name (for example Computer Graphics)'),
        ];
        $answers['full_name'] = $ask('full-name', 'Full name, shown in the footer', trim($answers['number'] . ': ' . $answers['name'], ': '));
        $answers['start'] = $ask('start', 'First day of the semester (YYYY-MM-DD)', "$year-08-24");
        $answers['end'] = $ask('end', 'Last day of the semester (YYYY-MM-DD)', "$year-12-18");
        $answers['class_days'] = CourseInit::days($ask('class-days', 'Class days', 'mon wed fri'));
        $answers['lab_days'] = CourseInit::days($ask('lab-days', 'Lab days (empty for none)', ''));
        $answers['timezone'] = $ask('timezone', 'Time zone', date_default_timezone_get());
        if (isset($options['password-stdin'])) {
            $answers['password'] = rtrim((string) fgets($this->in), "\r\n");
        } else {
            $tty = function_exists('posix_isatty') && posix_isatty($this->in);
            fwrite($this->err, 'Instructor password (at least 8 characters): ');
            if ($tty) {
                shell_exec('stty -echo');
            }
            $answers['password'] = rtrim((string) fgets($this->in), "\r\n");
            if ($tty) {
                shell_exec('stty echo');
                fwrite($this->err, "\n");
            }
        }
        $written = CourseInit::create($dir, $answers);
        $this->say('Created the course ' . $answers['number'] . ':');
        foreach ($written as $file) {
            $this->say('  ' . $file);
        }
        $this->say('');
        $this->say('Next: open the course in a browser, log in, and add material to class/02.Name/, class/03.Name/, ...');
        $this->say('Check the configuration any time with: php calendar/bin/cal5 check');
        return 0;
    }

    /** Developer command: regenerate the generated part of public/css/compat.css. */
    private function buildCompatCss(): int
    {
        $file = \Cal5\View\CompatCss::FILE;
        file_put_contents($file, \Cal5\View\CompatCss::build((string) file_get_contents($file)));
        $this->say('wrote ' . realpath($file));
        return 0;
    }

    private function loadCourseConfig(string $courseDir): LoadResult
    {
        if (!is_dir($courseDir)) {
            throw new RuntimeException("$courseDir is not a directory");
        }
        $globals = [];
        if (!is_file($courseDir . '/' . ConfigLoader::INI_FILE) && is_file($courseDir . '/calendar.php')) {
            $globals = LegacyCapture::run($courseDir . '/calendar.php');
        }
        return ConfigLoader::forCourse($courseDir, $globals);
    }

    /** @param array<string, string|true> $options */
    private function hashPassword(array $options): int
    {
        if (isset($options['stdin'])) {
            $password = rtrim((string) fgets($this->in), "\r\n");
        } else {
            $tty = function_exists('posix_isatty') && posix_isatty($this->in);
            fwrite($this->err, 'Password: ');
            if ($tty) {
                shell_exec('stty -echo');
            }
            $password = rtrim((string) fgets($this->in), "\r\n");
            if ($tty) {
                shell_exec('stty echo');
                fwrite($this->err, "\n");
            }
        }
        if ($password === '') {
            return $this->fail('The password is empty.', 1);
        }
        $this->say(password_hash($password, PASSWORD_DEFAULT));
        return 0;
    }

    private function problems(LoadResult $result): void
    {
        foreach ($result->errors as $problem) {
            fwrite($this->err, 'error: ' . self::describe($problem) . "\n");
        }
        foreach ($result->warnings as $problem) {
            fwrite($this->err, 'warning: ' . self::describe($problem) . "\n");
        }
    }

    private static function describe(Problem $problem): string
    {
        return (string) $problem;
    }

    private function say(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    private function fail(string $message, int $code): int
    {
        fwrite($this->err, $message . "\n");
        return $code;
    }

    /**
     * --name, --name=value, -o value
     *
     * @param list<string> $args
     * @return array{0: array<string, string|true>, 1: list<string>}
     */
    private static function parse(array $args): array
    {
        $options = [];
        $arguments = [];
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m)) {
                $options[$m[1]] = $m[2] ?? true;
            } elseif ($arg === '-o' && isset($args[$i + 1])) {
                $options['o'] = $args[++$i];
            } else {
                $arguments[] = $arg;
            }
        }
        return [$options, $arguments];
    }
}
