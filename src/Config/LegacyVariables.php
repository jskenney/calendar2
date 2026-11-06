<?php

declare(strict_types=1);

namespace Cal5\Config;

/**
 * The global variables a v4 calendar.php can set (calendar-core 4.6).
 * Milestone 2 translates each of these; see docs/design/configuration.md.
 */
final class LegacyVariables
{
    public const NAMES = [
        'ACCESS_FILE', 'ACCESS_LOG', 'ADMIN', 'ALLOWED_PATHS', 'BOX', 'CALENDAR_TOOL', 'CLASS_FILE',
        'COMBINE', 'COURSE', 'COURSENAME', 'COURSENAMEFULL', 'CUSTOMCSS', 'CUSTOMCSS_CAL', 'CUSTOMJS',
        'DAY_START', 'DEBUG_MODE', 'DEFAULT_TODAYS_LECTURE', 'DOW', 'HIDE_BEGINNING_DAY',
        'HIDE_ENDING_DAY', 'HIDE_WEEKS', 'HTML', 'IGNORE_A_TAG_WARNING', 'LINK', 'LOAD_PAGES', 'LOCK',
        'LOGON_TIME', 'MARKDOWN_MENU_LEVELS', 'MD', 'MONTH_END', 'MONTH_START', 'NAVBAR_DROPDOWNS',
        'NAVBAR_DROPDOWNS_INSTRUCTOR', 'OVERRIDE', 'PAGE_MODIFY', 'PAGE_TITLE', 'PDF', 'PPT',
        'REVEALHOUR', 'SECRET', 'SRC', 'WEEKENDS', 'YEAR',
    ];

    /** Variables that every working v4 configuration sets. */
    public const REQUIRED = ['COURSE', 'DOW', 'YEAR', 'MONTH_START', 'MONTH_END'];

    /**
     * Pick the v4 configuration variables out of a set of global variables.
     *
     * @param array<string, mixed> $globals
     * @return array<string, mixed>
     */
    public static function extract(array $globals): array
    {
        $found = [];
        foreach (self::NAMES as $name) {
            if (array_key_exists($name, $globals)) {
                $found[$name] = $globals[$name];
            }
        }
        return $found;
    }

    /** @param array<string, mixed> $variables output of extract() */
    public static function looksLikeV4(array $variables): bool
    {
        foreach (self::REQUIRED as $name) {
            if (!array_key_exists($name, $variables)) {
                return false;
            }
        }
        return true;
    }
}
