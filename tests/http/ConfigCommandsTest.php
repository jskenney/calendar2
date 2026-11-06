<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

use Cal5\Tests\Fixture;
use Cal5\Tests\TestCase;

/**
 * bin/cal5 translate / check / hash-password against the fixture courses,
 * including the milestone 2 acceptance test (translate round trip).
 *
 * The translated configurations are compared with tests/expected/config/<fixture>.json.
 * After an intended change, regenerate them with:
 *     CAL5_UPDATE_SNAPSHOTS=1 php tests/run.php ConfigCommands
 */
final class ConfigCommandsTest extends TestCase
{
    private const CAL5 = __DIR__ . '/../..';

    /** @var list<string> */
    private array $courses = [];

    public function tearDown(): void
    {
        foreach ($this->courses as $course) {
            Fixture::remove($course);
        }
        $this->courses = [];
    }

    private function install(string $fixture): string
    {
        $course = Fixture::load($fixture)->install(self::CAL5);
        $this->courses[] = $course;
        return $course;
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private static function cal5(string $course, string $arguments, string $stdin = ''): array
    {
        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=-1 calendar/bin/cal5 ' . $arguments,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $course,
        );
        if (!is_resource($process)) {
            return [-1, '', 'could not start'];
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    /** @return array<string, mixed> */
    private function checkJson(string $course): array
    {
        [$code, $out, $err] = self::cal5($course, 'check --json');
        $data = json_decode($out, true);
        $this->assertTrue(is_array($data), "check --json prints JSON (stderr: $err)");
        $this->assertNotContains('PHP ', $err, 'no PHP warnings');
        return is_array($data) ? $data + ['exit' => $code] : ['exit' => $code];
    }

    /** Milestone 2 acceptance: translate, reload, identical Config. */
    public function testTranslateRoundTripForEveryFixture(): void
    {
        foreach (Fixture::names() as $name) {
            $course = $this->install($name);
            $before = $this->checkJson($course);
            $this->assertSame('legacy', $before['source'] ?? null, "$name: v4 configuration");
            $this->assertSame(true, $before['ok'] ?? null, "$name: translates without errors: " . json_encode($before['errors'] ?? []));
            $this->assertSame(0, $before['exit']);

            [$code, $out, $err] = self::cal5($course, 'translate');
            $this->assertSame(0, $code, "$name: translate exits 0 ($err)");
            $this->assertContains('Wrote ./calendar.ini.php', $out);
            $this->assertTrue(is_file("$course/calendar.ini.php"), "$name: calendar.ini.php written");
            $this->assertSame(';<?php http_response_code(404); exit; ?>', strtok((string) file_get_contents("$course/calendar.ini.php"), "\n"), 'guard line first');

            $after = $this->checkJson($course);
            $this->assertSame('ini', $after['source'] ?? null, "$name: calendar.ini.php is now used");
            $this->assertSame(true, $after['ok'] ?? null, "$name: the written file loads without errors: " . json_encode($after['errors'] ?? []));
            $this->assertSame($before['config'] ?? 'x', $after['config'] ?? 'y', "$name: identical configuration after the round trip");

            $this->compareSnapshot($name, $before['config'] ?? []);
        }
    }

    /** @param array<string, mixed> $config */
    private function compareSnapshot(string $name, array $config): void
    {
        $config['course']['timezone'] = '(server default)';
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $file = __DIR__ . "/../expected/config/$name.json";
        if (getenv('CAL5_UPDATE_SNAPSHOTS') === '1') {
            file_put_contents($file, $json);
        }
        $this->assertSame((string) @file_get_contents($file), $json, "$name: translated configuration matches tests/expected/config/$name.json");
    }

    public function testTranslateOptions(): void
    {
        $course = $this->install('sample');
        [$code, $out] = self::cal5($course, 'translate -o -');
        $this->assertSame(0, $code);
        $this->assertContains('[course]', $out);
        $this->assertContains('number = SI123', $out);
        $this->assertFalse(is_file("$course/calendar.ini.php"), '-o - only prints');

        [$code, $out] = self::cal5($course, 'translate --hash-password -o -');
        $this->assertSame(0, $code);
        $this->assertTrue((bool) preg_match('/^password_hash = (\S+)$/m', $out, $m), 'password_hash written');
        $this->assertTrue(isset($m[1]) && password_verify('mypassword', $m[1]), 'the hash matches $ADMIN');
        $this->assertNotContains('password = mypassword', $out);

        self::cal5($course, 'translate');
        [$code, , $err] = self::cal5($course, 'translate');
        $this->assertSame(1, $code, 'refuses to overwrite');
        $this->assertContains('already exists', $err);
        [$code] = self::cal5($course, 'translate --force');
        $this->assertSame(0, $code, '--force replaces it');
    }

    public function testCheckReportsProblems(): void
    {
        $course = $this->install('core-tests');
        file_put_contents("$course/calendar.ini.php", ";<?php exit; ?>\n[course]\nnumber = X\n[semester]\nstart = 2026-13-01\n");
        $data = $this->checkJson($course);
        $this->assertSame(1, $data['exit'], 'exit 1 with errors');
        $this->assertSame(false, $data['ok'] ?? null);
        $this->assertContains('calendar.ini.php:5: [semester] start: expected a date', implode("\n", $data['errors'] ?? []));
        $this->assertContains('the settings in calendar.php are ignored', implode("\n", $data['warnings'] ?? []), 'notice when both exist');

        [$code, $out, $err] = self::cal5($course, 'check');
        $this->assertSame(1, $code);
        $this->assertContains('NOT OK', $out);
        $this->assertContains('error: calendar.ini.php:5:', $err);
    }

    public function testCheckSummary(): void
    {
        [$code, $out] = self::cal5($this->install('kitchen-sink'), 'check');
        $this->assertSame(0, $code);
        $this->assertContains('Course:    KS101 Kitchen Sink & Co', $out);
        $this->assertContains('Day:       2018-12-14 = Final Exam', $out);
        $this->assertContains('Login:     plain-text password', $out);
        $this->assertContains('OK', $out);
    }

    public function testSampleSecretIsRefused(): void
    {
        $course = $this->install('sample');
        $file = "$course/calendar.php";
        file_put_contents($file, (string) preg_replace("/\\\$SECRET = '[^']*';/", "\$SECRET = 'A really long phrase that should be impossible to guess, you dont need to remember this...';", (string) file_get_contents($file)));
        [$code, , $err] = self::cal5($course, 'translate');
        $this->assertSame(1, $code);
        $this->assertContains('published sample value', $err);
        $this->assertFalse(is_file("$course/calendar.ini.php"));
    }

    public function testHashPassword(): void
    {
        [$code, $out] = self::cal5($this->install('sample'), 'hash-password --stdin', "s3cret!\n");
        $this->assertSame(0, $code);
        $this->assertTrue(password_verify('s3cret!', trim($out)), 'prints a hash of the password');
        [$code, , $err] = self::cal5($this->courses[0], 'hash-password --stdin', "\n");
        $this->assertSame(1, $code);
        $this->assertContains('empty', $err);
    }

    public function testCaptureNeedsThisCal5(): void
    {
        $course = $this->install('sample');
        unlink("$course/calendar");
        mkdir("$course/calendar");
        file_put_contents("$course/calendar/calendar_main.php", "<?php echo 'v4 ran';\n");
        [$code, $out, $err] = self::cal5($course, '--help'); // calendar/bin/cal5 no longer exists here
        $this->assertTrue($code !== 0);
        // Run this Cal5's CLI against a course whose calendar/ is something else
        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(realpath(self::CAL5) . '/bin/cal5') . ' check',
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $course,
        );
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $this->assertSame(1, $code);
        $this->assertContains('is not this copy of Cal5', $err);
        $this->assertNotContains('v4 ran', $out . $err, 'the other calendar_main.php is not run');
    }
}
