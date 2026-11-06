<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\AccessPolicy;
use Cal5\Access\Window;
use Cal5\Content\ContentFile;
use Cal5\Content\EventFolder;
use Cal5\Course;
use Cal5\Schedule\ScheduledEvent;
use DateTimeImmutable;

/**
 * What the calendar views show, decided as v4's calendar_display*.php do:
 *
 *  - every scheduled day with its entries (and the entries [combine] adds)
 *  - for each entry, the boxes listed for the calendar (v4 $BOX), in that order
 *  - students only see boxes whose file they may see now; the title box links to
 *    the event page only when it is visible (otherwise the name is shown as text)
 *  - the instructor sees every box, with its release window
 *  - "inline:" boxes (v4 $SRC) paste the file's text into the calendar
 */
final class CalendarModel
{
    /** @var list<string> */
    public readonly array $boxNames;
    /** @var array<string, int> type => colour index (0-7), in configuration order */
    public readonly array $typeColours;
    public readonly string $today;

    public function __construct(
        private readonly Course $course,
        private readonly AccessPolicy $policy,
        public readonly bool $instructor,
    ) {
        $config = $course->config;
        $this->boxNames = $config->display->calendarBoxes ?? array_map(static fn ($b): string => $b->name, $config->boxes);
        $colours = [];
        foreach (array_merge(array_keys($course->content->types), array_map(static fn ($m): string => $m->type, $config->menus)) as $type) {
            $colours[$type] ??= count($colours) % 8;
        }
        $this->typeColours = $colours;
        $this->today = $policy->now()->format('Y-m-d');
    }

    public function now(): DateTimeImmutable
    {
        return $this->policy->now();
    }

    /** First and last date shown (semester.show_from / show_until, v4 $HIDE_WEEKS). @return array{0: string, 1: string} */
    public function range(): array
    {
        $semester = $this->course->config->semester;
        return [$semester->showFrom ?? $semester->start, $semester->showUntil ?? $semester->end];
    }

    public function weekends(): bool
    {
        return $this->course->config->semester->weekends;
    }

    /**
     * The calendar's days that are inside the range shown, in date order.
     *
     * @return array<string, list<CalendarEntry>> date => entries
     */
    public function days(): array
    {
        [$from, $until] = $this->range();
        $days = [];
        foreach ($this->course->schedule->days as $date => $day) {
            if ($date < $from || $date > $until) {
                continue;
            }
            $days[$date] = array_map(fn (ScheduledEvent $e): CalendarEntry => $this->entry($e), $day->entries);
        }
        return $days;
    }

    public function colour(string $type): int
    {
        return $this->typeColours[$type] ?? 0;
    }

    private function entry(ScheduledEvent $scheduled): CalendarEntry
    {
        $folder = $scheduled->number === null ? null : $this->course->content->event($scheduled->type, $scheduled->number);
        $title = null;
        $boxes = [];
        if ($folder !== null) {
            foreach ($this->boxNames as $name) {
                $file = $folder->boxFile($name);
                if ($file === null) {
                    continue;
                }
                $box = $this->box($folder, $name, $file);
                if ($box === null) {
                    continue;
                }
                if ($name === 'title') {
                    $title = $box;
                } else {
                    $boxes[] = $box;
                }
            }
            // the title links to the event page even when it is not one of the calendar's boxes
            if ($title === null && !in_array('title', $this->boxNames, true) && ($file = $folder->boxFile('title')) !== null) {
                $title = $this->box($folder, 'title', $file);
            }
        }
        return new CalendarEntry($scheduled, $folder, $title, $boxes);
    }

    private function box(EventFolder $folder, string $name, ContentFile $file): ?CalendarBox
    {
        $visible = $this->policy->visibleToStudents($folder, $file);
        if (!$visible && !$this->instructor) {
            return null; // v4 removes the box for students
        }
        $window = $this->policy->window($folder, $file);
        $inline = null;
        if ($file->inline) {
            $inline = (string) @file_get_contents($file->actualPath); // v4 pastes the text as it is
        }
        return new CalendarBox($name, $file, $visible, $window, $inline, $this->state($window));
    }

    /** "opens", "closed" or "" (visible now), for the instructor's badges. */
    private function state(Window $window): string
    {
        $now = $this->policy->now();
        if ($window->isOpen($now)) {
            return '';
        }
        $opens = $window->opens();
        if (!$window->onCalendar || $opens === null || $opens <= $now) {
            return 'closed';
        }
        return 'opens';
    }

    /** calendar.php?type=T&event=N */
    public static function eventLink(string $entry, string $type, int $number): string
    {
        return $entry . '?type=' . rawurlencode($type) . '&event=' . $number;
    }

    /** calendar.php?key=K&type=T&event=N */
    public static function fileLink(string $entry, EventFolder $folder, ContentFile $file): string
    {
        return $entry . '?key=' . $file->key . '&type=' . rawurlencode($folder->type) . '&event=' . $folder->number;
    }
}
