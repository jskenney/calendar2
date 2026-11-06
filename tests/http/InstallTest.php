<?php

declare(strict_types=1);

namespace Cal5\Tests\Http;

use Cal5\Tests\HttpClient;
use Cal5\Tests\Server;
use Cal5\Tests\TestCase;

/** Milestone 10: installer.sh and bin/cal5 init create a working new course. */
final class InstallTest extends TestCase
{
    private const CAL5 = __DIR__ . '/../..';

    /** @var list<string> */
    private array $dirs = [];
    private ?Server $server = null;

    public function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->dirs = [];
    }

    private function emptyDir(): string
    {
        $dir = sys_get_temp_dir() . '/cal5-install-' . bin2hex(random_bytes(4));
        mkdir("$dir/course", 0777, true);
        $this->dirs[] = $dir;
        return "$dir/course";
    }

    /** @return array{0: int, 1: string, 2: string} */
    private static function run(string $dir, string $command, string $stdin = ''): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (!is_resource($process)) {
            return [-1, '', ''];
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testInstallerCreatesAWorkingCourse(): void
    {
        $course = $this->emptyDir();
        $installer = escapeshellarg((string) realpath(self::CAL5 . '/installer.sh'));
        [$code, $out, $err] = self::run($course, "bash $installer --source=" . escapeshellarg((string) realpath(self::CAL5))
            . " --number=SI460 --name='Computer Graphics' --start=2020-08-24 --end=2020-12-18 --class-days='mon wed fri' --lab-days=thu"
            . " --timezone=America/New_York --password-stdin", "my-long-password\n");
        $this->assertSame(0, $code, "installer.sh ($err)");
        $this->assertContains('Created the course SI460', $out);
        $this->assertSame('calendar-core/', (string) readlink("$course/calendar"), 'the usual calendar -> calendar-core/ link');
        $ini = (string) file_get_contents("$course/calendar.ini.php");
        $this->assertContains('password_hash = $2y$', $ini, 'the password is stored as a hash');
        $this->assertNotContains('my-long-password', $ini);
        $this->assertTrue((bool) preg_match('/^secret\s+= [0-9a-f]{64}$/m', $ini), 'a random secret');
        $this->assertContains("thu = lab", $ini);

        [$code, $out] = self::run($course, escapeshellarg(PHP_BINARY) . ' calendar/bin/cal5 check');
        $this->assertSame(0, $code, 'the configuration checks out');

        $this->server = new Server($course);
        $student = new HttpClient($this->server);
        $home = $student->get('index.php');
        $this->assertContains('SI460: Computer Graphics', $home->body, 'the home page');
        $this->assertNoPhpErrors($home);
        $this->assertContains('Welcome', $student->get('index.php?type=class&event=1')->body, 'the first class (2020: released)');
        $this->assertContains('Lab 1: Setup', $student->get('index.php?type=lab&event=1')->body);
        $this->assertTrue(is_file("$course/class/.htaccess") && is_file("$course/virtual/.htaccess"), 'material directories are private (Apache)');
        $instructor = new HttpClient($this->server);
        $page = $instructor->post('index.php', ['password' => 'my-long-password']);
        $this->assertContains('data-instructor="yes"', $page->body, 'the instructor logs in with the password');

        [$code, , $err] = self::run($course, "bash $installer --yes");
        $this->assertSame(1, $code, 'an existing course is never touched');
        $this->assertContains('already a course', $err);
    }

    public function testInitChecksTheAnswers(): void
    {
        $course = $this->emptyDir();
        symlink((string) realpath(self::CAL5), "$course/calendar");
        $init = escapeshellarg(PHP_BINARY) . ' calendar/bin/cal5 init --number=X1 --password-stdin ';
        foreach ([
            '--start=2026-02-30' => 'the semester start must be a date',
            '--start=2026-12-01 --end=2026-09-01' => 'ends before it starts',
            '--class-days=funday' => 'is not a weekday',
            '--timezone=Mars/Base' => 'is not a time zone',
        ] as $args => $message) {
            [$code, , $err] = self::run($course, $init . $args, "long-enough-password\n");
            $this->assertSame(1, $code, $args);
            $this->assertContains($message, $err, $args);
        }
        [$code, , $err] = self::run($course, $init, "short\n");
        $this->assertContains('at least 8 characters', $err);
        $this->assertFalse(is_file("$course/calendar.ini.php"), 'nothing is written when an answer is wrong');
        file_put_contents("$course/calendar.php", '<?php // a v4 course');
        [$code, , $err] = self::run($course, $init, "long-enough-password\n");
        $this->assertSame(1, $code);
        $this->assertContains('this is a course already', $err);
    }
}
