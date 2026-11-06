<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Access\EventContext;
use Cal5\Auth\ArraySession;
use Cal5\Config\Config;
use Cal5\Config\IniConfigLoader;
use Cal5\Config\MeetingTime;
use Cal5\Content\ContentFile;
use Cal5\Content\FileKind;
use Cal5\Http\Download;
use Cal5\Http\PageRequest;
use Cal5\Http\Request;
use Cal5\Render\HeadingIndex;
use Cal5\Render\Markdown;
use Cal5\Render\PageContext;
use Cal5\Render\TagPipeline;
use Cal5\Tests\TestCase;
use Cal5\View\EventPage;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Milestone 6: the tag pipeline, Markdown, headings, downloads and request
 * validation.  How pages compare with v4 is checked by the compatibility suite
 * (tests/compat, scope "events"); these tests pin down the details.
 */
final class RenderTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private ?string $dir = null;

    public function tearDown(): void
    {
        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
            $this->dir = null;
        }
    }

    private static function config(string $sections = ''): Config
    {
        $result = IniConfigLoader::fromString("[course]\nnumber = T1\nname = Test\ntimezone = America/New_York\n"
            . "[semester]\nstart = 2026-08-24\nend = 2026-12-18\n[schedule]\nmon = class\n"
            . "[security]\npassword = pw\nsecret = " . self::SECRET . "\n" . $sections);
        if ($result->config === null) {
            throw new \RuntimeException(implode('; ', array_map('strval', $result->errors)));
        }
        return $result->config;
    }

    /**
     * Event files written to a temporary directory.
     *
     * @param array<string, string> $files name => contents
     * @return array<string, ContentFile>
     */
    private function files(array $files): array
    {
        $this->dir ??= sys_get_temp_dir() . '/cal5-render-' . bin2hex(random_bytes(4));
        @mkdir($this->dir);
        $result = [];
        foreach ($files as $name => $contents) {
            file_put_contents($this->dir . '/' . $name, $contents);
            $result[$name] = new ContentFile($name, $this->dir . '/' . $name, sha1($name), FileKind::fromPath($name), null, false);
        }
        return $result;
    }

    /**
     * @param array<string, ContentFile> $files
     * @param array<string, string> $query
     */
    private static function page(array $files = [], bool $instructor = false, array $query = [], ?Config $config = null,
        ?ArraySession $session = null, string $now = '2026-09-01 12:00', ?string $eventDate = '2026-08-31', ?MeetingTime $meeting = null): PageContext
    {
        $zone = new DateTimeZone('America/New_York');
        return new PageContext(
            config: $config ?? self::config(),
            instructor: $instructor,
            request: new Request($query),
            session: $session ?? new ArraySession(),
            files: $files,
            type: 'class',
            event: 2,
            replace: ['course' => 'T1', 'odd' => 'a<b & "c"'],
            eventContext: new EventContext($eventDate, $meeting, $zone, 2026),
            now: new DateTimeImmutable($now, $zone),
            phpVariables: ['COURSE' => 'T1'],
        );
    }

    private static function render(string $html, PageContext $page): string
    {
        return (new TagPipeline('www.example.edu', '10.0.0.1'))->process($html, $page)->html;
    }

    public function testLinksToEventFilesBecomeKeyedLinks(): void
    {
        $files = $this->files(['a.png' => 'png', 'notes.pdf' => 'pdf']);
        $html = self::render('<img src="a.png"> <a href=\'notes.pdf\'>n</a> <A HREF="notes.pdf">upper</A> <a href="other.pdf">x</a> <showlink src="notes.pdf">', self::page($files));
        $this->assertContains('<img src="calendar.php?key=' . sha1('a.png') . '&type=class&event=2">', $html);
        $this->assertContains("<a href='calendar.php?key=" . sha1('notes.pdf') . "&type=class&event=2'>n</a>", $html, 'single quotes');
        $this->assertContains('<a href="calendar.php?key=' . sha1('notes.pdf') . '&type=class&event=2">upper</A>', $html, 'case-insensitive, as v4');
        $this->assertContains('<a href="other.pdf">x</a>', $html, 'files the viewer may not see keep their link');
        $this->assertContains(' calendar.php?key=' . sha1('notes.pdf') . '&type=class&event=2', $html, '<showlink> becomes the URL');
    }

    public function testReplaceInjectAndCodeinject(): void
    {
        $files = $this->files(['part.html' => 'PART <replace value="course">', 'part.md' => '## Heading', 'code.py' => 'if a < b & c: print("<x>")']);
        $html = self::render('<replace value="course"> <replace value=\'odd\'/> <replace value="none"> <inject src="part.html"> <inject src="part.md" /> <codeinject src="code.py"> <inject src="missing.html">', self::page($files));
        $this->assertContains('T1 a&lt;b &amp; &quot;c&quot;', $html, 'values are escaped');
        $this->assertContains('<replace value="none">', $html, 'unknown values stay (v4)');
        $this->assertContains('PART T1', $html, 'injected files are processed too');
        $this->assertContains('<h2 id="heading">Heading</h2>', $html, 'injected Markdown is converted');
        $this->assertContains('if a &lt; b &amp; c: print("&lt;x&gt;")', $html, 'codeinject escapes');
        $this->assertContains('<inject src="missing.html">', $html);
    }

    public function testInjectionStopsAfterFivePasses(): void
    {
        $files = $this->files(['loop.html' => 'L<inject src="loop.html">']);
        $html = self::render('<inject src="loop.html">', self::page($files));
        $this->assertSame(str_repeat('L', 5) . '<inject src="loop.html">', $html, 'v4 resolves five levels');
    }

    public function testInstStudentAndAnswers(): void
    {
        $source = 'Q <inst>teacher</inst> <student>answer</student>';
        $student = (new TagPipeline())->process($source, self::page());
        $this->assertSame('Q  ', $student->html);
        $this->assertTrue($student->hasAnswers);
        $this->assertSame('Q  answer', self::render($source, self::page(query: ['answers' => 'yes'])));
        $this->assertSame('Q teacher ', self::render($source, self::page(instructor: true)));
    }

    public function testHostTags(): void
    {
        $html = self::render('<host server="other.edu, www.example.edu">A</host>|<host server="other.edu">B</host>|<host server="10.0.0.1">C</host>', self::page());
        $this->assertSame('<unhost>A</host>||<unhost>C</host>', $html, 'matching hosts keep their content (v4 markup)');
    }

    public function testLockCodes(): void
    {
        $session = new ArraySession();
        $locked = self::render('<lock code="red, blue">secret</lock>', self::page(session: $session));
        $this->assertNotContains('secret', $locked);
        $this->assertContains('name="lock"', $locked, 'students get the unlock form');
        $this->assertContains('name="csrf"', $locked);
        $session->set('lock', 'blue');
        $this->assertContains('secret', self::render('<lock code="red, blue">secret</lock>', self::page(session: $session)));
        $this->assertContains('secret', self::render('<lock code="red">secret</lock>', self::page(instructor: true)), 'the instructor sees everything');
    }

    public function testPostmeeting(): void
    {
        $source = 'A<postmeeting>after</postmeeting>';
        $meeting = new MeetingTime('08:00', '08:50');
        $this->assertSame('A', self::render($source, self::page(now: '2026-08-31 08:49', meeting: $meeting)), 'before the meeting ends');
        $this->assertSame('Aafter', self::render($source, self::page(now: '2026-08-31 08:50', meeting: $meeting)), 'at the end of the meeting');
        $this->assertSame('A', self::render($source, self::page(eventDate: null, now: '2027-01-01 00:00')), 'not on the calendar');
        $this->assertSame($source, self::render($source, self::page(instructor: true, eventDate: null)), 'the instructor sees the markup (v4)');
        $off = self::config("[features]\npostmeeting = off\n");
        $this->assertSame($source, self::render($source, self::page(config: $off, eventDate: null)));
    }

    public function testUrlValuesAreEscapedAndRemembered(): void
    {
        $session = new ArraySession();
        $source = '<url value="who" default="nobody">';
        $this->assertSame('nobody', self::render($source, self::page(session: $session)));
        $this->assertSame('&quot;&gt;&lt;x&gt;', self::render($source, self::page(query: ['who' => '"><x>'], session: $session)));
        $this->assertSame('&quot;&gt;&lt;x&gt;', self::render($source, self::page(session: $session)), 'remembered in the session');
    }

    public function testAceWithoutJquery(): void
    {
        $result = (new TagPipeline())->process('<ace name="ed1" mode="python" height="90px"><b>x</b></ace><ace name="bad\');alert(1)//">y</ace>', self::page());
        $this->assertTrue($result->usesAce);
        $this->assertContains('<div id="ed1" class="cal5-ace">&lt;b&gt;x&lt;/b&gt;</div>', $result->html);
        $this->assertContains('ace/mode/python', $result->html);
        $this->assertContains('#ed1 { height: 90px; }', $result->html);
        $this->assertNotContains('$(', $result->html, 'no jQuery');
        $this->assertNotContains("alert(1)", $result->html, 'unsafe names are not written into the script');
        $this->assertFalse((new TagPipeline())->process('<p>no editor</p>', self::page())->usesAce);
    }

    public function testTabsBecomeTwoSpaces(): void
    {
        $this->assertSame("a  b", self::render("a\tb", self::page()));
    }

    public function testCalphpRunsEventScripts(): void
    {
        $files = $this->files(['hello.php' => '<?php echo "ran in " . basename(getcwd()) . " for $COURSE" . ($INSTRUCTOR ? " (instructor)" : "");']);
        $cwd = getcwd();
        $html = self::render('A<calphp src="hello.php">B<calphp src="nosuch.php">C', self::page($files));
        $this->assertSame('Aran in ' . basename((string) $this->dir) . ' for T1BC', $html);
        $this->assertSame($cwd, getcwd(), 'the working directory is restored');
        $this->assertContains('(instructor)', self::render('<calphp src="hello.php">', self::page($files, instructor: true)));
    }

    public function testHeadings(): void
    {
        $index = HeadingIndex::find('<h1 id="intro-part">I</h1><a name="old"></a><h2 id="old">dup</h2><a id="skip"></a><h3 id="pagename:x">p</h3><h4>no id</h4>');
        $this->assertSame(['intro-part', 'old', 'pagename:x'], $index->anchors);
        $this->assertSame([['tag' => 'a', 'name' => 'old']], $index->oldAnchors);
        $this->assertSame(['intro-part' => 'Intro part', 'old' => 'Old'], $index->menu());
        $this->assertSame([], HeadingIndex::find('')->anchors);
    }

    public function testMarkdown(): void
    {
        $html = Markdown::convert("# Title\n\nSome *text* and `code` with \\(x^2\\).\n\n## Part two\n\n- a\n- b\n\n```python\nprint(1 < 2)\n```\n");
        $this->assertContains('<h1>Title</h1>', $html, 'only menu levels (default: 2) get ids');
        $this->assertContains('<em>text</em>', $html);
        $this->assertContains('<code>code</code>', $html);
        $this->assertContains('\\(x^2\\)', $html, 'MathJax delimiters are kept');
        $this->assertContains('<h2 id="part-two">Part two</h2>', $html);
        $this->assertContains('<h1 id="title">', Markdown::convert("# Title\n", [1, 2]), 'markdown_menu_levels');
        $this->assertContains('<li>a</li>', $html);
        $this->assertContains('print(1 &lt; 2)', $html);
    }

    public function testSourcesOfEachKind(): void
    {
        $files = $this->files(['a.txt' => "<b>raw</b>\n", 'b.md' => '## B', 'c.html' => '<p>c</p>']);
        $this->assertSame("<pre class=\"cal5-text\">&lt;b&gt;raw&lt;/b&gt;\n</pre>", EventPage::source($files['a.txt'], [2]));
        $this->assertSame("<div class=\"cal4-markdown\">\n<h2 id=\"b\">B</h2>\n</div>", EventPage::source($files['b.md'], [2]));
        $this->assertSame('<p>c</p>', EventPage::source($files['c.html'], [2]));
        $this->assertTrue(EventPage::isScript('x/y.PHP') && EventPage::isScript('a.phtml') && !EventPage::isScript('a.html'));
    }

    public function testDownloadTypes(): void
    {
        $this->assertSame(['application/pdf', true], Download::typeOf('a.pdf'));
        $this->assertSame(['application/vnd.ms-powerpoint', false], Download::typeOf('a.PPT'));
        $this->assertSame(['application/vnd.openxmlformats-officedocument.presentationml.presentation', false], Download::typeOf('a.pptx'));
        $this->assertSame(['image/jpeg', true], Download::typeOf('a.jpg'));
        $this->assertSame(['video/mp4', true], Download::typeOf('a.mp4'));
        $this->assertSame(['text/plain', true], Download::typeOf('Makefile'), 'no extension: text (v4)');
        $this->assertSame(['application/octet-stream', false], Download::typeOf('a.exe'));
    }

    public function testRanges(): void
    {
        $this->assertSame([0, 9], Download::range('bytes=0-9', 100));
        $this->assertSame([90, 99], Download::range('bytes=90-', 100));
        $this->assertSame([80, 99], Download::range('bytes=-20', 100));
        $this->assertSame([0, 99], Download::range('bytes=-500', 100));
        $this->assertSame([50, 99], Download::range('bytes=50-1000', 100));
        foreach (['bytes=100-', 'bytes=9-5', 'bytes=-0', 'bytes=-', 'bytes=0-1,5-6', 'items=0-1', ''] as $bad) {
            $this->assertSame(null, Download::range($bad, 100), $bad);
        }
    }

    public function testPageRequestValidation(): void
    {
        $types = ['class', 'lab'];
        $ok = PageRequest::from(new Request(['type' => 'class', 'event' => '12', 'key' => str_repeat('a', 40)]), $types);
        $this->assertSame(['class', 12, str_repeat('a', 40)], [$ok->type, $ok->event, $ok->key]);
        $this->assertTrue($ok->isEvent());
        foreach ([['event' => '02'], ['event' => '2.0'], ['event' => '0'], ['event' => '1234567'], ['type' => 'exam'],
                  ['key' => 'ABC'], ['event' => ['1']], ['key' => [str_repeat('a', 40)]]] as $bad) {
            $request = PageRequest::from(new Request($bad + ['type' => 'class', 'event' => '1']), $types);
            $this->assertSame([null, null, null], [$request->type, $request->event, $request->key], json_encode($bad) ?: '');
        }
        $this->assertFalse(PageRequest::from(new Request(['type' => 'class']), $types)->isEvent());
    }
}
