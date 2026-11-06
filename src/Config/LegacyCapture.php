<?php

declare(strict_types=1);

namespace Cal5\Config;

use RuntimeException;

/**
 * Runs a v4 course's calendar.php (for the command line tools) and returns the
 * variables it sets, without running the calendar: calendar_main.php hands the
 * variables over and returns early while a capture is active.
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
            (static function (string $cal5CaptureFile): void {
                require $cal5CaptureFile;
            })($file);
        } finally {
            ob_end_clean();
            chdir($cwd);
            self::$capturing = false;
        }
        if (self::$captured === null) {
            throw new RuntimeException('calendar.php did not load calendar/calendar_main.php');
        }
        return self::$captured;
    }
}
