<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Tests\Compat\Observer;
use Cal5\Tests\Compat\Requests;
use Cal5\Tests\Compat\Runner;
use Cal5\Tests\Fixture;
use Cal5\Tests\Response;
use Cal5\Tests\TestCase;

/** Tests for the compatibility harness itself, and for the recorded expectations. */
final class CompatHarnessTest extends TestCase
{
    public function testObserverReducesPagesToMarkersAndLinks(): void
    {
        $body = '<p>KS-one</p><a href="calendar.php?key=abc&amp;type=class&amp;event=1&amp;edit=1">x</a>'
            . '<img src=\'calendar.php?type=class&event=2\'> KS-two KS-one'
            . '<a href="calendar.php?load=home">home</a>';
        $observation = (new Observer(['pattern' => 'KS-[a-z]+']))->observe(new Response(200, ['HTTP/1.1 200 OK', 'Content-Type: text/html; charset=UTF-8'], $body));
        $this->assertSame('page', $observation['kind']);
        $this->assertSame(['KS-one', 'KS-two'], $observation['markers']);
        $this->assertSame(['key=abc&type=class&event=1'], $observation['keyed_links'], '&amp; decoded, extra parameters dropped');
        $this->assertSame(['type=class&event=2'], $observation['event_links']);
        $this->assertSame([], $observation['php_errors']);
    }

    public function testObserverMarkerList(): void
    {
        $observer = new Observer(['list' => ['zzz once', 'HOST-SHOWN', 'MISSING']]);
        $this->assertSame(['HOST-SHOWN', 'zzz once'], $observer->markers('a zzz once b HOST-SHOWN'));
    }

    public function testObserverDownloads(): void
    {
        $observer = new Observer([]);
        $pdf = $observer->observe(new Response(200, ['HTTP/1.1 200 OK', 'Content-Type: application/pdf', 'Content-Disposition: filename="a.pdf"'], '%PDF'));
        $this->assertSame(['status' => 200, 'kind' => 'download', 'content_type' => 'application/pdf', 'disposition' => 'inline', 'sha1' => sha1('%PDF')], $pdf);
        $ppt = $observer->observe(new Response(200, ['HTTP/1.1 200 OK', 'Content-Type: application/vnd.ms-powerpoint', 'Content-Disposition: attachment; filename="a.ppt"'], 'x'));
        $this->assertSame('attachment', $ppt['disposition']);
    }

    public function testObserverReportsPhpErrors(): void
    {
        $observation = (new Observer([]))->observe(new Response(200, ['HTTP/1.1 200 OK'], "<br />\n<b>Warning</b>:  Undefined variable \$x in /a.php on line 3<br />"));
        $this->assertSame(1, count($observation['php_errors']));
    }

    public function testRequestsUseV4KeysAndNaturalOrder(): void
    {
        $fixture = Fixture::load('kitchen-sink');
        $this->assertSame(['01.Welcome', '02.Markdown', '03.Links', '04.Slides', '05.Future', '06.Hidden', '07.Plain'],
            Requests::eventDirectories($fixture->sourceDir() . '/class'));
        $ids = array_column(Requests::forFixture($fixture), 'id');
        // sha1(secret . type . dir . file), the v4 key formula
        $key = sha1($fixture->meta['secret'] . 'class' . '01.Welcome' . 'handout.pdf');
        $this->assertTrue(in_array("student calendar.php?key=$key&type=class&event=1", $ids, true), 'keyed request for handout.pdf');
        $this->assertTrue(in_array('instructor calendar.php?type=class&event=8', $ids, true), 'one past the last event is requested');
        $this->assertSame(count($ids), count(array_unique($ids)), 'request ids are unique');
    }

    public function testVirtualFilesAreRequested(): void
    {
        $this->assertSame([['class', 2, 'syllabus.pdf', 'docs/syllabus.pdf']], Requests::virtualFiles(Fixture::load('kitchen-sink')));
        $this->assertSame([['class', 1, 'extra.html', 'shared docs/extra file.html']], Requests::virtualFiles(Fixture::load('core-tests')));
    }

    public function testCompareOnlyCountsPhpErrorsOfTheImplementationUnderTest(): void
    {
        $expected = ['a' => ['status' => 200, 'php_errors' => ['Warning: v4 bug']]];
        $this->assertSame([], Runner::compare($expected, ['a' => ['status' => 200, 'php_errors' => []]]), 'fewer warnings than v4 is fine');
        $this->assertSame(1, count(Runner::compare($expected, ['a' => ['status' => 200, 'php_errors' => ['Warning: new']]])));
    }

    public function testStructureCompareFindsDifferences(): void
    {
        $a = ['types' => ['class' => [['number' => 1, 'date' => '2018-08-20', 'files' => ['x' => ['key' => 'k']]]]]];
        $b = ['types' => ['class' => [['number' => 1, 'date' => '2018-08-21', 'files' => ['y' => ['key' => 'k']]]]]];
        $differences = \Cal5\Tests\Compat\Structure::compare($a, $b);
        $this->assertSame([
            'types.class.0.date: expected "2018-08-20", got "2018-08-21"',
            'types.class.0.files.x: missing (expected {"key":"k"})',
            'types.class.0.files.y: unexpected {"key":"k"}',
        ], $differences);
        $this->assertSame([], \Cal5\Tests\Compat\Structure::compare($a, $a));
    }

    public function testCompareFindsDifferences(): void
    {
        $expected = ['a' => ['status' => 200, 'markers' => ['X']], 'b' => ['status' => 200]];
        $actual = ['a' => ['status' => 200, 'markers' => []], 'c' => ['status' => 200]];
        $differences = Runner::compare($expected, $actual);
        $this->assertSame(3, count($differences), implode(' | ', $differences));
        $this->assertSame([], Runner::compare($expected, $expected));
    }

    /** The recorded v4 results must match the current request list (re-record after changing a fixture). */
    public function testExpectedResultsAreUpToDate(): void
    {
        foreach (Fixture::names() as $name) {
            $file = __DIR__ . "/../compat/expected/$name.json";
            $data = json_decode((string) @file_get_contents($file), true);
            $this->assertTrue(is_array($data), "expected/$name.json exists");
            if (!is_array($data)) {
                continue;
            }
            $recorded = array_column($data['requests'], 'id');
            $generated = array_column(Requests::forFixture(Fixture::load($name)), 'id');
            $this->assertSame($generated, $recorded, "expected/$name.json matches the generated requests");
            $known = Fixture::load($name)->meta['known_v4_warnings'] ?? [];
            foreach ($data['requests'] as $request) {
                $errors = array_values(array_filter($request['observation']['php_errors'] ?? [], static function (string $error) use ($known): bool {
                    foreach ($known as $pattern) {
                        if (str_contains($error, $pattern)) {
                            return false;
                        }
                    }
                    return true;
                }));
                $this->assertSame([], $errors, "v4 printed no unexpected PHP errors for {$request['id']}");
            }
        }
    }

    /**
     * Git does not keep empty directories, so a fixture that relies on one would
     * differ between a working copy and a fresh clone (CI).  Put a hidden file
     * (".gitkeep") in directories that must look empty: hidden files are skipped
     * by both v4 and Cal5.
     */
    public function testFixturesHaveNoEmptyDirectories(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(Fixture::DIR, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $empty = [];
        foreach ($iterator as $path => $info) {
            if ($info->isDir() && !$info->isLink() && (scandir((string) $path) ?: []) === ['.', '..']) {
                $empty[] = substr((string) $path, strlen(Fixture::DIR) + 1);
            }
        }
        $this->assertSame([], $empty, 'empty fixture directories (add a .gitkeep)');
    }

    public function testFixtureMetadata(): void
    {
        $this->assertSame(['access-rules', 'core-tests', 'edge-cases', 'kitchen-sink', 'sample'], Fixture::names());
        foreach (Fixture::names() as $name) {
            $meta = Fixture::load($name)->meta;
            foreach (['description', 'entry', 'secret', 'password', 'types', 'markers'] as $field) {
                $this->assertTrue(isset($meta[$field]), "$name: fixture.json has $field");
            }
            $this->assertTrue(is_file(Fixture::DIR . "/$name/" . $meta['entry']), "$name: entry file exists");
        }
    }
}
