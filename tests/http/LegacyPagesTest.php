<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

use Cal5\Tests\Fixture;
use Cal5\Tests\HttpClient;
use Cal5\Tests\Server;
use Cal5\Tests\TestCase;

/**
 * Milestone 8: course pages written for v4's front end.  The legacy-content fixture's
 * pages use Bootstrap/Skeleton classes, glyphicons, jQuery, DataTables and Chart.js;
 * tests/browser/check.js shows they work in a browser, these tests check what each page
 * loads, and the audit command.
 */
final class LegacyPagesTest extends TestCase
{
    private const CAL5 = __DIR__ . '/../..';
    private const JQUERY = 'public/vendor/legacy/jquery/jquery.min.js';
    private const DATATABLES = 'public/vendor/legacy/datatables/datatables.min.js';
    private const CHART = 'public/vendor/legacy/chartjs/Chart.bundle.min.js';

    /** @var list<string> */
    private array $courses = [];
    /** @var list<Server> */
    private array $servers = [];

    public function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        foreach ($this->courses as $course) {
            Fixture::remove($course);
        }
        $this->servers = [];
        $this->courses = [];
    }

    private function serve(string $course): HttpClient
    {
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        return new HttpClient($server);
    }

    public function testV4CourseLoadsLegacyScriptsWherePagesUseThem(): void
    {
        $client = $this->serve(Fixture::load('legacy-content')->install(self::CAL5));
        $bootstrap = $client->get('calendar.php?type=class&event=1');
        $this->assertNoPhpErrors($bootstrap);
        $this->assertContains('calendar/public/css/compat.css', $bootstrap->body, 'v4 configs get compat.css');
        $this->assertNotContains('public/vendor/legacy', $bootstrap->body, 'no scripts on a page that uses only classes');
        $this->assertNotContains('bootstrap.min', $bootstrap->body, 'Bootstrap itself is never loaded');

        $scripts = $client->get('calendar.php?type=class&event=3');
        $this->assertContains(self::JQUERY, $scripts->body);
        $this->assertContains(self::DATATABLES, $scripts->body);
        $this->assertContains('public/vendor/legacy/datatables/datatables.min.css', $scripts->body);
        $this->assertNotContains(self::CHART, $scripts->body);
        $this->assertTrue(strpos($scripts->body, self::JQUERY) < strpos($scripts->body, '$(function'), 'jQuery comes before the page\'s scripts');
        $this->assertTrue(strpos($scripts->body, self::JQUERY) < strpos($scripts->body, self::DATATABLES));

        $chart = $client->get('calendar.php?type=lab&event=1');
        $this->assertContains(self::CHART, $chart->body);
        $this->assertNotContains(self::JQUERY, $chart->body);
        $this->assertContains('docs/course.js', $chart->body, 'the course\'s own script ($CUSTOMJS lab)');

        foreach (['calendar.php?type=class&event=4', 'calendar.php?show=calendar_display'] as $plain) {
            $this->assertNotContains('public/vendor/legacy', $client->get($plain)->body, "$plain: nothing legacy");
        }
        foreach ([self::JQUERY, self::DATATABLES, self::CHART, 'public/css/compat.css'] as $file) {
            $this->assertSame(200, $client->get('calendar/' . $file)->status, "$file is served");
        }
    }

    public function testNewCoursesChooseThemselves(): void
    {
        $ini = static fn (string $features): string => ";<?php http_response_code(404); exit; ?>\n[course]\nnumber = N1\n"
            . "[semester]\nstart = 2020-08-24\nend = 2020-12-18\n[schedule]\nmon = class\n"
            . "[security]\npassword = pw\nsecret = 0123456789abcdef0123456789abcdef0123456789abcdef\n" . $features;
        $page = "<p class=\"alert alert-info\">x</p><script>$('p').hide();</script>";
        $files = ['index.php' => "<?php require __DIR__ . '/calendar/index.php';\n", 'class/1.A/index.html' => $page];

        $off = $this->serve(Fixture::temporaryCourse($files + ['calendar.ini.php' => $ini('')], self::CAL5))->get('index.php?type=class&event=1');
        $this->assertNotContains('compat.css', $off->body, 'new courses: off unless they ask');
        $this->assertNotContains('public/vendor/legacy', $off->body);

        $on = $this->serve(Fixture::temporaryCourse($files + ['calendar.ini.php' => $ini("[features]\nlegacy_css = yes\nlegacy_js = chartjs, auto\n")], self::CAL5));
        $page = $on->get('index.php?type=class&event=1');
        $this->assertContains('compat.css', $page->body);
        $this->assertContains(self::JQUERY, $page->body, 'auto: the page uses jQuery');
        $this->assertContains(self::CHART, $page->body, 'listed: on every page');
        $this->assertContains(self::CHART, $on->get('index.php?show=calendar_display')->body);
        $this->assertNotContains(self::JQUERY, $on->get('index.php?show=calendar_display')->body);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private static function cal5(string $course, string $arguments): array
    {
        $process = proc_open(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=-1 calendar/bin/cal5 ' . $arguments,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $course);
        if (!is_resource($process)) {
            return [-1, '', 'could not start'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testAudit(): void
    {
        $course = Fixture::load('legacy-content')->install(self::CAL5);
        $this->courses[] = $course;
        [$code, $out, $err] = self::cal5($course, 'audit');
        $this->assertSame('', $err, 'no PHP warnings');
        $this->assertSame(1, $code, 'something needs attention');
        foreach (['panel-primary', 'table-striped', 'col-md-8', 'jumbotron', 'two-thirds', 'button-primary', 'download-alt',
                  'jQuery', 'DataTables', 'Chart.js', 'legacy_css = yes', 'legacy_js  = auto'] as $expected) {
            $this->assertContains($expected, $out, "reports $expected");
        }
        $this->assertContains("Bootstrap's JavaScript", $out);
        $this->assertContains('class/01.Bootstrap/homework.html', $out, 'data-toggle and Font Awesome are found');
        $this->assertContains('Font Awesome icons (fa fa-...): not provided by Cal5', $out);
        $this->assertContains('links to v4 files (calendar/...): not provided by Cal5 (class/02.Skeleton/index.html)', $out);

        [$code, $json] = self::cal5($course, 'audit --json');
        $report = json_decode($json, true);
        $this->assertSame(1, $code);
        $this->assertTrue(is_array($report) && isset($report['findings']['bootstrap classes (compat.css)']['alert-info']), 'JSON report');
        $this->assertSame(['jquery', 'datatables', 'chartjs'], $report['libraries'] ?? null);
        $this->assertSame(['class/03.Scripts/index.html'], $report['findings']['scripts']['DataTables'] ?? null);

        $clean = Fixture::load('kitchen-sink')->install(self::CAL5);
        $this->courses[] = $clean;
        [$code, $out] = self::cal5($clean, 'audit');
        $this->assertSame(0, $code, 'nothing needs attention');
        $this->assertContains('Nothing needs attention', $out);
        $this->assertContains('<calphp> (runs PHP)', $out);
        $this->assertContains('legacy_css = no', $out);
    }
}
