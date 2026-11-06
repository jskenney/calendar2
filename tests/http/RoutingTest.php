<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

use Cal5\Tests\Fixture;
use Cal5\Tests\HttpClient;
use Cal5\Tests\Server;
use Cal5\Tests\TestCase;

/**
 * Milestone 9: every v4 URL form.  That each one shows what v4 shows is checked by the
 * full compatibility suite (php tests/compat/compat.php check --impl=cal5); these tests
 * cover the details and what is new: the start page, ?load=, search and keywords, the
 * instructor's status page and "view as a student", debug, the access log, and the error
 * and lock pages.
 */
final class RoutingTest extends TestCase
{
    private const CAL5 = __DIR__ . '/../..';
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';

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

    private function serve(string $course): Server
    {
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        return $server;
    }

    private function coreTests(): Server
    {
        return $this->serve(Fixture::load('core-tests')->install(self::CAL5));
    }

    private static function instructor(Server $server, string $entry = 'calendar.php', string $password = 'testpass'): HttpClient
    {
        $client = new HttpClient($server);
        $client->get("$entry?password=$password");
        return $client;
    }

    public function testStartPageAndLoadPages(): void
    {
        $server = $this->coreTests();
        $student = new HttpClient($server);
        $this->assertContains('HOME-PAGE', $student->get('calendar.php')->body, 'nothing asked for: home');
        foreach (['type=class&event=02', 'type=nosuch&event=1', 'type=exam&event[]=1', 'key=xyz&type=class&event=1'] as $bad) {
            $this->assertContains('HOME-PAGE', $student->get("calendar.php?$bad")->body, "$bad: invalid, so the home page (v4)");
        }
        $this->assertContains('EXTRA-PAGE', $student->get('calendar.php?load=extra')->body, '$LOAD_PAGES');
        $md = $student->get('calendar.php?load=mdpage');
        $this->assertContains('<div class="cal4-markdown">', $md->body);
        $this->assertContains('Root <strong>page</strong> for TST101', $md->body, 'Markdown, with <replace>');
        foreach (['notlisted', '../calendar', 'instructor', 'calendar_main'] as $page) {
            $body = $student->get('calendar.php?load=' . rawurlencode($page))->body;
            $this->assertNotContains('NOT-LISTED-PAGE', $body, "$page: not on the list");
            $this->assertNotContains('Instructor guide', $body, "$page: not for students");
        }
        $this->assertContains('Instructor guide', self::instructor($server)->get('calendar.php?load=instructor')->body, "the instructor's guide");
    }

    public function testTodaysLectureStartPage(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/New_York')))->format('Y-m-d');
        $server = $this->serve(Fixture::temporaryCourse([
            'index.php' => "<?php require __DIR__ . '/calendar/index.php';\n",
            'calendar.ini.php' => ";<?php http_response_code(404); exit; ?>\n[course]\nnumber = T1\ntimezone = America/New_York\n"
                . "[semester]\nstart = $today\nend = $today\nweekends = yes\n[schedule]\nmon = class\ntue = class\nwed = class\nthu = class\nfri = class\nsat = class\nsun = class\n"
                . "[security]\npassword = pw\nsecret = " . self::SECRET . "\n[display]\nstart_page = today\n",
            'class/1.Today/index.html' => '<p>TODAY-LECTURE</p>',
            'home.html' => '<p>HOME</p>',
        ], self::CAL5));
        $page = (new HttpClient($server))->get('index.php');
        $this->assertContains('TODAY-LECTURE', $page->body, "display.start_page = today: today's lecture");
        $this->assertNotContains('HOME', $page->body);
        $this->assertContains('HOME', (new HttpClient($server))->get('index.php?load=home')->body);
    }

    public function testSearchAndKeywords(): void
    {
        $server = $this->serve(Fixture::load('kitchen-sink')->install(self::CAL5));
        $student = new HttpClient($server);
        $found = $student->get('calendar.php?show=calendar_search&search=ks-CLASS1-index');
        $this->assertContains('1 class page containing', $found->body, 'not case sensitive');
        $this->assertContains('href="calendar.php?type=class&amp;event=1"', $found->body);
        $this->assertContains('value="ks-CLASS1-index"', $found->body, 'the term stays in the box');
        $this->assertContains('0 class pages containing', $student->get('calendar.php?show=calendar_search&search=KS-class5')->body, 'hidden pages are not searched');
        $this->assertContains('1 class page containing', self::instructor($server, 'calendar.php', 'kitchenpass')->get('calendar.php?show=calendar_search&search=KS-class5')->body, 'the instructor finds them');
        $this->assertContains('Type a word', $student->get('calendar.php?show=calendar_search&search=')->body);
        $escaped = $student->get('calendar.php?show=calendar_search&search=%3Cb%3E')->body;
        $this->assertContains('<mark>&lt;b&gt;</mark>', $escaped, 'the term is escaped');
        $this->assertNotContains('<mark><b>', $escaped);

        $index = $student->get('calendar.php?show=calendar_keyword');
        $this->assertContains('keyword=oldanchor', $index->body, 'the keywords pages define (<a name>)');
        $this->assertContains('1 class page with the keyword', $student->get('calendar.php?show=calendar_keyword&keyword=OLDANCHOR')->body);
        $this->assertContains('0 class pages with the keyword', $student->get('calendar.php?show=calendar_keyword&keyword=tags')->body, 'ids are not keywords (v4)');
    }

    public function testStatusPageAndViewAsStudent(): void
    {
        $server = $this->serve(Fixture::load('kitchen-sink')->install(self::CAL5));
        $student = new HttpClient($server);
        $this->assertNotContains('cal5-status', $student->get('calendar.php?navbaronly=1&type=class&event=1')->body, 'instructor only');
        $instructor = self::instructor($server, 'calendar.php', 'kitchenpass');
        $status = $instructor->get('calendar.php?navbaronly=1&type=class&event=1');
        $this->assertContains('<table class="cal5-status">', $status->body);
        $this->assertContains('instructor.html', $status->body);
        $this->assertTrue((bool) preg_match('/instructor\.html.*?never/s', $status->body), 'release windows (*/instructor never)');
        $this->assertNotContains('KS-class1-index', $status->body, 'navbaronly: no page content (v4)');
        $this->assertContains('<input type="hidden" name="as" value="student">', $status->body, 'view as a student (a form, so no extra links)');
        $this->assertContains('Release status of these files', $instructor->get('calendar.php?type=class&event=1')->body, 'linked from the Files menu');
        $this->assertContains('Open an event', $instructor->get('calendar.php?show=calendar_file_selector')->body);

        $preview = $instructor->get('calendar.php?type=class&event=1&as=student');
        $this->assertContains('You are seeing this page as a student would now.', $preview->body);
        $this->assertContains('data-instructor="no"', $preview->body);
        $this->assertNotContains('KS-class1-inst', $preview->body, 'as a student');
        $this->assertContains('href="calendar.php?type=class&amp;event=1"', $preview->body, 'the way back');
        $this->assertContains('data-instructor="yes"', $instructor->get('calendar.php?type=class&event=1')->body, 'still logged in');
        $this->assertNotContains('You are seeing', $student->get('calendar.php?type=class&event=1&as=student')->body, 'nothing for students');

        $editor = $instructor->get('calendar.php?show=calendar_file_editor');
        $this->assertContains('The file editor has been removed.', $editor->body);
        $this->assertContains('KS-class1-index', $instructor->get('calendar.php?type=class&event=1&edit=1')->body, '?edit is ignored');
    }

    public function testDebugView(): void
    {
        $server = $this->coreTests();
        $instructor = self::instructor($server);
        $debug = $instructor->get('debug.php?type=class&event=1&debug=1&lock=y&csrf=z');
        $this->assertContains('<section class="cal5-debug"', $debug->body);
        $this->assertContains('index.html', $debug->body, "the event's files");
        $this->assertNotContains('testpass', $debug->body, 'never the password');
        $this->assertNotContains('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', $debug->body, 'never the secret');
        $this->assertContains('(removed)', $debug->body, 'secret parameters are not shown');
        $this->assertNoPhpErrors($debug);
        $this->assertNotContains('cal5-debug', (new HttpClient($server))->get('debug.php?type=class&event=1&debug=1')->body, 'students: no');
        $this->assertNotContains('cal5-debug', self::instructor($server)->get('calendar.php?type=class&event=1&debug=1')->body, 'features.debug off: no');
    }

    public function testAccessLog(): void
    {
        $server = $this->coreTests();
        $client = new HttpClient($server);
        $client->get('calendar.php?type=class&event=1&lock=sesame');
        $client->post('calendar.php', ['password' => 'wrong']);
        $client->get('calendar.php');
        $log = (string) file_get_contents($server->docRoot . '/logs/access.log');
        $this->assertContains('type=class&event=1&lock=REMOVED', $log, 'lock codes are not logged');
        $client->get('calendar.php?password=wrong');
        $this->assertContains('password=REMOVED', (string) file_get_contents($server->docRoot . '/logs/access.log'), 'passwords are not logged');
        $log = (string) file_get_contents($server->docRoot . '/logs/access.log');
        $this->assertNotContains('sesame', $log);
        $this->assertNotContains('wrong', $log);
        $this->assertTrue((bool) preg_match('/^\d{8}-\d\d:\d\d:\d\d 127\.0\.0\.1 home$/m', $log), 'v4 format: date address query (home without one)');
    }

    public function testErrorAndLockPages(): void
    {
        $course = Fixture::load('core-tests')->install(self::CAL5);
        $server = $this->serve($course);
        $student = new HttpClient($server);
        $this->assertContains('This course site is locked.', $student->get('locked.php?type=class&event=1')->body);
        file_put_contents("$course/lock.html", '<p>COURSE-LOCK-PAGE</p>');
        $this->assertContains('COURSE-LOCK-PAGE', $student->get('locked.php')->body, "the course's own lock page");

        unlink("$course/virtual/cal4.access");
        mkdir("$course/virtual/cal4.access"); // exists but can not be read as a file
        $error = $student->get('calendar.php?type=class&event=1');
        $this->assertContains('The release rules can not be read.', $error->body, 'v4: the error page, no content');
        $this->assertNotContains('class/01', $error->body);
        $this->assertNoPhpErrors($error);
    }
}
