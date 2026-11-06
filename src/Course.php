<?php

declare(strict_types=1);

namespace Cal5;

use Cal5\Config\Config;
use Cal5\Config\Problem;
use Cal5\Content\ContentScanner;
use Cal5\Content\CourseContent;
use Cal5\Schedule\Schedule;

/** A course: its configuration, its content, and its calendar. */
final class Course
{
    private function __construct(
        public readonly string $directory,
        public readonly Config $config,
        public readonly CourseContent $content,
        public readonly Schedule $schedule,
    ) {
    }

    public static function load(string $directory, Config $config): self
    {
        $content = (new ContentScanner($config))->scan($directory);
        return new self($directory, $config, $content, Schedule::build($config, $content));
    }

    /** @return list<Problem> warnings found while reading the course content */
    public function problems(): array
    {
        return $this->content->problems;
    }
}
