<?php

declare(strict_types=1);

namespace Cal5\Config;

final class ScheduleSettings
{
    /**
     * @param array<string, DayPlan> $weekly weekday (mon..sun) => plan
     * @param array<string, DayPlan> $days   YYYY-MM-DD => plan (replaces the weekly plan that day)
     * @param list<CombineRule> $combine
     */
    public function __construct(
        public readonly array $weekly,
        public readonly array $days,
        public readonly array $combine,
    ) {
    }
}
