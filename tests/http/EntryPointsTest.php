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
            $this->assertContains('data-config-source="legacy"', $response->body, "$name: v4 configuration detected");
            $this->assertContains('data-config-state="ok"', $response->body, "$name: v4 configuration translated without errors");
            $this->assertNoPhpErrors($response, $name);
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'), "$name nosniff");
        }
    }

    public function testV4PageShowsCourseButNoSecrets(): void
    {
        $fixture = Fixture::load('sample');
        $response = $this->serve($fixture->install(self::CAL5))->get('index.php'); // index.php -> calendar.php
        $this->assertContains('SI123 Applied Something', $response->body);
        $this->assertNotContains((string) $fixture->meta['secret'], $response->body);
        $this->assertNotContains((string) $fixture->meta['password'], $response->body);
    }

    public function testInstalledPageSummarisesTheConfiguration(): void
    {
        $response = $this->serve(Fixture::load('kitchen-sink')->install(self::CAL5))->get('calendar.php');
        $this->assertContains('2018-08-20 to 2018-12-31', $response->body);
        $this->assertContains('mon class, wed lab, fri class', $response->body);
        $this->assertContains('7 class, 3 lab, 1 exam, 1 final-exam', $response->body, 'events found');
        $this->assertContains('12 of 12 events, on 13 days', $response->body, 'events on the calendar');
        $this->assertContains('1 warning(s)', $response->body, 'problems are counted');
        $this->assertNotContains('plain text', $response->body, 'but their text is not shown on the page');
    }

    public function testBrokenConfigurationShowsNoDetails(): void
    {
        $course = Fixture::load('sample')->install(self::CAL5);
        $file = "$course/calendar.php";
        file_put_contents($file, (string) preg_replace("/\\\$SECRET = '[^']*';/",
            "\\\$SECRET = 'A really long phrase that should be impossible to guess, you dont need to remember this...';",
            (string) file_get_contents($file)));
        $response = $this->serve($course)->get('calendar.php');
        $this->assertContains('data-config-state="error"', $response->body);
        $this->assertContains('Configuration problem', $response->body);
        $this->assertNotContains('sample value', $response->body);
        $this->assertNoPhpErrors($response);
    }

    public function testCourseNameIsEscaped(): void
    {
        $response = $this->serve(Fixture::load('core-tests')->install(self::CAL5))->get('calendar.php');
        $this->assertContains('TST101 Test &lt;b&gt;Course&lt;/b&gt;', $response->body);
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
        $this->assertContains('--cal5-accent', $css->body);
        $this->assertSame(200, $client->get('calendar/images/web-icon.png')->status, 'v4 icon path still works');
    }
}
