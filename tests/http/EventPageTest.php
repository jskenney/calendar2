<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

use Cal5\Tests\Fixture;
use Cal5\Tests\HttpClient;
use Cal5\Tests\Server;
use Cal5\Tests\TestCase;

/**
 * Milestone 6 acceptance: event pages and their files.  Every event request of
 * every fixture is also compared with v4 by the compatibility suite
 * (php tests/compat/compat.php check --scope=events); these tests cover what
 * that comparison does not see: headers, ranges, layouts and new courses.
 */
final class EventPageTest extends TestCase
{
    private const KS_SECRET = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';
    private const NEW_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';

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

    private function kitchenSink(): Server
    {
        $course = Fixture::load('kitchen-sink')->install(__DIR__ . '/../..');
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        return $server;
    }

    private static function key(string $file, string $dir = '01.Welcome', string $type = 'class'): string
    {
        return sha1(self::KS_SECRET . $type . $dir . $file);
    }

    private static function fileUrl(string $file, string $dir = '01.Welcome', int $event = 1): string
    {
        return 'calendar.php?key=' . self::key($file, $dir) . "&type=class&event=$event";
    }

    public function testStudentAndInstructorPages(): void
    {
        $server = $this->kitchenSink();
        $student = new HttpClient($server);
        $page = $student->get('calendar.php?type=class&event=1');
        $this->assertSame(200, $page->status);
        $this->assertNoPhpErrors($page);
        $this->assertContains('data-instructor="no"', $page->body);
        $this->assertContains('KS-class1-index', $page->body);
        $this->assertContains('KS-class1-calphp-ran', $page->body, '<calphp> ran');
        $this->assertContains('calendar/public/vendor/mathjax/tex-chtml.js', $page->body, 'MathJax on every page');
        $this->assertNotContains('cdn.', $page->body, 'nothing from a CDN');
        // every library file the head names is there (and is served)
        preg_match_all('#(?:src|href)="calendar/(public/[^"]+)"#', $page->body, $assets);
        $this->assertTrue(count($assets[1]) >= 5, 'theme, highlight.js (script and style), MathJax, Ace');
        foreach ($assets[1] as $asset) {
            $this->assertTrue(is_file(__DIR__ . '/../../' . $asset), "$asset exists");
            $this->assertSame(200, $student->get('calendar/' . $asset)->status, "$asset is served");
        }
        foreach (['mathjax-newcm-font/chtml.js', 'mathjax-newcm-font/chtml/woff2', 'mathjax-mhchem-font-extension/chtml.js',
                  'mathjax/input/tex/extensions/mathtools.js', 'ace/mode-python.js', 'ace/theme-tomorrow.js'] as $file) {
            $this->assertTrue(file_exists(__DIR__ . '/../../public/vendor/' . $file), "vendor/$file");
        }
        $this->assertContains('vendor/ace/ace.js', $page->body, 'Ace on a page with <ace>');
        $this->assertNotContains('KS-class1-inst', $page->body);
        $this->assertNotContains('KS-class1-student', $page->body);
        $this->assertNotContains('KS-class1-locked', $page->body);
        $this->assertContains('name="lock"', $page->body, 'the unlock form');
        $this->assertNotContains('jquery', strtolower($page->body), 'no jQuery');
        $this->assertNotContains('bootstrap', strtolower($page->body), 'no Bootstrap');
        $this->assertNotContains('>Files<', $page->body, 'students get no file list');
        $this->assertContains('href="calendar.php?type=class&amp;event=1&amp;answers=yes"', $page->body, 'answers link');
        $this->assertContains('href="#tags"', $page->body, 'section menu');
        $this->assertNotContains('vendor/ace', $student->get('calendar.php?type=class&event=7')->body, 'Ace only where it is used');

        $answers = $student->get('calendar.php?type=class&event=1&answers=yes');
        $this->assertContains('KS-class1-student', $answers->body);
        $this->assertContains('>Hide answers<', $answers->body);

        $this->assertNotContains('KS-class5', $student->get('calendar.php?type=class&event=5')->body, 'not released yet');
        $hidden = $student->get('calendar.php?type=class&event=6');
        $this->assertSame(200, $hidden->status);
        $this->assertNotContains('KS-class6', $hidden->body);
        $this->assertNoPhpErrors($student->get('calendar.php?type=class&event=99'), 'no such event');

        $instructor = new HttpClient($server);
        $instructor->login('calendar.php', 'kitchenpass');
        $inst = $instructor->get('calendar.php?type=class&event=1');
        $this->assertContains('data-instructor="yes"', $inst->body);
        $this->assertContains('KS-class1-inst', $inst->body);
        $this->assertContains('>Files<', $inst->body);
        $this->assertContains(self::key('hello.php'), $inst->body, 'the instructor sees every file');
        $this->assertContains('KS-class5', $instructor->get('calendar.php?type=class&event=5')->body);
        $this->assertNoPhpErrors($inst);
    }

    public function testFilesAndDownloads(): void
    {
        $server = $this->kitchenSink();
        $client = new HttpClient($server);
        $pdf = $client->get(self::fileUrl('handout.pdf'));
        $this->assertSame(200, $pdf->status);
        $this->assertSame('application/pdf', $pdf->header('Content-Type'));
        $this->assertSame('filename="handout.pdf"', $pdf->header('Content-Disposition'), 'PDFs open in the browser');
        $this->assertSame((string) strlen($pdf->body), $pdf->header('Content-Length'));
        $this->assertSame('bytes', $pdf->header('Accept-Ranges'));
        $this->assertSame('nosniff', $pdf->header('X-Content-Type-Options'));

        $pptx = $client->get(self::fileUrl('slides.pptx'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.presentationml.presentation', $pptx->header('Content-Type'));
        $this->assertSame('attachment; filename="slides.pptx"', $pptx->header('Content-Disposition'));
        $this->assertSame('application/vnd.ms-powerpoint', $client->get(self::fileUrl('deck.ppt'))->header('Content-Type'));

        $video = $client->get(self::fileUrl('clip.mp4'));
        $this->assertSame('video/mp4', $video->header('Content-Type'));
        $part = $client->get(self::fileUrl('clip.mp4'), ['Range: bytes=2-5']);
        $this->assertSame(206, $part->status, 'a range (video seeking)');
        $this->assertSame(substr($video->body, 2, 4), $part->body);
        $this->assertSame('bytes 2-5/' . strlen($video->body), $part->header('Content-Range'));
        $this->assertSame('4', $part->header('Content-Length'));
        $this->assertSame(416, $client->get(self::fileUrl('clip.mp4'), ['Range: bytes=500-'])->status);
        $head = $client->head(self::fileUrl('clip.mp4'));
        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);
        $this->assertSame((string) strlen($video->body), $head->header('Content-Length'));

        $text = $client->get(self::fileUrl('notes.txt'));
        $this->assertSame('text/html; charset=UTF-8', $text->header('Content-Type'), '.txt files are pages');
        $this->assertContains('<pre class="cal5-text">KS-class1-notes-inline', $text->body);

        $nested = $client->get(self::fileUrl('sub/nested.html'));
        $this->assertContains('KS-class1-nested', $nested->body);
        $this->assertNoPhpErrors($nested);

        $source = $client->get(self::fileUrl('hello.php'));
        $this->assertSame(200, $source->status);
        $this->assertNotContains('<?php', $source->body, 'students never get PHP source');
        $this->assertNotContains('KS-class1-index', $source->body, 'and no title page either (v4)');

        $other = $client->get('calendar.php?key=' . self::key('notes.md', '02.Markdown') . '&type=class&event=1');
        $this->assertNotContains('KS-class2', $other->body, 'a key of another event serves nothing');
        $this->assertNotContains('KS-class1-index', $other->body);
        $unknown = $client->get('calendar.php?key=' . str_repeat('0', 40) . '&type=class&event=1');
        $this->assertContains('KS-class1-index', $unknown->body, 'an unknown key falls back to the title (v4)');

        $instructor = new HttpClient($server);
        $instructor->login('calendar.php', 'kitchenpass');
        $script = $instructor->get(self::fileUrl('hello.php'));
        $this->assertSame('application/octet-stream', $script->header('Content-Type'), 'the instructor can download scripts');
        $this->assertContains('<?php', $script->body);
    }

    public function testLayouts(): void
    {
        $client = new HttpClient($this->kitchenSink());
        $full = $client->get('calendar.php?type=class&event=7');
        $this->assertContains('class="cal5-nav"', $full->body);
        $print = $client->get('calendar.php?type=class&event=7&print=1');
        $this->assertNotContains('class="cal5-nav"', $print->body, 'print: no navbar');
        $this->assertContains('cal5.css', $print->body, 'print: still styled');
        $this->assertContains('KS-class7', $print->body);
        $bare = $client->get('calendar.php?type=class&event=7&nocss=1');
        $this->assertNotContains('<html', $bare->body, 'nocss: the content only');
        $this->assertContains('KS-class7', $bare->body);
    }

    public function testLockCodesAndUrlValues(): void
    {
        $client = new HttpClient($this->kitchenSink());
        $this->assertNotContains('KS-class1-locked', $client->get('calendar.php?type=class&event=1')->body);
        $this->assertContains('KS-class1-locked', $client->get('calendar.php?type=class&event=1&lock=opensesame')->body);
        $this->assertContains('KS-class1-locked', $client->get('calendar.php?type=class&event=1')->body, 'remembered');
        $this->assertContains('Hi KS-me', $client->get('calendar.php?type=class&event=1&who=KS-me')->body);
        $this->assertContains('Hi &lt;x&gt;', $client->get('calendar.php?type=class&event=1&who=%3Cx%3E')->body, 'escaped');
        $this->assertContains('Hi &lt;x&gt;', $client->get('calendar.php?type=class&event=1')->body, 'remembered');
    }

    public function testNewCourseLinksAndHtmFiles(): void
    {
        $key = static fn (string $file): string => sha1(self::NEW_SECRET . 'class' . '1.Intro' . $file);
        $course = Fixture::temporaryCourse([
            'index.php' => "<?php require __DIR__ . '/calendar/index.php';\n",
            'calendar.ini.php' => ";<?php http_response_code(404); exit; ?>\n[course]\nnumber = NEW1\nname = New\n"
                . "[semester]\nstart = 2020-08-24\nend = 2020-12-18\n[schedule]\nmon = class\n"
                . "[security]\npassword = pw\nsecret = " . self::NEW_SECRET . "\n",
            'class/1.Intro/index.html' => '<h2 id="start">NEW-index</h2><a href="slides.pdf">slides</a> <a href="bare.htm">bare</a>',
            'class/1.Intro/slides.pdf' => '%PDF-1.4 NEW',
            'class/1.Intro/bare.htm' => '<p>NEW-bare</p>',
            'class/1.Intro/notes.md' => "## Notes\n\nNEW-md \\(x\\)",
        ], __DIR__ . '/../..');
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        $client = new HttpClient($server);

        $page = $client->get('index.php?type=class&event=1');
        $this->assertNoPhpErrors($page);
        $this->assertContains('NEW-index', $page->body);
        $this->assertContains('href="index.php?key=' . $key('slides.pdf') . '&type=class&event=1"', $page->body, 'links use the course entry');
        $this->assertContains('href="index.php?type=class&amp;event=1"', $page->body, 'event menu');
        $this->assertSame('%PDF-1.4 NEW', $client->get('index.php?key=' . $key('slides.pdf') . '&type=class&event=1')->body);

        $bare = $client->get('index.php?key=' . $key('bare.htm') . '&type=class&event=1');
        $this->assertSame('<p>NEW-bare</p>', $bare->body, '.htm files are sent without the layout (v4)');

        $md = $client->get('index.php?key=' . $key('notes.md') . '&type=class&event=1');
        $this->assertContains('<div class="cal4-markdown">', $md->body);
        $this->assertContains('<h2 id="notes">Notes</h2>', $md->body);
        $this->assertContains('NEW-md \\(x\\)', $md->body);
    }
}
