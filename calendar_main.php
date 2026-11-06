<?php

/*
 * Entry point for v4 courses.
 *
 * A v4 course's calendar.php sets its configuration variables ($COURSE, $DOW,
 * ...) and then runs  require_once('calendar/calendar_main.php');
 * This file is included in that same (global) scope, so the variables are
 * collected here and handed to Cal5, which translates them.
 */

$cal5GlobalVariables = get_defined_vars();
require_once __DIR__ . '/src/autoload.php';

// "bin/cal5 translate" and "check" only want the settings, not the calendar
if (\Cal5\Config\LegacyCapture::$capturing) {
    \Cal5\Config\LegacyCapture::$captured = $cal5GlobalVariables;
    return;
}

\Cal5\App::fromLegacyGlobals($cal5GlobalVariables, (string) getcwd(), __DIR__)->run();
