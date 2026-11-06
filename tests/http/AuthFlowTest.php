<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

use Cal5\Auth\RateLimiter;
use Cal5\Tests\Fixture;
use Cal5\Tests\HttpClient;
use Cal5\Tests\Response;
use Cal5\Tests\Server;
use Cal5\Tests\TestCase;

/**
 * Milestone 5 acceptance: an instructor logs in with the course's v4 password and
 * v4's login flow, and a login survives switching a course from v4 to Cal5 (and back).
 *
 * The switching tests need v4: run tests/compat/fetch-v4.sh first (CI does).
 */
final class AuthFlowTest extends TestCase
{
    private const CAL5 = __DIR__ . '/../..';
    private const V4 = __DIR__ . '/../../.v4';

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
            $limits = RateLimiter::directoryFor($course);
            foreach (glob($limits . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($limits);
            Fixture::remove($course);
        }
        $this->servers = [];
        $this->courses = [];
    }

    /** @return array{0: string, 1: Server} */
    private function serve(string $fixture, string $implementation = self::CAL5): array
    {
        $course = Fixture::load($fixture)->install($implementation);
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        return [$course, $server];
    }

    private static function isInstructor(Response $response): bool
    {
        return str_contains($response->body, 'data-instructor="yes"');
    }

    /** v4's login form: sha256(password . nonce), sent as ?password=..., then the page is reloaded. */
    private static function v4Login(HttpClient $client, string $entry, string $password, string $course): Response
    {
        $client->get($entry);
        $nonce = (string) $client->cookie("cal4-$course-nonce");
        $client->get($entry . '?password=' . hash('sha256', $password . $nonce));
        return $client->get($entry);
    }

    public function testV4LoginFormFlow(): void
    {
        foreach (['sample' => ['mypassword', 'SI123'], 'core-tests' => ['testpass', 'TST101'], 'kitchen-sink' => ['kitchenpass', 'KS101']] as $fixture => [$password, $course]) {
            [, $server] = $this->serve($fixture);
            $client = new HttpClient($server);
            $first = $client->get('calendar.php');
            $this->assertFalse(self::isInstructor($first), "$fixture: not logged in yet");
            $nonce = (string) $client->cookie("cal4-$course-nonce");
            $this->assertSame(64, strlen($nonce), "$fixture: nonce cookie");
            $this->assertContains('data-nonce="' . $nonce . '"', $first->body, "$fixture: the page has the same nonce");

            $page = self::v4Login($client, 'calendar.php', $password, $course);
            $this->assertTrue(self::isInstructor($page), "$fixture: logged in with v4's challenge-response");
            $this->assertNoPhpErrors($page, $fixture);

            // v4's log off button: hashPassword('') -> a password that does not match
            $client->get('calendar.php?password=' . hash('sha256', '' . 'stale-nonce'));
            $this->assertFalse(self::isInstructor($client->get('calendar.php')), "$fixture: logged out");
        }
    }

    public function testPlainPasswordAndCal5Form(): void
    {
        [, $server] = $this->serve('sample');
        $bookmark = new HttpClient($server);
        $bookmark->get('calendar.php?password=mypassword');
        $this->assertTrue(self::isInstructor($bookmark->get('calendar.php')), 'v4 accepted ?password= with the plain password');

        $client = new HttpClient($server);
        $page = $client->get('calendar.php');
        $csrf = (string) HttpClient::formValue($page);
        $this->assertSame(64, strlen($csrf), 'the login form has a CSRF token');
        $loggedIn = $client->post('calendar.php', ['password' => 'mypassword', 'csrf' => $csrf]);
        $this->assertTrue(self::isInstructor($loggedIn), 'the Cal5 login form');
        $this->assertContains('Log out', $loggedIn->body);

        $logout = $client->post('calendar.php', ['password' => '', 'csrf' => (string) HttpClient::formValue($loggedIn)]);
        $this->assertFalse(self::isInstructor($logout), 'the Cal5 log out button');

        $wrong = $client->post('calendar.php', ['password' => 'nope']);
        $this->assertContains('The password was not accepted.', $wrong->body);
    }

    public function testCookieFlagsAndSessionFixation(): void
    {
        [, $server] = $this->serve('sample');
        $attacker = new HttpClient($server);
        $attacker->setCookie('PHPSESSID', 'fixated0123456789abcdef');
        $response = $attacker->get('calendar.php');
        $this->assertTrue($attacker->cookie('PHPSESSID') !== 'fixated0123456789abcdef', 'a session id the server did not create is replaced (strict mode)');

        $victim = new HttpClient($server);
        $victim->get('calendar.php');
        $before = (string) $victim->cookie('PHPSESSID');
        $victim->get('calendar.php?password=mypassword');
        $after = (string) $victim->cookie('PHPSESSID');
        $this->assertTrue($before !== '' && $after !== '' && $before !== $after, 'the session id changes at login');

        $stale = new HttpClient($server);
        $stale->setCookie('PHPSESSID', $before);
        $this->assertFalse(self::isInstructor($stale->get('calendar.php')), 'the old session id is not logged in');

        $headers = implode("\n", $response->headerValues('Set-Cookie'));
        $this->assertTrue((bool) preg_match('/PHPSESSID=[^\n]*HttpOnly[^\n]*SameSite=Lax/i', $headers), 'session cookie flags');
        $this->assertTrue((bool) preg_match('/cal4-SI123-nonce=[^\n]*HttpOnly[^\n]*SameSite=Lax/i', $headers), 'nonce cookie flags');
    }

    public function testLockedSite(): void
    {
        [, $server] = $this->serve('core-tests');
        $student = new HttpClient($server);
        $this->assertContains('This course site is locked.', $student->get('locked.php')->body);
        $this->assertNotContains('This course site is locked.', $student->get('calendar.php')->body, 'only while $LOCK is set');
        $instructor = new HttpClient($server);
        $instructor->get('locked.php?password=testpass');
        $page = $instructor->get('locked.php');
        $this->assertTrue(self::isInstructor($page));
        $this->assertNotContains('This course site is locked.', $page->body, 'the instructor is not locked out');
    }

    public function testPasswordHashCourse(): void
    {
        $hash = password_hash('s3cret', PASSWORD_DEFAULT);
        $course = Fixture::temporaryCourse([
            'index.php' => "<?php require __DIR__ . '/calendar/index.php';\n",
            'calendar.ini.php' => ";<?php http_response_code(404); exit; ?>\n[course]\nnumber = NEW1\n[semester]\nstart = 2026-08-24\nend = 2026-12-18\n"
                . "[security]\npassword_hash = $hash\nsecret = 0123456789abcdef0123456789abcdef0123456789abcdef\n",
        ], self::CAL5);
        $this->courses[] = $course;
        $server = new Server($course);
        $this->servers[] = $server;
        $client = new HttpClient($server);
        $client->get('index.php');
        $nonce = (string) $client->cookie('cal4-NEW1-nonce');
        $client->get('index.php?password=' . hash('sha256', 's3cret' . $nonce));
        $this->assertFalse(self::isInstructor($client->get('index.php')), 'no challenge-response with a password hash');
        $page = $client->post('index.php', ['password' => 's3cret']);
        $this->assertTrue(self::isInstructor($page), 'posting the password works');
        $this->assertNoPhpErrors($page);
    }

    public function testRateLimitOverHttp(): void
    {
        [, $server] = $this->serve('sample');
        $client = new HttpClient($server);
        for ($i = 0; $i < RateLimiter::MAX; $i++) {
            $response = $client->post('calendar.php', ['password' => "guess$i"]);
        }
        $this->assertContains('Too many failed logins', $response->body ?? '');
        $this->assertFalse(self::isInstructor($client->post('calendar.php', ['password' => 'mypassword'])), 'blocked');
    }

    /** The acceptance test: switching a course from v4 to Cal5 keeps the instructor logged in. */
    public function testLoginSurvivesSwitchingFromV4ToCal5(): void
    {
        if (!is_file(self::V4 . '/calendar_main.php')) {
            $this->assertTrue(false, 'v4 is needed: run tests/compat/fetch-v4.sh');
            return;
        }
        [$course, $server] = $this->serve('kitchen-sink', self::V4);
        $client = new HttpClient($server);
        $v4Page = self::v4Login($client, 'calendar.php', 'kitchenpass', 'KS101');
        $this->assertContains('Version 4.6.', $v4Page->body, 'v4 is running (its version shows when logged in)');
        $this->assertContains('Logoff', $v4Page->body, 'logged in under v4');

        self::switchTo($course, self::CAL5);
        $cal5Page = $client->get('calendar.php');
        $this->assertContains('data-cal5-version', $cal5Page->body, 'Cal5 is running now');
        $this->assertTrue(self::isInstructor($cal5Page), 'still logged in after the switch');
        $this->assertNoPhpErrors($cal5Page);

        // and back again (rolling back to v4 keeps the login made under Cal5)
        $fresh = new HttpClient($server);
        $fresh->post('calendar.php', ['password' => 'kitchenpass']);
        $this->assertTrue(self::isInstructor($fresh->get('calendar.php')), 'logged in under Cal5');
        self::switchTo($course, self::V4);
        $this->assertContains('Logoff', $fresh->get('calendar.php')->body, 'still logged in after rolling back to v4');
    }

    private static function switchTo(string $course, string $implementation): void
    {
        unlink($course . '/calendar');
        symlink((string) realpath($implementation), $course . '/calendar');
        clearstatcache();
    }
}
