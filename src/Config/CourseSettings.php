<?php

declare(strict_types=1);

namespace Cal5\Config;

final class CourseSettings
{
    public function __construct(
        public readonly string $number,
        public readonly string $name,
        public readonly string $fullName,
        public readonly string $title,
        public readonly string $timezone,
    ) {
    }
}
