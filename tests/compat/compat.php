<?php

declare(strict_types=1);

/*
 * Compatibility runner: sends the same requests to v4 (calendar-core) and Cal5
 * and compares what users see.  See tests/compat/README.md.
 *
 *   php tests/compat/compat.php record --v4=PATH [--fixture=NAME]
 *       Run v4 and store the results in tests/compat/expected/NAME.json
 *
 *   php tests/compat/compat.php check --impl=v4 --v4=PATH [--fixture=NAME]
 *   php tests/compat/compat.php check --impl=cal5 [--fixture=NAME] [--report]
 *       Compare an implementation with the stored results.  --report prints a
 *       summary and always exits 0 (used while Cal5 is still being built).
 */

require_once __DIR__ . '/../lib/autoload.php';

use Cal5\Tests\Compat\Runner;
use Cal5\Tests\Fixture;

// (getopt() stops at the first non-option argument, so parse by hand)
$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $argument, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "Unknown argument: $argument\n");
        exit(2);
    }
}
$fixtures = isset($options['fixture']) ? [(string) $options['fixture']] : Fixture::names();
$impl = (string) ($options['impl'] ?? ($command === 'record' ? 'v4' : 'cal5'));
$v4 = isset($options['v4']) ? (string) $options['v4'] : null;
$report = isset($options['report']);

if (!in_array($command, ['record', 'check'], true) || !in_array($impl, ['v4', 'cal5'], true)) {
    fwrite(STDERR, "Usage: compat.php record --v4=PATH | check --impl=v4|cal5 [--v4=PATH] [--fixture=NAME] [--report]\n");
    exit(2);
}
if ($impl === 'v4' && ($v4 === null || !is_file($v4 . '/calendar_main.php'))) {
    fwrite(STDERR, "--v4 must point to a calendar-core checkout\n");
    exit(2);
}
$implementationDir = $impl === 'v4' ? (string) $v4 : dirname(__DIR__, 2);

$exit = 0;
foreach ($fixtures as $name) {
    $fixture = Fixture::load($name);
    $results = Runner::run($fixture, $implementationDir);
    $expectedFile = __DIR__ . "/expected/$name.json";

    if ($command === 'record') {
        $label = (string) ($options['label'] ?? 'calendar-core');
        $data = ['fixture' => $name, 'recorded_with' => $label, 'requests' => []];
        foreach ($results as $id => $result) {
            $data['requests'][] = ['id' => $id] + $result['request'] + ['observation' => $result['observation']];
        }
        file_put_contents($expectedFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        echo "recorded $name: " . count($results) . " requests -> tests/compat/expected/$name.json\n";
        continue;
    }

    $stored = json_decode((string) @file_get_contents($expectedFile), true);
    if (!is_array($stored)) {
        fwrite(STDERR, "No expected results for $name (run record first)\n");
        $exit = 1;
        continue;
    }
    $expected = [];
    foreach ($stored['requests'] as $request) {
        $expected[$request['id']] = $request['observation'];
    }
    $actual = array_map(static fn (array $r): array => $r['observation'], $results);
    $differences = Runner::compare($expected, $actual);

    $matching = 0;
    foreach ($expected as $id => $want) {
        if (isset($actual[$id]) && $actual[$id] === $want) {
            $matching++;
        }
    }
    printf("%-14s %s: %d/%d requests match\n", $name, $impl, $matching, count($expected));
    if ($differences !== [] && !$report) {
        foreach (array_slice($differences, 0, 40) as $difference) {
            echo "  - $difference\n";
        }
        if (count($differences) > 40) {
            echo '  ... ' . (count($differences) - 40) . " more\n";
        }
        $exit = 1;
    }
}
exit($report ? 0 : $exit);
