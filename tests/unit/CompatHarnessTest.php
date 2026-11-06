<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Tests\Compat\IntendedDifferences;
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
        $text = $observer->observe(new Response(200, ['HTTP/1.1 200 OK', 'Content-Type: text/plain'], 'X'));
        $this->assertSame([], $text['markers'], 'text downloads also record their markers');
    }

    public function testIntendedDifferences(): void
    {
        $txt = ['status' => 200, 'kind' => 'download', 'content_type' => 'text/plain', 'disposition' => 'inline', 'sha1' => 'x', 'markers' => ['M']];
        $page = ['status' => 200, 'kind' => 'page', 'markers' => ['M'], 'keyed_links' => ['k'], 'event_links' => ['type=a&event=1'], 'php_errors' => []];
        $this->assertSame([], Runner::compareOne('txt', $txt, $page), '.txt shown as a page with the same text');
        $this->assertSame(1, count(Runner::compareOne('txt', $txt, ['markers' => []] + $page)), 'the text must be there');

        $mp4 = ['status' => 200, 'kind' => 'download', 'content_type' => 'application/octet-stream', 'disposition' => 'attachment', 'sha1' => 's'];
        $this->assertSame([], Runner::compareOne('mp4', $mp4, ['content_type' => 'video/mp4', 'disposition' => 'inline'] + $mp4));
        $this->assertSame(2, count(Runner::compareOne('zip', $mp4, ['content_type' => 'application/zip', 'disposition' => 'inline'] + $mp4)), 'only audio and video');
        $jpg = ['content_type' => 'image/jpg', 'disposition' => 'inline'] + $mp4;
        $this->assertSame([], Runner::compareOne('jpg', $jpg, ['content_type' => 'image/jpeg'] + $jpg));
        $this->assertSame([$page, $page], IntendedDifferences::apply($page, $page), 'pages are compared unchanged');
        $dead = ['event_links' => ['type=a&event=1', 'type=holiday']] + $page;
        $this->assertSame([], Runner::compareOne('dead', $dead, $page), "v4's dead day links (no event number) are not expected");
        $this->assertSame([], Runner::compareOne('v4', $dead, $dead, [], false), 'v4 is compared with its own recording without the rules');
        $this->assertSame(1, count(Runner::compareOne('v4', $dead, $page, [], false)), 'and must still produce the dead links');
    }

    public function testEventsScope(): void
    {
        $fixture = Fixture::load('core-tests');
        $in = static fn (string $query, string $entry = 'calendar.php'): bool => Requests::inScope(['entry' => $entry, 'query' => $query], 'events', $fixture);
        $this->assertTrue($in('type=class&event=1'));
        $this->assertTrue($in('key=' . str_repeat('a', 40) . '&type=class&event=1'));
        $this->assertTrue($in('type=class&event=1&answers=yes&lock=x'));
        $this->assertFalse($in(''));
        $this->assertFalse($in('load=home'));
        $this->assertFalse($in('type=class&event=1&show=calendar_display'), 'calendar views are milestone 9');
        $this->assertFalse($in('type=class&event=02'), 'invalid requests show the home page');
        $this->assertFalse($in('type=nosuch&event=1'));
        $this->assertFalse($in('type=class&event=1', 'sample_secret.php'), 'error entries');
        $this->assertTrue(Requests::inScope(['entry' => 'x', 'query' => ''], 'all', $fixture));
        $calendar = static fn (string $query): bool => Requests::inScope(['entry' => 'calendar.php', 'query' => $query], 'calendars', $fixture);
        $this->assertTrue($calendar('show=calendar_display') && $calendar('show=calendar_display_alt') && $calendar('show=calendar_display_dateless'));
        $this->assertTrue($calendar('show=calendar_display&print=1'));
        $this->assertFalse($calendar('show=calendar_search&search=x'), 'search is milestone 9');
        $this->assertFalse($calendar('show=calendar_display&type=class&event=1'), 'with an event: milestone 9');
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
        $this->assertSame(['access-rules', 'core-tests', 'edge-cases', 'kitchen-sink', 'legacy-content', 'sample'], Fixture::names());
        foreach (Fixture::names() as $name) {
            $meta = Fixture::load($name)->meta;
            foreach (['description', 'entry', 'secret', 'password', 'types', 'markers'] as $field) {
                $this->assertTrue(isset($meta[$field]), "$name: fixture.json has $field");
            }
            $this->assertTrue(is_file(Fixture::DIR . "/$name/" . $meta['entry']), "$name: entry file exists");
        }
    }
}
