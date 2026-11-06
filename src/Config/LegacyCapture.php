<?php

declare(strict_types=1);

namespace Cal5\Config;

use RuntimeException;

/**
 * Runs a v4 course's calendar.php (for the command line tools and tests) and
 * returns the variables it sets, without running the calendar: while a capture
 * is active, calendar_main.php returns early.  The variables are read from the
 * scope calendar.php ran in, so this also works when calendar_main.php was
 * already loaded once in this process (require_once then skips it).
 */
final class LegacyCapture
{
    public static bool $capturing = false;
    /** @var array<string, mixed>|null */
    public static ?array $captured = null;

    /** @return array<string, mixed> */
    public static function run(string $calendarPhp): array
    {
        $file = realpath($calendarPhp);
        if ($file === false || !is_file($file)) {
            throw new RuntimeException("$calendarPhp not found");
        }
        $course = dirname($file);
        $shim = realpath($course . '/calendar/calendar_main.php');
        $ours = realpath(dirname(__DIR__, 2) . '/calendar_main.php');
        if ($shim === false || $shim !== $ours) {
            throw new RuntimeException("calendar/ in $course is not this copy of Cal5 "
                . "(calendar.php loads calendar/calendar_main.php, so calendar/ must point to Cal5 to read the settings)");
        }

        $cwd = (string) getcwd();
        self::$capturing = true;
        self::$captured = null;
        chdir($course);
        ob_start();
        try {
            $variables = (static function (string $cal5CaptureFile): array {
                require $cal5CaptureFile;
                return get_defined_vars();
            })($file);
        } finally {
            ob_end_clean();
            chdir($cwd);
            self::$capturing = false;
        }
        unset($variables['cal5CaptureFile'], $variables['cal5GlobalVariables']);
        return $variables;
    }
}
