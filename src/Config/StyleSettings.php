<?php

declare(strict_types=1);

namespace Cal5\Config;

final class StyleSettings
{
    /**
     * @param list<string> $css every page
     * @param array<string, list<string>> $cssByType type => stylesheets
     * @param list<string> $cssCalendar calendar views
     * @param list<string> $js every page
     * @param array<string, list<string>> $jsByType type => scripts
     */
    public function __construct(
        public readonly array $css,
        public readonly array $cssByType,
        public readonly array $cssCalendar,
        public readonly array $js,
        public readonly array $jsByType,
    ) {
    }
}
