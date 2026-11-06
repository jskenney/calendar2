<?php

/*
 * Copied into a fixture course (calendar/ -> v4) and run with the PHP CLI from
 * the course directory.  Runs v4 once as the logged-in instructor (v4 accepts
 * the plain-text password as a request parameter) and prints the structures it
 * computed, as JSON.  Used by Structure::fromV4Course().
 */

ini_set('session.save_path', sys_get_temp_dir());
$_GET = [];
$_POST = ['password' => (string) getenv('CAL5_PROBE_PASSWORD')];
$_REQUEST = $_POST;
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/calendar.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['DOCUMENT_ROOT'] = '';

ob_start();
require getenv('CAL5_PROBE_ENTRY') ?: 'calendar.php';
ob_end_clean();

// What a student may see: v4's own get_file_security() for every file, called the way
// v4 calls it when serving a file ($other): the event's calendar date, its number, the
// file's category and "filename" (the real path for virtual files).  Events that are
// not on the calendar are hidden from students (calendar-core 4.6).
$studentVisible = [];
foreach (($files ?? []) as $type => $dirs) {
    $number = 0;
    foreach ($dirs as $dir => $dirFiles) {
        $number++;
        $map = $events_list['map'][$type][$number] ?? null;
        foreach ($dirFiles as $name => $info) {
            if (!is_array($map)) {
                $studentVisible[$type][$number][$name] = false;
                continue;
            }
            $x = get_file_security($YEAR, $map[0], $map[1], $type, $number, $info['category'], $info['filename'], $access);
            $studentVisible[$type][$number][$name] = (bool) $x[0];
        }
    }
}

echo json_encode([
    'student_visible' => $studentVisible,
    'instructor' => $INSTRUCTOR ?? null,
    'year' => $YEAR ?? null,
    'files' => $files ?? null,
    'events' => $events ?? null,
    'events_list' => $events_list ?? null,
], JSON_PARTIAL_OUTPUT_ON_ERROR);
