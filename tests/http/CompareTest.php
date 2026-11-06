<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

use Cal5\Compare\CourseCompare;
use Cal5\Compare\Observation;
use Cal5\Tests\Fixture;
use Cal5\Tests\TestCase;

/**
 * Milestone 10: bin/cal5 compare.  Needs v4 (tests/compat/fetch-v4.sh), like the login
 * switching tests.
 */
final class CompareTest extends TestCase
{
    private const CAL5 = __DIR__ . '/../..';
    private const V4 = __DIR__ . '/../../.v4';

    /** @var list<string> */
    private array $remove = [];

    public function tearDown(): void
    {
        foreach ($this->remove as $path) {
            str_ends_with($path, '/course') ? Fixture::remove($path) : self::removeTree($path);
        }
        $this->remove = [];
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree("$path/$entry");
            }
        }
        rmdir($path);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private static function cal5(string $course, string $arguments): array
    {
        $process = proc_open(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr calendar/bin/cal5 ' . $arguments,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $course);
        if (!is_resource($process)) {
            return [-1, '', ''];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testFixtureCourseHasNoDifferences(): void
    {
        if (!is_file(self::V4 . '/calendar_main.php')) {
            $this->assertTrue(false, 'v4 is needed: run tests/compat/fetch-v4.sh');
            return;
        }
        $course = Fixture::load('kitchen-sink')->install(self::CAL5);
        $this->remove[] = $course;
        $before = sha1((string) file_get_contents("$course/logs/access.log"));
        [$code, $out, $err] = self::cal5($course, 'compare --v4=' . escapeshellarg((string) realpath(self::V4)));
        $this->assertSame(0, $code, "no differences ($out $err)");
        $this->assertContains('No differences', $out);
        $this->assertTrue((bool) preg_match('/student\s+(\d+) addresses .*: \1 the same, 0 different/', $out), 'every student address the same');
        $this->assertTrue((bool) preg_match('/instructor\s+(\d+) addresses/', $out), 'the instructor is compared too');
        $this->assertSame($before, sha1((string) file_get_contents("$course/logs/access.log")), 'the course is not changed (its access log)');
        $this->assertSame([], glob(sys_get_temp_dir() . '/cal5-compare-*') ?: [], 'the work copies are removed');

        [$code, $json] = self::cal5($course, 'compare --json --max=10 --v4=' . escapeshellarg((string) realpath(self::V4)));
        $report = json_decode($json, true);
        $this->assertSame(0, $code);
        $this->assertSame(10, $report['roles']['student']['requests'] ?? null, '--max');
        $this->assertTrue(($report['roles']['student']['not visited'] ?? 0) > 0);
    }

    public function testDifferencesAreFound(): void
    {
        if (!is_file(self::V4 . '/calendar_main.php')) {
            $this->assertTrue(false, 'v4 is needed: run tests/compat/fetch-v4.sh');
            return;
        }
        // a "v4" that adds a word to every page's content and hides one file's link
        $fake = sys_get_temp_dir() . '/cal5-fake-v4-' . bin2hex(random_bytes(4));
        $this->remove[] = $fake;
        mkdir($fake);
        foreach (scandir(self::V4) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== 'calendar_main.php') {
                symlink((string) realpath(self::V4 . "/$entry"), "$fake/$entry");
            }
        }
        $main = (string) file_get_contents(self::V4 . '/calendar_main.php');
        file_put_contents("$fake/calendar_main.php", str_replace("echo \$contents;\n", "echo 'ONLY-IN-V4 ' . \$contents;\n", $main, $count));
        $this->assertSame(1, $count, 'the fake v4 was made');

        $course = Fixture::load('sample')->install(self::CAL5);
        $this->remove[] = $course;
        $report = (new CourseCompare($course, $fake, (string) realpath(self::CAL5), null, 40))->run();
        $this->assertTrue($report['differences'] !== [], 'differences are reported');
        $first = $report['differences'][0]['problems'][0] ?? [];
        $this->assertSame('text', $first['field'] ?? null);
        $this->assertContains('ONLY-IN-V4', (string) ($first['v4'] ?? ''), 'with the text where they differ');
    }

    public function testObservation(): void
    {
        $links = Observation::links('<a href="calendar.php?key=K&amp;type=class&amp;event=1&amp;answers=yes">x</a> <a href=\'calendar.php?type=lab&event=2\'>y</a> <a href="calendar.php?load=home">z</a>', 'calendar.php');
        $this->assertSame(['key=K&type=class&event=1'], $links['keyed']);
        $this->assertSame(['type=lab&event=2'], $links['event']);
        $this->assertSame(3, count($links['all']));
        $v4 = "<nav>MENU</nav><!-- Begin providing the contents of the page -->\n<div class=\"container\">\n<div class='cal4-config-warnings' style='color:red;'><b>W</b><ul><li>x</li></ul></div>"
            . "<h1>Title</h1><form><input></form><p>Body &amp; more</p><script>x()</script></div> <!-- /container --></body>";
        $cal5 = '<header>MENU</header><main id="cal5-main"><div class="cal5-warnings" role="note"><div><ul><li>w</li></ul></div></div><h1>Title</h1><p>Body &amp; more</p></main>';
        $this->assertSame(Observation::words(Observation::content($v4, true)), Observation::words(Observation::content($cal5, false)), 'the same content text');
        $this->assertSame('Title Body & more', Observation::words(Observation::content($cal5, false)));
        $this->assertSame('event=1&type=class', CourseCompare::normalize('type=class&event=1&print=1'), 'print and nocss are dropped, parameters sorted');
        $this->assertSame('event=1&type=class', CourseCompare::normalize('event=1&type=class'));
        foreach (['password=x', 'lock=y&type=a&event=1', 'as=student', 'debug=1', 'edit=1', 'event[]=1'] as $skip) {
            $this->assertSame(null, CourseCompare::normalize($skip), $skip);
        }
        [$a, $b] = CourseCompare::firstDifference('one two three four', 'one two THREE four');
        $this->assertContains('three', $a);
        $this->assertContains('THREE', $b);
    }
}
