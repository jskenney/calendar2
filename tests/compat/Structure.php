<?php

declare(strict_types=1);

namespace Cal5\Tests\Compat;

use Cal5\Tests\Fixture;
use RuntimeException;

/**
 * The structure of a course as v4 computes it (milestone 3): every event of
 * every type with its directory, name, calendar date, box files and file
 * keys, plus which events appear on each calendar day.
 *
 * fromV4Course() reads it out of v4 (tests/compat/v4-probe.php); the Cal5
 * side is built by Cal5\Course and normalized by Structure::fromCal5().
 */
final class Structure
{
    /** Run v4 on an installed fixture course and normalize what it computed. @return array<string, mixed> */
    public static function fromV4Course(string $courseDir, Fixture $fixture): array
    {
        copy(__DIR__ . '/v4-probe.php', $courseDir . '/cal5-v4-probe.php');
        $env = ['CAL5_PROBE_PASSWORD' => (string) $fixture->meta['password'], 'CAL5_PROBE_ENTRY' => (string) $fixture->meta['entry']]
            + getenv();
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', 'cal5-v4-probe.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $courseDir, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('could not run the v4 probe');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        unlink($courseDir . '/cal5-v4-probe.php');
        $dump = json_decode($out, true);
        if (!is_array($dump) || ($dump['instructor'] ?? null) !== true) {
            throw new RuntimeException('the v4 probe failed: ' . substr($out . $err, 0, 500));
        }
        return self::normalizeV4($dump, $courseDir);
    }

    /** Build a fixture course with Cal5 and normalize it the same way. @return array<string, mixed> */
    public static function fromCal5Course(string $courseDir, Fixture $fixture): array
    {
        $globals = \Cal5\Config\LegacyCapture::run($courseDir . '/' . $fixture->meta['entry']);
        $result = \Cal5\Config\ConfigLoader::forCourse($courseDir, $globals);
        if ($result->config === null) {
            throw new RuntimeException('configuration errors: ' . implode('; ', array_map('strval', $result->errors)));
        }
        return self::fromCal5(\Cal5\Course::load($courseDir, $result->config));
    }

    /** @return array<string, mixed> */
    public static function fromCal5(\Cal5\Course $course): array
    {
        $types = [];
        foreach ($course->content->types as $type => $events) {
            foreach ($events as $event) {
                $boxes = $event->boxes;
                ksort($boxes);
                $files = [];
                foreach ($event->files as $name => $file) {
                    $files[$name] = [
                        'key' => $file->key,
                        'box' => $file->box,
                        'actual' => self::relative($file->actualPath, $course->directory),
                    ];
                }
                $types[$type][] = [
                    'number' => $event->number,
                    'dir' => $event->directory,
                    'name' => $event->name,
                    'date' => $course->schedule->dateOf($type, $event->number),
                    'boxes' => $boxes,
                    'files' => $files,
                ];
            }
        }
        ksort($types);
        $days = [];
        foreach ($course->schedule->days as $date => $day) {
            foreach ($day->entries as $entry) {
                $days[$date][] = ['type' => $entry->type, 'number' => $entry->number, 'combined' => $entry->combined];
            }
        }
        ksort($days);
        return ['types' => $types, 'days' => $days];
    }

    /** @param array<string, mixed> $dump @return array<string, mixed> */
    public static function normalizeV4(array $dump, string $courseDir): array
    {
        $year = (int) $dump['year'];
        $list = is_array($dump['events_list']) ? $dump['events_list'] : [];
        $map = is_array($list['map'] ?? null) ? $list['map'] : [];

        $types = [];
        foreach (($dump['files'] ?? []) as $type => $dirs) {
            $number = 0;
            foreach ($dirs as $dir => $files) {
                $number++;
                $event = $dump['events'][$type][$number] ?? [];
                $date = null;
                if (isset($map[$type][$number]) && is_array($map[$type][$number])) {
                    $date = sprintf('%04d-%02d-%02d', $year, (int) $map[$type][$number][0], (int) $map[$type][$number][1]);
                }
                $boxes = [];
                foreach (($event['box'] ?? []) as $box => $info) {
                    $boxes[(string) $box] = (string) $info['filename'];
                }
                ksort($boxes);
                $fileList = [];
                foreach ($files as $name => $info) {
                    $fileList[(string) $name] = [
                        'key' => (string) $info['key'],
                        'box' => ($info['category'] ?? '') === '' ? null : (string) $info['category'],
                        'actual' => self::relative((string) $info['actual'], $courseDir),
                    ];
                }
                $types[(string) $type][] = [
                    'number' => $number,
                    'dir' => (string) $dir,
                    'name' => (string) ($event['name'] ?? ''),
                    'date' => $date,
                    'boxes' => $boxes,
                    'files' => $fileList,
                ];
            }
        }
        ksort($types);

        $days = [];
        foreach ($list as $month => $monthDays) {
            if (!is_int($month) && !ctype_digit((string) $month)) {
                continue;
            }
            foreach ($monthDays as $day => $info) {
                $date = sprintf('%04d-%02d-%02d', $year, (int) $month, (int) $day);
                $entries = [];
                if (isset($info['type'])) {
                    $entries[] = ['type' => (string) $info['type'], 'number' => self::number($info['type_num'] ?? ''), 'combined' => false];
                }
                foreach (($info['combine'] ?? []) as $extra) {
                    $entries[] = ['type' => (string) $extra['type'], 'number' => self::number($extra['type_num'] ?? -1), 'combined' => true];
                }
                $days[$date] = $entries;
            }
        }
        ksort($days);
        return ['types' => $types, 'days' => $days];
    }

    /** "./class/x" and absolute paths inside the course -> "class/x" */
    public static function relative(string $path, string $courseDir): string
    {
        $course = rtrim((string) realpath($courseDir), '/') . '/';
        if (str_starts_with($path, $course)) {
            return substr($path, strlen($course));
        }
        if (str_starts_with($path, './')) {
            return substr($path, 2);
        }
        return $path;
    }

    private static function number(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Differences between two structures, as readable lines.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return list<string>
     */
    public static function compare(array $expected, array $actual, string $path = ''): array
    {
        $differences = [];
        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $key) {
            $where = $path === '' ? (string) $key : "$path.$key";
            $a = $expected[$key] ?? null;
            $b = $actual[$key] ?? null;
            if (!array_key_exists($key, $actual)) {
                $differences[] = "$where: missing (expected " . json_encode($a, JSON_UNESCAPED_SLASHES) . ')';
            } elseif (!array_key_exists($key, $expected)) {
                $differences[] = "$where: unexpected " . json_encode($b, JSON_UNESCAPED_SLASHES);
            } elseif (is_array($a) && is_array($b)) {
                $differences = array_merge($differences, self::compare($a, $b, $where));
            } elseif ($a !== $b) {
                $differences[] = "$where: expected " . json_encode($a, JSON_UNESCAPED_SLASHES) . ', got ' . json_encode($b, JSON_UNESCAPED_SLASHES);
            }
        }
        return $differences;
    }
}
