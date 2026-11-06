<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

use Cal5\Tests\Fixture;
use Cal5\Tests\HttpClient;
use Cal5\Tests\Server;
use Cal5\Tests\TestCase;

/** Milestone 1: the entry points start Cal5 and detect the configuration. */
final class EntryPointsTest extends TestCase
{
    private const CAL5 = __DIR__ . '/../..';
    private const GUARD = ";<?php http_response_code(404); exit; ?>\n";

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

    public function testV4CourseRunsThroughTheShim(): void
    {
        foreach (Fixture::names() as $name) {
            $client = $this->serve(Fixture::load($name)->install(self::CAL5));
            $response = $client->get('calendar.php');
            $this->assertSame(200, $response->status, "$name status");
            $this->assertContains('data-cal5-version', $response->body, "$name: Cal5 runs");
            $this->assertContains('class="cal5-header"', $response->body, "$name: the course's start page, not the set-up page");
            $this->assertNoPhpErrors($response, $name);
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'), "$name nosniff");
        }
    }

    public function testV4PageShowsCourseButNoSecrets(): void
    {
        $fixture = Fixture::load('sample');
        $response = $this->serve($fixture->install(self::CAL5))->get('index.php'); // index.php -> calendar.php
        $this->assertContains('SI123', $response->body);
        $this->assertContains('Applied Something', $response->body);
        $this->assertContains('SAMPLE-home', $response->body, 'the home page');
        $this->assertNotContains((string) $fixture->meta['secret'], $response->body);
        $this->assertNotContains((string) $fixture->meta['password'], $response->body);
    }

    public function testInstructorSeesConfigurationWarnings(): void
    {
        $course = Fixture::load('core-tests')->install(self::CAL5);
        $client = $this->serve($course);
        $student = $client->get('weak_secret.php?load=home');
        $this->assertNotContains('the secret is weak', $student->body, 'students do not see warnings');
        $client->get('weak_secret.php?password=testpass');
        $page = $client->get('weak_secret.php?load=home');
        $this->assertContains('the secret is weak', $page->body, 'the instructor does (v4 showed them on every page)');
        $this->assertNotContains('stored as plain text', $page->body, 'but not the advice only bin/cal5 check gives');
    }

    public function testBrokenConfigurationShowsNoDetails(): void
    {
        $course = Fixture::load('sample')->install(self::CAL5);
        $file = "$course/calendar.php";
        file_put_contents($file, (string) preg_replace("/\\\$SECRET = '[^']*';/",
            "\\\$SECRET = 'A really long phrase that should be impossible to guess, you dont need to remember this...';",
            (string) file_get_contents($file)));
        $client = $this->serve($course);
        $response = $client->get('calendar.php');
        $this->assertContains('This course site is not set up correctly.', $response->body, 'the error page, in the usual layout (v4)');
        $this->assertContains('class="cal5-header"', $response->body);
        $this->assertNotContains('sample value', $response->body, 'the problem itself is not shown to students');
        $this->assertNotContains('SAMPLE-class1', $client->get('calendar.php?type=class&event=1')->body, 'no content is served');
        $this->assertNoPhpErrors($response);
    }

    public function testCourseNameIsEscaped(): void
    {
        $response = $this->serve(Fixture::load('core-tests')->install(self::CAL5))->get('calendar.php');
        $this->assertContains('Test &lt;b&gt;Course&lt;/b&gt;', $response->body);
        $this->assertNotContains('<b>Course</b>', $response->body);
    }

    public function testNewCourseWithIniFile(): void
    {
        $client = $this->serve(Fixture::temporaryCourse([
            'index.php' => "<?php require __DIR__ . '/calendar/index.php';\n",
            'calendar.ini.php' => self::GUARD . "[course]\nnumber = NEW1\n[security]\nsecret = top-secret-value-0123456789abcdef\n",
        ], self::CAL5));
        $response = $client->get('index.php');
        $this->assertSame(200, $response->status);
        $this->assertContains('data-config-source="ini"', $response->body);
        $this->assertContains('data-config-state="error"', $response->body, 'semester dates are missing');
        $this->assertNoPhpErrors($response);

        // The guard line keeps the configuration (and its secret) private
        $ini = $client->get('calendar.ini.php');
        $this->assertSame(404, $ini->status, 'calendar.ini.php is not served');
        $this->assertNotContains('top-secret-value', $ini->body);
    }

    public function testCourseWithoutConfiguration(): void
    {
        $response = $this->serve(Fixture::temporaryCourse([
            'index.php' => "<?php require __DIR__ . '/calendar/index.php';\n",
        ], self::CAL5))->get('index.php');
        $this->assertContains('data-config-source="none"', $response->body);
        $this->assertContains('Set up this course', $response->body);
        $this->assertNoPhpErrors($response);
    }

    public function testRequestingTheLibraryDirectly(): void
    {
        $response = $this->serve(Fixture::temporaryCourse([], self::CAL5))->get('calendar/index.php');
        $this->assertContains('This directory is the Cal5 library', $response->body);
        $this->assertNoPhpErrors($response);
    }

    public function testStylesheetAndIconAreServed(): void
    {
        $client = $this->serve(Fixture::temporaryCourse([], self::CAL5));
        $css = $client->get('calendar/public/css/cal5.css');
        $this->assertSame(200, $css->status);
        $this->assertContains('--accent:', $css->body);
        $this->assertSame(200, $client->get('calendar/images/web-icon.png')->status, 'v4 icon path still works');
    }
}
