<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Config\Config;
use Cal5\Config\IniConfigLoader;
use Cal5\Content\FileKey;
use Cal5\Content\FileKind;
use Cal5\Course;
use Cal5\Tests\TestCase;

/**
 * Content scanning and scheduling with calendar.ini.php features that v4 does
 * not have (the v4 behaviour itself is checked by tests/compat check-structure).
 */
final class CourseTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /** @var list<string> */
    private array $dirs = [];

    public function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::remove($dir);
        }
        $this->dirs = [];
    }

    /** @param array<string, string> $files path => contents ("@link:target" makes a symlink) */
    private function course(array $files): string
    {
        $dir = sys_get_temp_dir() . '/cal5-unit-' . bin2hex(random_bytes(4));
        $this->dirs[] = $dir;
        mkdir($dir . '/course', 0777, true);
        foreach ($files as $path => $contents) {
            $full = "$dir/course/$path";
            @mkdir(dirname($full), 0777, true);
            if (str_starts_with($contents, '@link:')) {
                symlink(substr($contents, 6), $full);
            } elseif (str_ends_with($path, '/')) {
                @mkdir($full, 0777, true);
            } else {
                file_put_contents($full, $contents);
            }
        }
        return "$dir/course";
    }

    private static function config(string $sections): Config
    {
        $result = IniConfigLoader::fromString(
            "[course]\nnumber = T1\n[security]\npassword = pw\nsecret = " . self::SECRET . "\n" . $sections,
        );
        if ($result->config === null) {
            throw new \RuntimeException(implode('; ', array_map('strval', $result->errors)));
        }
        return $result->config;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("$path/$entry");
                }
            }
            rmdir($path);
        }
    }

    public function testSemesterCrossingIntoTheNextYear(): void
    {
        $dir = $this->course(['class/1.A/index.html' => 'a', 'class/2.B/index.html' => 'b', 'class/3.C/index.html' => 'c']);
        $config = self::config("[semester]\nstart = 2026-12-28\nend = 2027-01-08\n[schedule]\nmon = class\n[days]\n2027-01-01 = holiday\n");
        $course = Course::load($dir, $config);
        $this->assertSame('2026-12-28', $course->schedule->dateOf('class', 1));
        $this->assertSame('2027-01-04', $course->schedule->dateOf('class', 2), 'continues into January');
        $this->assertSame(null, $course->schedule->dateOf('class', 3), 'no third Monday in the semester');
        $this->assertSame(['2026-12-28', '2027-01-01', '2027-01-04'], array_keys($course->schedule->days));
        $this->assertSame('holiday', $course->schedule->day('2027-01-01')?->entries[0]->type);
    }

    public function testMeetingTimesAndMultiEntryDays(): void
    {
        $dir = $this->course(['class/1.A/index.html' => 'a', 'class/2.B/index.html' => 'b', 'lab/1.L/index.html' => 'l']);
        $config = self::config("[semester]\nstart = 2026-08-24\nend = 2026-08-31\n"
            . "[schedule]\nmon = class 08:00-08:50\n[days]\n2026-08-26 = lab 13:30-15:20 + class\n");
        $course = Course::load($dir, $config);
        $entries = $course->schedule->day('2026-08-26')?->entries ?? [];
        $this->assertSame([['lab', 1, '13:30-15:20'], ['class', 2, null]], array_map(
            static fn ($e) => [$e->type, $e->number, $e->meeting !== null ? (string) $e->meeting : null],
            $entries,
        ), 'both entries of the day get events, each with its own meeting time');
        $this->assertSame('08:00-08:50', (string) $course->schedule->eventEntry('class', 1)?->meeting);
        $this->assertSame(null, $course->schedule->eventEntry('class', 2)?->meeting);
        $this->assertSame(['2026-08-24', '2026-08-26'], array_keys($course->schedule->days), 'Monday the 31st has no third class');
    }

    public function testListedBoxPickUsesTheListedOrder(): void
    {
        $dir = $this->course(['class/1.A/index.html' => 'h', 'class/1.A/index.md' => 'm', 'class/1.A/slides.pdf' => 'p']);
        $listed = self::config("[semester]\nstart = 2026-08-24\nend = 2026-08-31\n[schedule]\nmon = class\n[boxes]\ntitle = slides.pdf, index.md, index.html\n");
        $this->assertSame(['title' => 'slides.pdf'], Course::load($dir, $listed)->content->event('class', 1)?->boxes);

        $byName = self::config("[semester]\nstart = 2026-08-24\nend = 2026-08-31\n[schedule]\nmon = class\n[boxes]\ntitle = slides.pdf, index.md, index.html\n[display]\nbox_pick = first-by-name\n");
        $this->assertSame(['title' => 'index.html'], Course::load($dir, $byName)->content->event('class', 1)?->boxes);
    }

    public function testInlineCandidatesAndFileKinds(): void
    {
        $dir = $this->course(['class/1.A/notes.txt' => 'one line', 'class/1.A/deck.pptx' => 'x', 'class/1.A/page.htm' => 'x']);
        $config = self::config("[semester]\nstart = 2026-08-24\nend = 2026-08-31\n[schedule]\nmon = class\n[boxes]\nnotes = inline:notes.txt\n");
        $event = Course::load($dir, $config)->content->event('class', 1);
        $this->assertTrue($event?->file('notes.txt')?->inline ?? false);
        $this->assertSame(FileKind::Text, $event?->file('notes.txt')?->kind);
        $this->assertSame(FileKind::Download, $event?->file('deck.pptx')?->kind);
        $this->assertSame(FileKind::Htm, $event?->file('page.htm')?->kind);
        $this->assertSame(FileKey::forFile(self::SECRET, 'class', '1.A', 'notes.txt'), $event?->file('notes.txt')?->key);
        $this->assertSame('notes.txt', $event?->fileByKey(FileKey::forFile(self::SECRET, 'class', '1.A', 'notes.txt'))?->name);
    }

    public function testFileKinds(): void
    {
        $this->assertSame(FileKind::Html, FileKind::fromPath('a/B.HTML'));
        $this->assertSame(FileKind::Markdown, FileKind::fromPath('notes.md'));
        $this->assertSame(FileKind::Text, FileKind::fromPath('notes.txt'));
        $this->assertSame(FileKind::Pdf, FileKind::fromPath('slides.pdf'));
        $this->assertSame(FileKind::Download, FileKind::fromPath('slides.ppt'));
        $this->assertSame(FileKind::Download, FileKind::fromPath('slides.pptx'));
        $this->assertSame(FileKind::Download, FileKind::fromPath('Makefile'));
        $this->assertTrue(FileKind::Text->isPage());
        $this->assertFalse(FileKind::Pdf->isPage());
    }

    public function testAllowedPathsAndProblems(): void
    {
        $outside = sys_get_temp_dir() . '/cal5-outside-' . bin2hex(random_bytes(4));
        $this->dirs[] = $outside;
        mkdir("$outside/shared", 0777, true);
        file_put_contents("$outside/shared/common.html", 'shared');
        file_put_contents("$outside/secret.txt", 'secret');
        $dir = $this->course([
            'class/1.A/index.html' => 'a',
            'class/1.A/common.html' => "@link:$outside/shared/common.html",
            'class/1.A/secret.txt' => "@link:$outside/secret.txt",
            'virtual.txt' => "# comment\nextra.html\tshared/nope.html\tclass\t1\nbad line\n",
        ]);
        $sections = "[semester]\nstart = 2026-08-24\nend = 2026-08-31\n[schedule]\nmon = class\n[files]\nvirtual = virtual.txt\n";

        $course = Course::load($dir, self::config($sections));
        $event = $course->content->event('class', 1);
        $this->assertSame(['index.html'], array_keys($event?->files ?? []), 'links outside the course are ignored');
        $problems = implode("\n", array_map('strval', $course->problems()));
        $this->assertContains('class/1.A/common.html points outside the course', $problems);
        $this->assertContains('virtual.txt:3: ignored (expected: virtual-name, real-file, type, event-number): bad line', $problems);
        $this->assertContains('virtual file extra.html: shared/nope.html is missing or outside the course', $problems);

        $allowed = Course::load($dir, self::config($sections . "allowed_paths = $outside/shared\n"));
        $this->assertSame(['common.html', 'index.html'], array_keys($allowed->content->event('class', 1)?->files ?? []), 'allowed path is followed');
        $this->assertSame(realpath("$outside/shared/common.html"), $allowed->content->event('class', 1)?->file('common.html')?->actualPath);
    }

    public function testEventNamesAndEmptyTypes(): void
    {
        $dir = $this->course(['class/1.Intro.Part2/a.html' => 'a', 'class/Plain/b.html' => 'b', 'lab/readme.txt' => 'no events']);
        $config = self::config("[semester]\nstart = 2026-08-24\nend = 2026-08-31\n[schedule]\nmon = class\nwed = lab\n");
        $course = Course::load($dir, $config);
        $this->assertSame(['Intro.Part2', 'Plain'], array_map(static fn ($e) => $e->name, $course->content->events('class')));
        $this->assertFalse($course->content->hasEvents('lab'), 'a type directory without event directories has no events');
        $this->assertSame(null, $course->schedule->day('2026-08-26'), 'so its days do not appear');
    }
}
