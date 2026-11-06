<?php

declare(strict_types=1);

/*
 * Cal5 test runner (no dependencies).
 *
 *   php tests/run.php            run every test
 *   php tests/run.php unit       only tests/unit
 *   php tests/run.php http       only tests/http
 *   php tests/run.php Fixture    only test files whose name contains "Fixture"
 *
 * A test file tests/<suite>/FooTest.php defines class Cal5\Tests\<Suite>\FooTest
 * extending Cal5\Tests\TestCase, with public test*() methods.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/lib/autoload.php';

// Any PHP warning or notice inside a test counts as a failure.
// (Errors silenced with @ are skipped: PHP 8 still calls the handler for them.)
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$filter = $argv[1] ?? '';
$suites = ['unit', 'http'];
if (in_array($filter, $suites, true)) {
    $suites = [$filter];
    $filter = '';
}

$files = [];
foreach ($suites as $suite) {
    foreach (glob(__DIR__ . "/$suite/*Test.php") ?: [] as $file) {
        if ($filter === '' || stripos(basename($file), $filter) !== false) {
            $files[] = [$suite, $file];
        }
    }
}

$passed = 0;
$failed = 0;
$assertions = 0;
$start = microtime(true);
// tests must leave nothing behind in the temporary directory (courses, copies, rate limiter state)
$temporaryBefore = glob(sys_get_temp_dir() . '/cal5-*') ?: [];

foreach ($files as [$suite, $file]) {
    require_once $file;
    $class = 'Cal5\\Tests\\' . ucfirst($suite) . '\\' . basename($file, '.php');
    if (!class_exists($class)) {
        echo "FAIL $file: class $class not found\n";
        $failed++;
        continue;
    }
    $methods = array_filter(get_class_methods($class), static fn (string $m): bool => str_starts_with($m, 'test'));
    /** @var \Cal5\Tests\TestCase $test */
    $test = new $class();
    foreach ($methods as $method) {
        $name = basename($file, '.php') . '::' . $method;
        $test->resetResults();
        $errors = [];
        try {
            $test->setUp();
            try {
                $test->$method();
            } finally {
                $test->tearDown();
            }
        } catch (Throwable $e) {
            $errors[] = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }
        $errors = array_merge($test->failures(), $errors);
        $assertions += $test->assertions();
        if ($errors === [] && $test->assertions() === 0) {
            $errors[] = 'no assertions were made';
        }
        if ($errors === []) {
            $passed++;
            echo "  ok    $name\n";
        } else {
            $failed++;
            echo "  FAIL  $name\n";
            foreach ($errors as $error) {
                echo "        - $error\n";
            }
        }
    }
}

$leftovers = array_values(array_diff(glob(sys_get_temp_dir() . '/cal5-*') ?: [], $temporaryBefore));
if ($leftovers !== []) {
    $failed++;
    echo "\n  FAIL  the tests left files in " . sys_get_temp_dir() . ': ' . implode(', ', array_map('basename', $leftovers)) . "\n";
}
printf("\n%d passed, %d failed, %d assertions (%.1fs)\n", $passed, $failed, $assertions, microtime(true) - $start);
exit($failed === 0 && $passed > 0 ? 0 : 1);
