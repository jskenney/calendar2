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

Planned (see MILESTONES.md):
  compare        Compare a course under v4 and Cal5                 (milestone 10)
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
