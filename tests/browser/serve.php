<?php

declare(strict_types=1);

/*
 * Serves a course for the browser checks (tests/browser/check.js):
 *
 *   php tests/browser/serve.php FIXTURE|demo
 *
 * Prints one JSON line {"url", "entry", "password"} once the server is up, keeps it
 * running until standard input is closed, then removes the course.
 */

require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/DemoCourse.php';

use Cal5\Tests\Browser\DemoCourse;
use Cal5\Tests\Fixture;
use Cal5\Tests\Server;

$name = $argv[1] ?? 'demo';
$library = dirname(__DIR__, 2);
if ($name === 'demo') {
    $course = Fixture::temporaryCourse(DemoCourse::files(new DateTimeImmutable('today')), $library);
    $info = ['entry' => 'index.php', 'password' => DemoCourse::PASSWORD];
} else {
    $fixture = Fixture::load($name);
    $course = $fixture->install($library);
    $info = ['entry' => (string) $fixture->meta['entry'], 'password' => (string) $fixture->meta['password']];
}
$server = new Server($course);
echo json_encode(['url' => $server->url()] + $info), "\n";
while (!feof(STDIN)) {
    fgets(STDIN);
}
$server->stop();
Fixture::remove($course);
