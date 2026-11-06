<?php

declare(strict_types=1);

namespace Cal5\Config;

use RuntimeException;

/**
 * Runs a v4 course's calendar.php (for the command line tools and tests) and
 * returns the variables it sets, without running the calendar: while a capture
 * is active, calendar_main.php returns early.  The variables are read from the
 * scope calendar.php ran in, so this also works when calendar_main.php was
 * already loaded once in this process (require_once then skips it).  It also
 * works before the course is switched to Cal5 (calendar/ still pointing to v4).
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
        // Before a course is switched, its calendar/ still points to v4.  calendar.php is then
        // run from a temporary directory whose calendar/ is this Cal5: PHP finds
        // "calendar/calendar_main.php" in the working directory first, and the course's own
        // relative includes are still found next to calendar.php.
        $runIn = $course;
        $temporary = null;
        if ($shim === false || $shim !== $ours) {
            $temporary = sys_get_temp_dir() . '/cal5-capture-' . bin2hex(random_bytes(4));
            mkdir($temporary);
            symlink(dirname((string) $ours), $temporary . '/calendar');
            $runIn = $temporary;
        }

        $cwd = (string) getcwd();
        self::$capturing = true;
        self::$captured = null;
        chdir($runIn);
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
            if ($temporary !== null) {
                @unlink($temporary . '/calendar');
                @rmdir($temporary);
            }
        }
        unset($variables['cal5CaptureFile'], $variables['cal5GlobalVariables']);
        return $variables;
    }
}
