<?php

declare(strict_types=1);

namespace Cal5\Config;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Parsing and normalizing of individual setting values.
 * Parse functions throw InvalidArgumentException with a readable message.
 */
final class Values
{
    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** "Final Exam" -> "final-exam" (the v4 rule: lower case, spaces to dashes). */
    public static function normalizeType(string $label): string
    {
        return str_replace(' ', '-', strtolower(trim($label)));
    }

    /** A type must be usable as a directory name. */
    public static function isValidType(string $type): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9._-]*$/', $type) && !str_contains($type, '..');
    }

    /** "final-exam" -> "Final Exam" */
    public static function menuLabel(string $type): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $type));
    }

    public static function bool(string $value): bool
    {
        $lower = strtolower(trim($value));
        return match ($lower) {
            'yes', 'true', 'on', '1' => true,
            'no', 'false', 'off', '0', '' => false,
            default => throw new InvalidArgumentException("expected yes or no, got \"$value\""),
        };
    }

    /** @return list<string> comma separated, empty items dropped */
    public static function list(string $value): array
    {
        $items = array_map('trim', explode(',', $value));
        return array_values(array_filter($items, static fn (string $i): bool => $i !== ''));
    }

    /** YYYY-MM-DD, a real calendar date */
    public static function date(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("expected a date like 2026-08-24, got \"$value\"");
        }
        return $value;
    }

    /** HH:MM, 24-hour; 24:00 is allowed (midnight at the end of the day) */
    public static function time(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)
            || (int) $m[2] > 59 || (int) $m[1] > 24 || ((int) $m[1] === 24 && (int) $m[2] !== 0)) {
            throw new InvalidArgumentException("expected a time like 13:30, got \"$value\"");
        }
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    /** mon / Monday / MON -> mon */
    public static function weekday(string $value): string
    {
        $lower = strtolower(trim($value));
        foreach (self::WEEKDAYS as $day) {
            if ($lower === $day || (strlen($lower) > 3 && str_starts_with(self::fullWeekday($day), $lower))) {
                return $day;
            }
        }
        throw new InvalidArgumentException("expected a weekday such as mon or monday, got \"$value\"");
    }

    public static function fullWeekday(string $day): string
    {
        return [
            'mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday', 'thu' => 'thursday',
            'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday',
        ][$day];
    }

    public static function timezone(string $value): string
    {
        try {
            return (new DateTimeZone(trim($value)))->getName();
        } catch (\Exception) {
            throw new InvalidArgumentException("unknown time zone \"$value\" (use a name like America/New_York)");
        }
    }

    /** "inline:notes.txt" -> inline candidate */
    public static function candidate(string $value): BoxCandidate
    {
        $value = trim($value);
        if (stripos($value, 'inline:') === 0) {
            return new BoxCandidate(trim(substr($value, 7)), true);
        }
        return new BoxCandidate($value, false);
    }

    /**
     * "class 08:00-08:50", "lab + class", "final exam" -> DayPlan
     */
    public static function dayPlan(string $value): DayPlan
    {
        $entries = [];
        foreach (explode('+', $value) as $part) {
            $part = trim($part);
            if (!preg_match('/^(.*?)(?:\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2}))?$/', $part, $m) || trim($m[1]) === '') {
                throw new InvalidArgumentException("expected a type such as \"class\" or \"lab 13:30-15:20\", got \"$value\"");
            }
            $label = trim($m[1]);
            $type = self::normalizeType($label);
            if (!self::isValidType($type)) {
                throw new InvalidArgumentException("\"$label\" can not be used as a type (letters, digits, spaces, . _ - only)");
            }
            $meeting = null;
            if (isset($m[2]) && $m[2] !== '') {
                $meeting = new MeetingTime(self::time($m[2]), self::time($m[3]));
                if ($meeting->end <= $meeting->start) {
                    throw new InvalidArgumentException("the meeting must end after it starts: \"$part\"");
                }
            }
            $entries[] = new DayEntry($type, $label, $meeting);
        }
        return new DayPlan($entries);
    }

    /** "Classes | apple" -> MenuItem */
    public static function menuItem(string $type, string $value): MenuItem
    {
        $parts = array_map('trim', explode('|', $value, 2));
        $label = $parts[0] !== '' ? $parts[0] : self::menuLabel($type);
        return new MenuItem($type, $label, $parts[1] ?? '');
    }

    /** postmeeting: off | end | HH:MM */
    public static function postmeeting(string $value): string
    {
        $lower = strtolower(trim($value));
        if ($lower === 'off' || $lower === 'end') {
            return $lower;
        }
        try {
            return self::time($value);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("expected off, end, or a time like 13:00, got \"$value\"");
        }
    }

    /** Last day of a month as YYYY-MM-DD */
    public static function lastDayOfMonth(int $year, int $month): string
    {
        return (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('Y-m-t');
    }
}
