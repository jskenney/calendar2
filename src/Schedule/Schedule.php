<?php

declare(strict_types=1);

namespace Cal5\Schedule;

use Cal5\Config\Config;
use Cal5\Config\DayEntry;
use Cal5\Content\CourseContent;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * Places events on calendar dates, with v4's numbering rules (calendar-core 4.6):
 *
 *  - every date from semester.start to semester.end is visited (so a semester may cross into
 *    the next year); [days] replaces the weekly plan for its date
 *  - a scheduled day advances its type's counter only if that type has event directories;
 *    the day gets the event with that number if it exists
 *  - a day appears on the calendar if it comes from [days] or its event exists
 *  - [combine] rules apply when "type N" lands on a day: each added type's counter advances
 *    (even if that type has no material) and its event is added to the same day
 *  - extra entries of a [days] plan ("lab + class") behave like separate scheduled entries
 */
final class Schedule
{
    /**
     * @param array<string, CalendarDay> $days date => day, in date order
     * @param array<string, array<int, string>> $dates type => event number => date
     */
    private function __construct(public readonly array $days, private readonly array $dates)
    {
    }

    public static function build(Config $config, CourseContent $content): self
    {
        $counters = [];
        $days = [];
        $dates = [];

        $start = new DateTimeImmutable($config->semester->start);
        $end = (new DateTimeImmutable($config->semester->end))->modify('+1 day');
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $day) {
            $date = $day->format('Y-m-d');
            $weekday = strtolower($day->format('D'));
            $forced = isset($config->schedule->days[$date]);
            $plan = $config->schedule->days[$date] ?? $config->schedule->weekly[$weekday] ?? null;
            if ($plan === null) {
                continue;
            }

            $entries = [];
            foreach ($plan->entries as $entry) {
                $number = null;
                if ($content->hasEvents($entry->type)) {
                    $counters[$entry->type] = ($counters[$entry->type] ?? 0) + 1;
                    if ($content->event($entry->type, $counters[$entry->type]) !== null) {
                        $number = $counters[$entry->type];
                    }
                }
                if ($forced || $number !== null) {
                    $entries[] = new ScheduledEvent($date, $entry->type, $entry->label, $number, $entry->meeting);
                    if ($number !== null) {
                        $dates[$entry->type][$number] = $date;
                    }
                }
                if ($number !== null) {
                    foreach (self::combined($config, $entry, $number) as $added) {
                        $counters[$added] = ($counters[$added] ?? 0) + 1;
                        $addedNumber = $content->event($added, $counters[$added]) !== null ? $counters[$added] : null;
                        $entries[] = new ScheduledEvent($date, $added, $added, $addedNumber, null, true);
                        if ($addedNumber !== null) {
                            $dates[$added][$addedNumber] = $date;
                        }
                    }
                }
            }
            if ($entries !== []) {
                $days[$date] = new CalendarDay($date, $entries);
            }
        }
        return new self($days, $dates);
    }

    /** @return list<string> types added to "type number" by [combine] rules */
    private static function combined(Config $config, DayEntry $entry, int $number): array
    {
        $added = [];
        foreach ($config->schedule->combine as $rule) {
            if ($rule->type === $entry->type && $rule->number === $number) {
                array_push($added, ...$rule->add);
            }
        }
        return $added;
    }

    /** The date of an event, or null if it is not on the calendar. */
    public function dateOf(string $type, int $number): ?string
    {
        return $this->dates[$type][$number] ?? null;
    }

    public function day(string $date): ?CalendarDay
    {
        return $this->days[$date] ?? null;
    }

    /** The scheduled entry of an event (for its meeting time), or null. */
    public function eventEntry(string $type, int $number): ?ScheduledEvent
    {
        $date = $this->dateOf($type, $number);
        if ($date === null) {
            return null;
        }
        foreach ($this->days[$date]->entries as $entry) {
            if ($entry->type === $type && $entry->number === $number) {
                return $entry;
            }
        }
        return null;
    }
}
