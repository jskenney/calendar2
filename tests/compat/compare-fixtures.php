<?php

declare(strict_types=1);

/*
 * Runs bin/cal5 compare on every fixture course, installed as a v4 course would be before
 * switching (calendar -> v4), as an instructor would run it (milestone 10).
 *
 *   php tests/compat/compare-fixtures.php [--v4=.v4]
 */

require_once __DIR__ . '/../lib/autoload.php';

use Cal5\Tests\Fixture;

$option = $argv[1] ?? '';
$v4 = realpath(str_starts_with($option, '--v4=') ? substr($option, 5) : __DIR__ . '/../../.v4');
if ($v4 === false || !is_file("$v4/calendar_main.php")) {
    fwrite(STDERR, "v4 is needed: run tests/compat/fetch-v4.sh\n");
    exit(2);
}
$cal5 = (string) realpath(__DIR__ . '/../../bin/cal5');
$exit = 0;
foreach (Fixture::names() as $name) {
    $course = Fixture::load($name)->install($v4);
    try {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cal5) . ' compare 2>&1';
        $output = [];
        exec('cd ' . escapeshellarg($course) . ' && ' . $command, $output, $code);
        printf("%-14s %s\n", $name, $code === 0 ? 'no differences' : 'DIFFERENCES');
        foreach ($output as $line) {
            if (str_starts_with($line, '  ') && !str_contains($line, 'copies kept')) {
                echo "  $line\n";
            }
        }
        if ($code !== 0) {
            $exit = 1;
        }
    } finally {
        Fixture::remove($course);
    }
}
exit($exit);
