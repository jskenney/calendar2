<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

require_once __DIR__ . '/../browser/DemoCourse.php';

use Cal5\Tests\Browser\DemoCourse;
use Cal5\Tests\Fixture;
use Cal5\Tests\HttpClient;
use Cal5\Tests\Server;
use Cal5\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Milestone 7: the calendar views and the new header.  That the views show the same
 * events, links and inline text as v4 is checked by the compatibility suite
 * (compat.php check --scope=calendars); these tests cover the new parts: today,
 * release badges, the list/agenda options, the login form and locked sites.
 * The demo course's semester is placed around today, so "today" is always inside it.
 */
final class CalendarViewTest extends TestCase
{
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

    private function demo(): Server
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $course = Fixture::temporaryCourse(DemoCourse::files($today), __DIR__ . '/../..');
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        return $server;
    }

    public function testMonthViewForStudents(): void
    {
        $client = new HttpClient($this->demo());
        $page = $client->get('index.php?show=calendar_display');
        $this->assertSame(200, $page->status);
        $this->assertNoPhpErrors($page);
        $this->assertContains('id="today"', $page->body, 'today is marked');
        $this->assertContains('(today)', $page->body, 'and announced to screen readers');
        $this->assertContains('aria-current="page"', $page->body, 'the Calendar link is current');
        $this->assertContains('href="index.php?type=class&amp;event=1"', $page->body, 'released titles link to the event');
        $this->assertContains('<span class="cal5-entry-title">Extra</span>', $page->body, 'the last class is not released yet: its name, no link');
        $this->assertNotContains('cal5-badge-opens', $page->body, 'students do not see when hidden material opens');
        $this->assertNotContains('navbaronly', $page->body);
        $this->assertContains('cal5-badge-until', $page->body, 'material that locks again shows until when');
        $this->assertContains('Read: chapter', $page->body, 'inline boxes paste their text');
        $this->assertContains('class="cal5-tag calCore-cal-HOLIDAY">HOLIDAY</span>', $page->body, 'days without material show their label');
        $this->assertNotContains('<th scope="col"><abbr title="Saturday">', $page->body, 'no weekends');
        $this->assertNotContains('jquery', strtolower($page->body));
        $this->assertNotContains('bootstrap', strtolower($page->body));
    }

    public function testMonthViewForTheInstructor(): void
    {
        $server = $this->demo();
        $client = new HttpClient($server);
        $client->get('index.php?password=' . DemoCourse::PASSWORD);
        $page = $client->get('index.php?show=calendar_display');
        $this->assertContains('data-instructor="yes"', $page->body);
        $this->assertContains('cal5-badge-opens', $page->body, 'when hidden material opens');
        $this->assertContains('cal5-badge-closed', $page->body, 'material that is never shown (class_4/slides never)');
        $this->assertContains('&amp;navbaronly=1', $page->body, 'day numbers open the event (v4)');
        $this->assertContains('<a class="cal5-entry-title" href="index.php?type=class&amp;event=40">Extra</a>', $page->body, 'every title links');
        $this->assertContains('>Log out<', $page->body);
        $this->assertContains('Guide', $page->body, 'the instructor guide link');
        $this->assertNoPhpErrors($page);
    }

    public function testListAndAgendaViews(): void
    {
        $client = new HttpClient($this->demo());
        $list = $client->get('index.php?show=calendar_display_alt');
        $this->assertContains('class="cal5-row-date"', $list->body, 'the list view has dates');
        $this->assertContains('cal5-row cal5-c', $list->body);
        $this->assertNotContains('HOLIDAY', $list->body, 'v4 lists only days with material');
        $this->assertContains('<a href="index.php?show=calendar_display_alt" aria-current="page">', $list->body, 'view tabs');
        $agenda = $client->get('index.php?show=calendar_display_dateless');
        $this->assertContains('Available material', $agenda->body);
        $this->assertNotContains('class="cal5-row-date"', $agenda->body, 'the agenda view has no dates');
        $print = $client->get('index.php?show=calendar_display&print=1');
        $this->assertNotContains('class="cal5-header"', $print->body, 'print: no header');
        $this->assertContains('cal5-grid', $print->body);
        $this->assertNoPhpErrors($agenda);
    }

    public function testLoginFormHashesPlainPasswords(): void
    {
        $server = $this->demo();
        $client = new HttpClient($server);
        $page = $client->get('index.php?show=calendar_display');
        $this->assertTrue((bool) preg_match('/<form method="post" class="cal5-login" data-nonce="([0-9a-f]{64})" data-hash="sha256">/', $page->body, $m),
            'the form asks cal5.js to send sha256(password + nonce), as v4 did');
        $this->assertContains('<script defer src="calendar/public/js/cal5.js"></script>', $page->body);
        // what cal5.js sends
        $loggedIn = $client->post('index.php?show=calendar_display', ['password' => hash('sha256', DemoCourse::PASSWORD . $m[1]), 'csrf' => (string) HttpClient::formValue($page)]);
        $this->assertContains('data-instructor="yes"', $loggedIn->body);
    }

    public function testPasswordHashCoursesPostThePassword(): void
    {
        $hash = password_hash('s3cret', PASSWORD_DEFAULT);
        $files = DemoCourse::files(new DateTimeImmutable('today'));
        $files['calendar.ini.php'] = str_replace('password = ' . DemoCourse::PASSWORD, "password_hash = $hash", $files['calendar.ini.php']);
        $course = Fixture::temporaryCourse($files, __DIR__ . '/../..');
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        $page = (new HttpClient($server))->get('index.php?show=calendar_display');
        $this->assertContains('class="cal5-login"', $page->body);
        $this->assertNotContains('data-hash', $page->body, 'no challenge-response with a password hash');
    }

    public function testLockedSiteAndMenus(): void
    {
        $course = Fixture::load('core-tests')->install(__DIR__ . '/../..');
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        $student = new HttpClient($server);
        $locked = $student->get('locked.php?show=calendar_display');
        $this->assertContains('This course site is locked.', $locked->body, 'students get the lock page');
        $this->assertNotContains('cal5-grid', $locked->body);

        $page = $student->get('calendar.php?show=calendar_display');
        $this->assertContains('cal5-grid', $page->body);
        $this->assertContains('<summary><svg class="cal5-icon"', $page->body, 'menus have icons (v4 glyphicon names)');
        $this->assertNoPhpErrors($page);

        $instructor = new HttpClient($server);
        $instructor->get('locked.php?password=testpass');
        $view = $instructor->get('locked.php?show=calendar_display');
        $this->assertContains('cal5-grid', $view->body, 'the instructor still sees the calendar');
        $this->assertContains('cal5-locked-badge', $view->body, 'with a "locked" note');
    }
}
