<?php

declare(strict_types=1);

namespace Cal5\Access;

use Cal5\Config\Problem;
use Cal5\Content\ContentFile;
use Cal5\Content\EventFolder;
use Cal5\Course;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Decides what students may see (docs/design/access-rules.md).
 *
 * The rule for a file is the first of these targets present in the access
 * file (v4 order, for file F of event N of type T, in box C):
 *
 *   T_N/F, F, T_N/C, T/C, T_N, T/*, T_N/all, T_N/*, * /C, T, *
 *
 * No rule: visible.  Events that are not on the calendar: never visible to
 * students.  A virtual file is checked under its virtual name and under the
 * path of its real file, and must be allowed by both (v4 used one for the
 * calendar and the other when serving).
 */
final class AccessPolicy
{
    private readonly DateTimeZone $timezone;
    private readonly int $courseYear;

    public function __construct(
        private readonly Course $course,
        private readonly AccessRules $rules,
        private readonly Clock $clock,
    ) {
        $this->timezone = new DateTimeZone($course->config->course->timezone);
        $this->courseYear = (int) substr($course->config->semester->start, 0, 4);
    }

    public static function forCourse(Course $course, ?Clock $clock = null): self
    {
        return new self(
            $course,
            AccessRuleParser::parseFile($course->config->files->access, $course->directory),
            $clock ?? new SystemClock(),
        );
    }

    /** @return list<Problem> */
    public function problems(): array
    {
        return $this->rules->problems;
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function context(EventFolder $event): EventContext
    {
        $entry = $this->course->schedule->eventEntry($event->type, $event->number);
        return new EventContext(
            $entry?->date,
            $entry?->meeting,
            $this->timezone,
            $this->courseYear,
        );
    }

    /** The rule that applies to a file under one of its names, in v4 precedence order. */
    public function ruleFor(EventFolder $event, string $fileName, ?string $box): ?Rule
    {
        $t = $event->type;
        $n = $event->number;
        $c = $box ?? '';
        foreach (["{$t}_{$n}/{$fileName}", $fileName, "{$t}_{$n}/{$c}", "{$t}/{$c}", "{$t}_{$n}", "{$t}/*",
                  "{$t}_{$n}/all", "{$t}_{$n}/*", "*/{$c}", $t, '*'] as $target) {
            $rule = $this->rules->get($target);
            if ($rule !== null) {
                return $rule;
            }
        }
        return null;
    }

    /** @return list<Window> one per name the file is checked under */
    public function windows(EventFolder $event, ContentFile $file): array
    {
        $context = $this->context($event);
        $onCalendar = $context->date !== null;
        $names = [$file->name];
        if ($file->virtualSource !== null && $file->virtualSource !== $file->name) {
            $names[] = $file->virtualSource;
        }
        if ($this->rules->unreadable) {
            return [new Window(new Rule('(access file can not be read)', new NeverWhen(), null, 0), $context, $onCalendar)];
        }
        $windows = [];
        foreach ($names as $name) {
            $windows[] = new Window($this->ruleFor($event, $name, $file->box), $context, $onCalendar);
        }
        return $windows;
    }

    /** The window shown to the instructor: the one that keeps the file hidden longest now. */
    public function window(EventFolder $event, ContentFile $file): Window
    {
        $windows = $this->windows($event, $file);
        $now = $this->now();
        foreach ($windows as $window) {
            if (!$window->isOpen($now)) {
                return $window;
            }
        }
        return $windows[0];
    }

    /** May a student see this file now (or at $now)? */
    public function visibleToStudents(EventFolder $event, ContentFile $file, ?DateTimeImmutable $now = null): bool
    {
        $now ??= $this->now();
        foreach ($this->windows($event, $file) as $window) {
            if (!$window->isOpen($now)) {
                return false;
            }
        }
        return true;
    }
}
