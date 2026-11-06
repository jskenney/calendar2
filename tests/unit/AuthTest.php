<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Access\FixedClock;
use Cal5\Auth\ArraySession;
use Cal5\Auth\AuthResult;
use Cal5\Auth\Csrf;
use Cal5\Auth\InstructorAuth;
use Cal5\Auth\LockCodes;
use Cal5\Auth\LockMode;
use Cal5\Auth\RateLimiter;
use Cal5\Config\Config;
use Cal5\Config\IniConfigLoader;
use Cal5\Http\Request;
use Cal5\Tests\TestCase;

final class AuthTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const NOW = '2026-11-06 12:00:00';

    /** @var list<string> */
    private array $dirs = [];

    public function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    private static function config(string $security = "password = letmein\n", string $extra = ''): Config
    {
        $config = IniConfigLoader::fromString("[course]\nnumber = SI123\n[semester]\nstart = 2026-08-24\nend = 2026-12-18\n"
            . "[security]\nsecret = " . self::SECRET . "\nsession_hours = 2\n" . $security . $extra)->config;
        if ($config === null) {
            throw new \RuntimeException('config');
        }
        return $config;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     */
    private static function run(Config $config, ArraySession $session, array $query = [], array $cookies = [],
                                string $now = self::NOW, ?RateLimiter $limiter = null, array $post = []): AuthResult
    {
        $request = new Request($query, $post, $cookies, ['REMOTE_ADDR' => '203.0.113.9']);
        return (new InstructorAuth($config, $session, $request, FixedClock::at($now), $limiter))->handle();
    }

    /** @param list<\Cal5\Auth\Cookie> $cookies @return array<string, string> */
    private static function cookies(array $cookies): array
    {
        $jar = [];
        foreach ($cookies as $cookie) {
            $jar[$cookie->name] = $cookie->value;
        }
        return $jar;
    }

    private function limiter(): RateLimiter
    {
        $dir = sys_get_temp_dir() . '/cal5-test-limiter-' . bin2hex(random_bytes(4));
        $this->dirs[] = $dir;
        return new RateLimiter($dir);
    }

    public function testPlainPasswordUsesTheV4Token(): void
    {
        $session = new ArraySession();
        $first = self::run(self::config(), $session);
        $this->assertFalse($first->instructor);
        $this->assertTrue(is_string($first->nonce) && strlen($first->nonce) === 64, 'a nonce for the login form');
        $this->assertSame($first->nonce, self::cookies($first->cookies)['cal4-SI123-nonce'] ?? null, 'nonce cookie');

        $oldId = $session->id();
        $login = self::run(self::config(), $session, ['password' => 'letmein'], ['cal4-SI123-nonce' => (string) $first->nonce]);
        $this->assertTrue($login->instructor);
        $this->assertSame(AuthResult::LOGGED_IN, $login->event);
        $this->assertTrue($session->id() !== $oldId, 'the session id changes at login');
        $nonce = (string) $session->get('cal4-SI123-nonce');
        $expected = sha1(self::SECRET . 'letmein' . 'SI123' . $nonce . $session->id());
        $this->assertSame($expected, $session->get('cal4-SI123'), 'v4 session token');
        $this->assertSame($expected, self::cookies($login->cookies)['cal4-SI123'] ?? null, 'v4 cookie token');
        $this->assertSame(null, $login->nonce, 'no new nonce while logged in');
    }

    public function testV4ChallengeResponse(): void
    {
        foreach (['cookie', 'session'] as $where) {
            $session = new ArraySession();
            $first = self::run(self::config(), $session);
            $nonce = (string) $first->nonce;
            $cookies = $where === 'cookie' ? ['cal4-SI123-nonce' => $nonce] : [];
            if ($where === 'session') {
                $session->set('cal4-SI123-nonce', $nonce);
            }
            $answer = hash('sha256', 'letmein' . $nonce);
            $result = self::run(self::config(), $session, ['password' => $answer], $cookies);
            $this->assertTrue($result->instructor, "sha256(password . $where nonce) logs in");
        }
        $session = new ArraySession();
        $first = self::run(self::config(), $session);
        $upper = strtoupper(hash('sha256', 'letmein' . $first->nonce));
        $this->assertTrue(self::run(self::config(), $session, ['password' => $upper])->instructor, 'upper-case hex is accepted');
    }

    public function testStaysLoggedInAndLogsOut(): void
    {
        $session = new ArraySession();
        $login = self::run(self::config(), $session, ['password' => 'letmein']);
        $jar = self::cookies($login->cookies);
        $this->assertTrue(self::run(self::config(), $session, [], $jar)->instructor, 'next request');
        $this->assertTrue(self::run(self::config(), new ArraySession([], $session->id()), [], $jar)->instructor,
            'the cookie alone keeps the login when the session data is gone (v4)');

        $logout = self::run(self::config(), $session, ['password' => hash('sha256', '' . 'whatever')], $jar);
        $this->assertFalse($logout->instructor);
        $this->assertSame(AuthResult::LOGGED_OUT, $logout->event);
        $deleted = array_values(array_filter($logout->cookies, static fn ($c) => $c->name === 'cal4-SI123'));
        $this->assertTrue($deleted !== [] && $deleted[0]->value === '' && $deleted[0]->expires < time(), 'the token cookie is deleted');
        $this->assertSame(null, $session->get('cal4-SI123'));

        $again = self::run(self::config(), $session, ['password' => 'letmein']);
        $this->assertTrue(self::run(self::config(), $session, ['password' => ''], self::cookies($again->cookies))->event === AuthResult::LOGGED_OUT,
            'an empty password (the Cal5 log out button) logs out');
    }

    public function testWrongPasswordAndNonceRotation(): void
    {
        $session = new ArraySession();
        $a = self::run(self::config(), $session);
        $b = self::run(self::config(), $session, ['password' => 'nope']);
        $this->assertFalse($b->instructor);
        $this->assertSame(AuthResult::FAILED, $b->event);
        $this->assertTrue($a->nonce !== $b->nonce, 'a new nonce for every request while logged out (v4)');
        $old = hash('sha256', 'letmein' . $a->nonce);
        $this->assertFalse(self::run(self::config(), $session, ['password' => $old])->instructor, 'an answer to an old nonce is refused');
    }

    public function testLoginExpires(): void
    {
        $session = new ArraySession();
        self::run(self::config(), $session, ['password' => 'letmein'], [], '2026-11-06 12:00:00');
        $this->assertTrue(self::run(self::config(), $session, [], [], '2026-11-06 13:59:59')->instructor);
        $this->assertFalse(self::run(self::config(), $session, [], [], '2026-11-06 14:00:01')->instructor, 'session_hours = 2');

        // A login made by v4 has no Cal5 timestamp: it is accepted, and the clock starts then
        $v4 = new ArraySession(['cal4-SI123-nonce' => 'n'], 'v4-session');
        $v4->set('cal4-SI123', sha1(self::SECRET . 'letmein' . 'SI123' . 'n' . 'v4-session'));
        $this->assertTrue(self::run(self::config(), $v4)->instructor, 'a v4 login carries over');
        $this->assertTrue(is_int($v4->get(InstructorAuth::LOGIN_AT)));
    }

    public function testPasswordHashConfigurations(): void
    {
        $hash = password_hash('letmein', PASSWORD_DEFAULT);
        $config = self::config("password_hash = $hash\n");
        $session = new ArraySession();
        $first = self::run($config, $session);
        $this->assertFalse(self::run($config, $session, ['password' => hash('sha256', 'letmein' . $first->nonce)])->instructor,
            'challenge-response needs the plain password on the server, so it is refused');
        $login = self::run($config, $session, [], [], self::NOW, null, ['password' => 'letmein']);
        $this->assertTrue($login->instructor, 'the posted password is checked with password_verify');
        $token = (string) $session->get('cal4-SI123');
        $this->assertSame(64, strlen($token), 'an HMAC-SHA256 token');
        $this->assertTrue($token !== sha1(self::SECRET . 'letmein' . 'SI123' . $session->get('cal4-SI123-nonce') . $session->id()));

        $changed = self::config('password_hash = ' . password_hash('another', PASSWORD_DEFAULT) . "\n");
        $this->assertFalse(self::run($changed, $session, [], self::cookies($login->cookies))->instructor,
            'changing the password ends existing logins');
    }

    public function testLoginDisabledWithoutPassword(): void
    {
        $config = self::config('');
        $result = self::run($config, new ArraySession(), ['password' => '']);
        $this->assertFalse($result->instructor);
        $this->assertSame([], $result->cookies, 'no nonce cookie (v4: empty $ADMIN)');
        $this->assertSame(null, $result->nonce);
    }

    public function testRateLimiting(): void
    {
        $limiter = $this->limiter();
        $session = new ArraySession();
        for ($i = 1; $i <= RateLimiter::MAX; $i++) {
            $result = self::run(self::config(), $session, ['password' => "guess$i"], [], self::NOW, $limiter);
        }
        $this->assertSame(AuthResult::BLOCKED, $result->event ?? null, 'the 10th failure blocks');
        $blocked = self::run(self::config(), $session, ['password' => 'letmein'], [], '2026-11-06 12:14:00', $limiter);
        $this->assertFalse($blocked->instructor, 'even the right password is refused while blocked');
        $this->assertSame(AuthResult::BLOCKED, $blocked->event);
        $later = self::run(self::config(), $session, ['password' => 'letmein'], [], '2026-11-06 12:15:01', $limiter);
        $this->assertTrue($later->instructor, 'allowed again after 15 minutes');
        $this->assertFalse($limiter->blocked('203.0.113.9', strtotime('2026-11-06 12:15:01 UTC')), 'a success clears the failures');
    }

    public function testCsrf(): void
    {
        $session = new ArraySession();
        $token = Csrf::token($session);
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, Csrf::token($session), 'stable within a session');
        $this->assertTrue(Csrf::valid($session, new Request([], ['csrf' => $token])));
        $this->assertFalse(Csrf::valid($session, new Request([], ['csrf' => 'x' . substr($token, 1)])));
        $this->assertFalse(Csrf::valid($session, new Request(['csrf' => $token], [])), 'only accepted from a form post');
        $this->assertFalse(Csrf::valid($session, new Request([], ['csrf' => [$token]])), 'arrays are ignored');
        $this->assertFalse(Csrf::valid(new ArraySession(), new Request([], ['csrf' => ''])), 'no token in the session');
    }

    public function testLockModeAndLockCodes(): void
    {
        $dir = sys_get_temp_dir() . '/cal5-lock-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->assertFalse(LockMode::locked(self::config(), $dir));
        $this->assertTrue(LockMode::locked(self::config("password = x\nlock = yes\n"), $dir));
        $byFile = self::config("password = x\nlock = LOCKED\n");
        $this->assertFalse(LockMode::locked($byFile, $dir), 'unlocked while the file is missing');
        touch("$dir/LOCKED");
        $this->assertTrue(LockMode::locked($byFile, $dir), 'locked while it exists');
        unlink("$dir/LOCKED");
        rmdir($dir);

        $session = new ArraySession();
        $this->assertFalse(LockCodes::opens($session, 'happy'));
        LockCodes::remember(new Request(['lock' => 'happy']), $session);
        $this->assertSame('happy', $session->get('lock'), 'same session key as v4');
        $this->assertTrue(LockCodes::opens($session, 'sad, happy ,glad'));
        $this->assertFalse(LockCodes::opens($session, 'sad,glad'));
        $this->assertFalse(LockCodes::opens($session, ' , '));
    }

    public function testRequest(): void
    {
        $request = new Request(['a' => '1', 'arr' => ['x'], 'both' => 'get'], ['both' => 'post'],
            ['cal4-SI_123' => 'mangled'], ['REQUEST_METHOD' => 'post', 'SERVER_PORT' => '443']);
        $this->assertSame('1', $request->param('a'));
        $this->assertSame(null, $request->param('arr'), 'array parameters are ignored');
        $this->assertSame('post', $request->param('both'), 'POST wins, like $_REQUEST');
        $this->assertSame('mangled', $request->cookie('cal4-SI.123'), 'PHP renames "." in cookie names to "_"');
        $this->assertTrue($request->isPost());
        $this->assertTrue($request->isHttps());
        $this->assertSame('unknown', $request->clientIp());
    }
}
