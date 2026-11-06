<?php

declare(strict_types=1);

namespace Cal5\Access;

use Cal5\Config\Problem;
use InvalidArgumentException;

/**
 * Reads access files (docs/design/access-rules.md): every v4 cal4.access line,
 * plus times, ranges and meeting-relative rules.
 *
 *     target  open  [until close | for duration]
 *
 * Fields are separated by tabs or spaces; with tabs, the target may contain
 * spaces.  Blank lines and # comments are skipped; bad lines are reported and
 * ignored (as calendar-core 4.6 does).
 */
final class AccessRuleParser
{
    private const DAYS = [
        'mon' => 'mon', 'monday' => 'mon', 'tue' => 'tue', 'tues' => 'tue', 'tuesday' => 'tue',
        'wed' => 'wed', 'wednes' => 'wed', 'wednesday' => 'wed', 'thu' => 'thu', 'thur' => 'thu',
        'thurs' => 'thu', 'thursday' => 'thu', 'fri' => 'fri', 'friday' => 'fri',
        'sat' => 'sat', 'saturday' => 'sat', 'sun' => 'sun', 'sunday' => 'sun',
    ];

    public static function parseFile(?string $path, string $courseDir): AccessRules
    {
        if ($path === null) {
            return AccessRules::none();
        }
        $full = str_starts_with($path, '/') ? $path : $courseDir . '/' . $path;
        if (!file_exists($full)) {
            return AccessRules::none();
        }
        $text = is_file($full) && is_readable($full) ? @file_get_contents($full) : false;
        if ($text === false) {
            // v4 shows no content at all when the access file can not be read
            return new AccessRules([], [new Problem('the access file can not be read: nothing is shown to students', $path)], true);
        }
        return self::parse($text, $path);
    }

    public static function parse(string $text, string $label = 'access file'): AccessRules
    {
        $rules = [];
        $problems = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            try {
                $rule = self::parseLine($line, $index + 1);
                $rules[$rule->target] = $rule;
            } catch (InvalidArgumentException $e) {
                $problems[] = new Problem($e->getMessage() . ': ' . $line, $label, $index + 1);
            }
        }
        return new AccessRules($rules, $problems);
    }

    public static function parseLine(string $line, int $number = 0): Rule
    {
        if (str_contains($line, "\t")) {
            [$target, $rest] = array_pad(preg_split('/\t+/', $line, 2) ?: [], 2, '');
        } else {
            [$target, $rest] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');
        }
        $target = trim((string) $target);
        $rest = trim(preg_replace('/\s+/', ' ', (string) $rest) ?? '');
        if ($target === '' || $rest === '') {
            throw new InvalidArgumentException('expected a target and when it opens');
        }

        $close = null;
        $parts = preg_split('/\s+(until|for)\s+/i', $rest, 2, PREG_SPLIT_DELIM_CAPTURE) ?: [$rest];
        $open = self::when($parts[0]);
        if (count($parts) === 3) {
            $close = strtolower($parts[1]) === 'for'
                ? new ForDurationWhen($open, self::duration($parts[2]), $parts[2])
                : self::when($parts[2]);
        }
        return new Rule($target, $open, $close, $number);
    }

    public static function when(string $text): When
    {
        $text = trim($text);
        $lower = strtolower($text);
        if ($lower === 'always') {
            return new AlwaysWhen();
        }
        if ($lower === 'never') {
            return new NeverWhen();
        }
        // v4: month day [year]
        if (preg_match('/^(\d{1,4}) (\d{1,4})(?: (\d{1,4}))?$/', $text, $m)) {
            return new LegacyDateWhen((int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null, $text);
        }
        // 2026-09-14 [13:30]
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?: (\d{1,2}:\d{2}))?$/', $text, $m)) {
            [$y, $mo, $d] = array_map('intval', explode('-', $m[1]));
            if (!checkdate($mo, $d, $y)) {
                throw new InvalidArgumentException("\"{$m[1]}\" is not a real date");
            }
            return new AbsoluteWhen($m[1], self::time($m[2] ?? '00:00'), $text);
        }
        // [start|end] offsets... [HH:MM]
        if (preg_match('/^(start|end)?((?: ?[+-] ?[a-z0-9]+)*)(?: (\d{1,2}:\d{2}))?$/i', $text, $m)
            && ($m[1] !== '' || trim($m[2]) !== '')) {
            $offsets = [];
            preg_match_all('/([+-]) ?([a-z0-9]+)/i', $m[2], $parts, PREG_SET_ORDER);
            foreach ($parts as $part) {
                $offsets[] = self::offset($part[1] === '-' ? -1 : 1, strtolower($part[2]), $text);
            }
            return new RelativeWhen(strtolower($m[1]), $offsets, isset($m[3]) ? self::time($m[3]) : null, $text);
        }
        throw new InvalidArgumentException("can not understand \"$text\" (see docs/design/access-rules.md)");
    }

    /**
     * Amounts have at most 4 digits, like v4 (which ignored longer ones): 9999 days is
     * about 27 years.
     *
     * @return array{sign: int, amount: int, unit: string}
     */
    private static function offset(int $sign, string $value, string $text): array
    {
        if (preg_match('/^(\d{1,4})([dwhm]?)$/', $value, $m)) {
            return ['sign' => $sign, 'amount' => (int) $m[1], 'unit' => $m[2] === '' ? 'd' : $m[2]];
        }
        if (isset(self::DAYS[$value])) {
            return ['sign' => $sign, 'amount' => 1, 'unit' => self::DAYS[$value]];
        }
        throw new InvalidArgumentException("\"$value\" is not a number of days of up to 4 digits (7, 7d, 2w, 3h, 90m) or a weekday in \"$text\"");
    }

    private static function duration(string $text): int
    {
        $text = strtolower(str_replace(' ', '', $text));
        if (!preg_match('/^(\d+[dwhm])+$/', $text)) {
            throw new InvalidArgumentException("\"$text\" is not a duration such as 48h, 2d or 1d12h");
        }
        preg_match_all('/(\d+)([dwhm])/', $text, $parts, PREG_SET_ORDER);
        $seconds = 0;
        foreach ($parts as $part) {
            $seconds += (int) $part[1] * ['d' => 86400, 'w' => 604800, 'h' => 3600, 'm' => 60][$part[2]];
        }
        return $seconds;
    }

    private static function time(string $text): string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $text, $m) || (int) $m[2] > 59 || (int) $m[1] > 24 || ((int) $m[1] === 24 && (int) $m[2] !== 0)) {
            throw new InvalidArgumentException("\"$text\" is not a time such as 13:30");
        }
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
}
