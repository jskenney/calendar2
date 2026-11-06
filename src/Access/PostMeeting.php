<?php

declare(strict_types=1);

namespace Cal5\Access;

use Cal5\Config\Config;
use DateTimeImmutable;

/**
 * When <postmeeting> content becomes visible to students (features.postmeeting):
 *
 *  - off:   always visible (v4 without $REVEALHOUR leaves the content alone)
 *  - end:   when the event's meeting ends; without a meeting time, at the end of its day
 *  - HH:MM: at that time on the event's date (v4 $REVEALHOUR = 13 -> 13:00; 24:00 = end of day)
 *
 * Content of events that are not on the calendar stays hidden (calendar-core 4.6).
 */
final class PostMeeting
{
    public static function visible(Config $config, EventContext $event, DateTimeImmutable $now): bool
    {
        if ($config->features->postmeeting === 'off') {
            return true;
        }
        $at = self::revealsAt($config, $event);
        return $at !== null && $now >= $at;
    }

    /** The moment the content appears, or null when it never does (event not on the calendar). */
    public static function revealsAt(Config $config, EventContext $event): ?DateTimeImmutable
    {
        $setting = $config->features->postmeeting;
        if ($setting === 'off') {
            return new DateTimeImmutable('@0');
        }
        if ($event->date === null) {
            return null;
        }
        if ($setting === 'end') {
            $time = $event->meeting?->end ?? '24:00';
        } else {
            $time = $setting;
        }
        return AbsoluteWhen::dateTime($event->date, $time, $event);
    }
}
