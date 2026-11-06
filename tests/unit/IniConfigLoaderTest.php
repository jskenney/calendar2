<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Config\ConfigSource;
use Cal5\Config\Defaults;
use Cal5\Config\IniConfigLoader;
use Cal5\Config\LoadResult;
use Cal5\Tests\TestCase;

final class IniConfigLoaderTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private static function load(string $ini): LoadResult
    {
        return IniConfigLoader::fromString($ini, 'test.ini');
    }

    private static function minimal(string $extra = ''): string
    {
        return "[course]\nnumber = SI460\nname = Graphics\n[semester]\nstart = 2026-08-24\nend = 2026-12-18\n"
            . "[schedule]\nmon = class\nwed = lab\n[security]\npassword = pw\nsecret = " . self::SECRET . "\n" . $extra;
    }

    /** @return list<string> */
    private static function messages(array $problems): array
    {
        return array_map('strval', $problems);
    }

    public function testMinimalConfigUsesDefaults(): void
    {
        $result = self::load(self::minimal());
        $this->assertSame([], self::messages($result->errors));
        $this->assertSame(ConfigSource::Ini, $result->source);
        $config = $result->config;
        $this->assertTrue($config !== null);
        if ($config === null) {
            return;
        }
        $this->assertSame('SI460', $config->course->title, 'title defaults to the course number');
        $this->assertSame(date_default_timezone_get(), $config->course->timezone);
        $this->assertSame(array_keys(Defaults::BOXES), array_map(static fn ($b) => $b->name, $config->boxes));
        $this->assertSame(['class', 'lab'], array_map(static fn ($m) => $m->type, $config->menus));
        $this->assertSame(null, $config->instructorMenus);
        $this->assertSame(Defaults::LOAD_PAGES, $config->files->loadPages);
        $this->assertSame(43200, $config->security->sessionSeconds);
        $this->assertSame('listed', $config->display->boxPick);
        $this->assertSame('end', $config->features->postmeeting);
        $this->assertFalse($config->features->legacyCss);
        $this->assertSame([], $config->features->legacyJs);
    }

    public function testScheduleDaysAndCombine(): void
    {
        $config = self::load(self::minimal(
            "[schedule]\nfri = class 08:00-08:50\nThursday = lab 13:30 - 15:20\n"
            . "[days]\n2026-12-18 = Final Exam 08:00-10:00\n2026-10-14 = lab + class\n2026-09-07 = HOLIDAY\n"
            . "[combine]\nclass 3 = lab, Final Exam\n"
        ))->config;
        $this->assertTrue($config !== null);
        if ($config === null) {
            return;
        }
        $this->assertSame(['mon', 'wed', 'thu', 'fri'], array_keys($config->schedule->weekly), 'weekdays in calendar order');
        $this->assertSame([['type' => 'class', 'label' => 'class', 'meeting' => '08:00-08:50']], $config->schedule->weekly['fri']->toArray());
        $this->assertSame('13:30-15:20', $config->schedule->weekly['thu']->toArray()[0]['meeting']);
        $this->assertSame(['2026-09-07', '2026-10-14', '2026-12-18'], array_keys($config->schedule->days), 'days sorted');
        $this->assertSame(['type' => 'final-exam', 'label' => 'Final Exam', 'meeting' => '08:00-10:00'], $config->schedule->days['2026-12-18']->toArray()[0]);
        $this->assertSame(['lab', 'class'], array_column($config->schedule->days['2026-10-14']->toArray(), 'type'));
        $this->assertSame('holiday', $config->schedule->days['2026-09-07']->entries[0]->type);
        $this->assertSame(['lab', 'final-exam'], $config->schedule->combine[0]->add);
    }

    public function testBoxesKeepOrderAndInlineFiles(): void
    {
        $config = self::load(self::minimal("[boxes]\ntitle = index.md, index.html\nNotes = inline:notes.txt, notes.html\n"))->config;
        $this->assertSame(['title', 'Notes'], array_map(static fn ($b) => $b->name, $config?->boxes ?? []));
        $this->assertSame(['inline:notes.txt', 'notes.html'], array_map('strval', $config?->box('Notes')?->candidates ?? []));
        $this->assertTrue($config?->box('Notes')?->candidates[0]->inline ?? false);
    }

    public function testRequiredSettings(): void
    {
        $result = self::load("[security]\nsecret = " . self::SECRET . "\n");
        $this->assertSame(null, $result->config, 'no Config when there are errors');
        $messages = implode("\n", self::messages($result->errors));
        $this->assertContains('[course] number is required', $messages);
        $this->assertContains('[semester] start is required', $messages);
        $this->assertContains('[semester] end is required', $messages);
    }

    public function testInvalidValuesAreReportedWithLineNumbers(): void
    {
        $result = self::load(self::minimal(
            "[semester]\nweekends = maybe\n[schedule]\nfunday = class\ntue = lab 14:00-13:00\n"
            . "[days]\n2026-02-30 = exam\n[display]\ncalendar_view = grid\n[features]\nlegacy_js = jquery, angular\n"
            . "[course]\ntimezone = Mars/Base\n"
        ));
        $messages = self::messages($result->errors);
        $expected = [
            'test.ini:14: [semester] weekends: expected yes or no, got "maybe"',
            'test.ini:25: [course] timezone: unknown time zone "Mars/Base" (use a name like America/New_York)',
            'test.ini:16: [schedule] expected a weekday such as mon or monday, got "funday"',
            'test.ini:17: [schedule] the meeting must end after it starts: "lab 14:00-13:00"',
            'test.ini:19: [days] expected a date like 2026-08-24, got "2026-02-30"',
            'test.ini:21: [display] calendar_view: expected one of month, list, agenda, got "grid"',
            'test.ini:23: [features] legacy_js: expected any of jquery, datatables, chartjs, got "angular"',
        ];
        foreach ($expected as $message) {
            $this->assertTrue(in_array($message, $messages, true), "reports: $message\n  got: " . implode("\n       ", $messages));
        }
    }

    public function testTypesMustBeSafeDirectoryNames(): void
    {
        $result = self::load(self::minimal("[days]\n2026-09-01 = ../etc\n[menus]\n../x = Bad\n"));
        $messages = implode("\n", self::messages($result->errors));
        $this->assertContains('can not be used as a type', $messages);
    }

    public function testUnknownSectionsAndKeysAreWarnings(): void
    {
        $result = self::load(self::minimal("[colours]\nx = 1\n[course]\nnumbr = typo\n"));
        $this->assertSame([], self::messages($result->errors));
        $warnings = implode("\n", self::messages($result->warnings));
        $this->assertContains('test.ini:13: unknown section [colours] is ignored', $warnings);
        $this->assertContains('test.ini:16: unknown setting "numbr" in [course] is ignored', $warnings);
    }

    public function testSecretChecks(): void
    {
        $sample = self::load(str_replace(self::SECRET, Defaults::SAMPLE_SECRET, self::minimal()));
        $this->assertContains('published sample value', implode("\n", self::messages($sample->errors)));

        $weak = self::load(str_replace(self::SECRET, 'short', self::minimal()));
        $this->assertSame([], self::messages($weak->errors));
        $this->assertContains('the secret is weak', implode("\n", self::messages($weak->warnings)));

        $installer = self::load(str_replace(self::SECRET, 'A really long phrase that should be impossible to guess 12345, you dont need to remember this...', self::minimal()));
        $this->assertContains('the secret is weak', implode("\n", self::messages($installer->warnings)), 'old installer secrets are weak');
    }

    public function testPasswordSettings(): void
    {
        $hash = password_hash('pw', PASSWORD_DEFAULT);
        $hashed = self::load(str_replace("password = pw\n", "password_hash = $hash\n", self::minimal()));
        $this->assertSame($hash, $hashed->config?->security->passwordHash);
        $this->assertNotContains('plain text', implode("\n", self::messages($hashed->warnings)));

        $bad = self::load(str_replace("password = pw\n", "password_hash = not-a-hash\n", self::minimal()));
        $this->assertContains('is not a password hash', implode("\n", self::messages($bad->errors)));

        $none = self::load(str_replace("password = pw\n", '', self::minimal()));
        $this->assertContains('instructor login is disabled', implode("\n", self::messages($none->warnings)));
    }

    public function testLockSessionAndStyle(): void
    {
        $config = self::load(self::minimal(
            "[security]\nlock = LOCKED\nsession_hours = 1.5\n[style]\ncss = a.css, b.css\ncss.class = c.css\ncss.calendar = cal.css\njs.lab = lab.js\ncss.class = d.css\n"
        ))->config;
        $this->assertSame('LOCKED', $config?->security->lockFile);
        $this->assertFalse($config?->security->lockAlways ?? true);
        $this->assertSame(5400, $config?->security->sessionSeconds);
        $this->assertSame(['a.css', 'b.css'], $config?->style->css);
        $this->assertSame(['class' => ['c.css', 'd.css']], $config?->style->cssByType);
        $this->assertSame(['cal.css'], $config?->style->cssCalendar);
        $this->assertSame(['lab' => ['lab.js']], $config?->style->jsByType);

        $locked = self::load(self::minimal("[security]\nlock = yes\n"))->config;
        $this->assertTrue($locked?->security->lockAlways ?? false);
        $this->assertSame(null, $locked?->security->lockFile);
    }

    /** The examples in docs/design/configuration.md must load (keeps the docs honest). */
    public function testDocumentationExamplesLoad(): void
    {
        $doc = (string) file_get_contents(__DIR__ . '/../../docs/design/configuration.md');
        preg_match_all('/```ini\n(.*?)```/s', $doc, $m);
        $examples = array_values(array_filter($m[1], static fn (string $ini): bool => str_contains($ini, '[course]')));
        $this->assertTrue(count($examples) >= 2, 'the minimal and complete examples are found');
        foreach ($examples as $i => $ini) {
            $ini = str_replace(['$2y$12$...', '9f3c...'], [password_hash('x', PASSWORD_DEFAULT), self::SECRET], $ini);
            $result = self::load($ini);
            $this->assertSame([], self::messages($result->errors), "example $i has no errors");
            $this->assertSame([], self::messages($result->warnings), "example $i has no warnings");
        }
    }
}
