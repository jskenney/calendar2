<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Access\AccessPolicy;
use Cal5\Access\AccessRuleParser;
use Cal5\Access\EventContext;
use Cal5\Access\FixedClock;
use Cal5\Access\LegacyDateWhen;
use Cal5\Access\PostMeeting;
use Cal5\Config\Config;
use Cal5\Config\IniConfigLoader;
use Cal5\Config\MeetingTime;
use Cal5\Course;
use Cal5\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Release rules with an injected clock: minute boundaries, ranges, meeting
 * times, daylight saving, year boundaries (v4's own rule forms are compared
 * with v4 by tests/compat check-structure, fixture access-rules).
 */
final class AccessTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const TZ = 'America/New_York';

    /** @var list<string> */
    private array $dirs = [];

    public function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::remove($dir);
        }
        $this->dirs = [];
    }

    /**
     * A course with class/1.A .. class/N (index.html, notes.html) and the given rules.
     */
    private function course(string $rules, string $sections, int $events = 3, array $extraFiles = []): Course
    {
        $dir = sys_get_temp_dir() . '/cal5-access-' . bin2hex(random_bytes(4));
        $this->dirs[] = $dir;
        for ($n = 1; $n <= $events; $n++) {
            @mkdir("$dir/class/$n.C$n", 0777, true);
            file_put_contents("$dir/class/$n.C$n/index.html", "class $n");
            file_put_contents("$dir/class/$n.C$n/notes.html", "notes $n");
        }
        foreach ($extraFiles as $path => $contents) {
            @mkdir(dirname("$dir/$path"), 0777, true);
            file_put_contents("$dir/$path", $contents);
        }
        file_put_contents("$dir/access.txt", $rules);
        $result = IniConfigLoader::fromString("[course]\nnumber = T1\ntimezone = " . self::TZ . "\n"
            . "[security]\npassword = pw\nsecret = " . self::SECRET . "\n[files]\naccess = access.txt\n" . $sections);
        if ($result->config === null) {
            throw new \RuntimeException(implode('; ', array_map('strval', $result->errors)));
        }
        return Course::load($dir, $result->config);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone(self::TZ));
    }

    private static function visible(Course $course, int $event, string $file, string $time): bool
    {
        $policy = AccessPolicy::forCourse($course, FixedClock::at($time, self::TZ));
        $folder = $course->content->event('class', $event);
        $f = $folder?->file($file);
        return $folder !== null && $f !== null && $policy->visibleToStudents($folder, $f);
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

    private const WEEKLY = "[semester]\nstart = 2026-09-14\nend = 2026-12-18\n[schedule]\nmon = class 08:00-08:50\n";

    public function testTodayIsTheCourseDate(): void
    {
        // 00:30 UTC on Saturday 7 November is still Friday 6 November in New York (UTC-5)
        $course = $this->course('', "[semester]\nstart = 2026-11-02\nend = 2026-11-13\n[schedule]\nmon = class\nwed = class\nfri = class\n", 6);
        $policy = AccessPolicy::forCourse($course, FixedClock::at('2026-11-07 00:30', 'UTC'));
        $this->assertSame('2026-11-06 19:30 America/New_York', $policy->now()->format('Y-m-d H:i e'), 'now, in the course time zone');
        $this->assertSame('2026-11-06', (new \Cal5\View\CalendarModel($course, $policy, false))->today, "the calendar's today");
        $this->assertSame('2026-11-06', $policy->now()->format('Y-m-d'));
    }

    public function testExactMinute(): void
    {
        $course = $this->course("class/title 2026-09-14 13:30\n", self::WEEKLY);
        $this->assertFalse(self::visible($course, 1, 'index.html', '2026-09-14 13:29:59'), 'one second before');
        $this->assertTrue(self::visible($course, 1, 'index.html', '2026-09-14 13:30:00'), 'at the minute');
        $this->assertTrue(self::visible($course, 1, 'notes.html', '2026-09-01 00:00'), 'other boxes have no rule');
    }

    public function testUntilRangeLocksAgain(): void
    {
        $course = $this->course("class_1/notes.html 2026-09-14 08:00 until 2026-09-14 09:50\n", self::WEEKLY);
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2026-09-14 07:59'));
        $this->assertTrue(self::visible($course, 1, 'notes.html', '2026-09-14 08:00'));
        $this->assertTrue(self::visible($course, 1, 'notes.html', '2026-09-14 09:49:59'));
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2026-09-14 09:50'), 'locked again at the end of the range');
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2027-01-01 00:00'));
    }

    public function testForDuration(): void
    {
        $course = $this->course("class_1/notes.html 2026-10-02 12:00 for 1d12h\n", self::WEEKLY);
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2026-10-02 11:59'));
        $this->assertTrue(self::visible($course, 1, 'notes.html', '2026-10-03 23:59'));
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2026-10-04 00:00'), '36 hours later');
    }

    public function testMeetingRelativeRules(): void
    {
        $course = $this->course("class/title start-15m\nclass/notes start until end\n", self::WEEKLY);
        // class 1 is Monday 2026-09-14 08:00-08:50
        $this->assertFalse(self::visible($course, 1, 'index.html', '2026-09-14 07:44'));
        $this->assertTrue(self::visible($course, 1, 'index.html', '2026-09-14 07:45'), '15 minutes before class');
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2026-09-14 07:59'));
        $this->assertTrue(self::visible($course, 1, 'notes.html', '2026-09-14 08:30'), 'during class');
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2026-09-14 08:50'), 'locked when class ends');
        $this->assertTrue(self::visible($course, 2, 'index.html', '2026-09-21 07:45'), 'each event uses its own date');
    }

    public function testStartAndEndWithoutMeetingTimesMeanMidnight(): void
    {
        $course = $this->course("class/title start\nclass/notes end+2h\n", "[semester]\nstart = 2026-09-14\nend = 2026-12-18\n[schedule]\nmon = class\n");
        $this->assertFalse(self::visible($course, 1, 'index.html', '2026-09-13 23:59'));
        $this->assertTrue(self::visible($course, 1, 'index.html', '2026-09-14 00:00'));
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2026-09-14 01:59'));
        $this->assertTrue(self::visible($course, 1, 'notes.html', '2026-09-14 02:00'));
    }

    public function testDayOffsetsWithTimesAndWeekdays(): void
    {
        $course = $this->course("class/title +7 17:00\nclass/notes -friday 08:00\n", self::WEEKLY);
        $this->assertFalse(self::visible($course, 1, 'index.html', '2026-09-21 16:59'));
        $this->assertTrue(self::visible($course, 1, 'index.html', '2026-09-21 17:00'));
        // Monday 2026-09-14: the Friday before is 2026-09-11
        $this->assertFalse(self::visible($course, 1, 'notes.html', '2026-09-11 07:59'));
        $this->assertTrue(self::visible($course, 1, 'notes.html', '2026-09-11 08:00'));
    }

    public function testDaylightSavingTime(): void
    {
        // Spring forward: 2026-03-08 02:00 -> 03:00 in New York.  Event on Saturday 2026-03-07, 10:00-11:00.
        $context = new EventContext('2026-03-07', new MeetingTime('10:00', '11:00'), new DateTimeZone(self::TZ), 2026);
        $this->assertSame('2026-03-08 10:00 EDT', AccessRuleParser::when('start+1d')->at($context)?->format('Y-m-d H:i T'),
            '+1d keeps the clock time');
        $this->assertSame('2026-03-08 11:00 EDT', AccessRuleParser::when('start+24h')->at($context)?->format('Y-m-d H:i T'),
            '+24h is exactly 24 hours');
        // Fall back: 2026-11-01 02:00 -> 01:00.  Event on Saturday 2026-10-31.
        $fall = new EventContext('2026-10-31', new MeetingTime('10:00', '11:00'), new DateTimeZone(self::TZ), 2026);
        $this->assertSame('2026-11-01 10:00 EST', AccessRuleParser::when('start+1d')->at($fall)?->format('Y-m-d H:i T'));
        $this->assertSame('2026-11-01 09:00 EST', AccessRuleParser::when('start+24h')->at($fall)?->format('Y-m-d H:i T'));
    }

    public function testYearBoundary(): void
    {
        $course = $this->course("class/title +7\n", "[semester]\nstart = 2026-12-28\nend = 2027-01-15\n[schedule]\nmon = class\n");
        $this->assertSame('2027-01-04', $course->schedule->dateOf('class', 2));
        $this->assertFalse(self::visible($course, 1, 'index.html', '2027-01-03 23:59'));
        $this->assertTrue(self::visible($course, 1, 'index.html', '2027-01-04 00:00'), '2026-12-28 + 7 days');
    }

    public function testV4DateFormula(): void
    {
        $tz = new DateTimeZone('UTC');
        $context = new EventContext(null, null, $tz, 2018);
        $at = static fn (string $rule): ?string => AccessRuleParser::when($rule)->at($context)?->format('Y-m-d');
        $this->assertSame('2018-03-01', $at('2 30'), 'an impossible date opens on the 1st of the next month');
        $this->assertSame('2018-06-01', $at('6 1'), 'the year defaults to the course year');
        $this->assertSame(null, $at('99 99 9999'));
        $this->assertSame(null, $at('13 1 2018'), 'month 13 never opens');
        $this->assertSame('2018-01-01', $at('0 5 2018'));
        $this->assertSame('2018-05-01', $at('5 0 2018'));

        $rule = new LegacyDateWhen(2, 30, null, '2 30');
        $this->assertFalse($rule->reached($context, new DateTimeImmutable('2018-02-28 23:59', $tz)));
        $this->assertTrue($rule->reached($context, new DateTimeImmutable('2018-03-01 00:00', $tz)));
        $this->assertTrue((new LegacyDateWhen(1, 1, 1999, ''))->reached($context, new DateTimeImmutable('2000-01-01', $tz)));
    }

    public function testUnmappedEventsAreHiddenFromStudents(): void
    {
        $course = $this->course("class always\n", "[semester]\nstart = 2026-09-14\nend = 2026-09-20\n[schedule]\nmon = class\n", 2);
        $this->assertTrue(self::visible($course, 1, 'index.html', '2026-10-01 00:00'));
        $this->assertFalse(self::visible($course, 2, 'index.html', '2026-10-01 00:00'), 'class 2 has no day on the calendar');
        $policy = AccessPolicy::forCourse($course);
        $event = $course->content->event('class', 2);
        $file = $event?->file('index.html');
        $this->assertSame('not on the calendar (students never see it)', $event !== null && $file !== null ? $policy->window($event, $file)->describe() : '');
    }

    public function testVirtualFilesMustBeAllowedUnderBothNames(): void
    {
        $sections = self::WEEKLY . "[files]\nvirtual = virtual.txt\n";
        $files = ['shared/sheet.html' => 'shared', 'virtual.txt' => "sheet.html\tshared/sheet.html\tclass\t1\n"];
        $byReal = $this->course("shared/sheet.html never\n", $sections, 1, $files);
        $this->assertFalse(self::visible($byReal, 1, 'sheet.html', '2026-10-01 00:00'), 'rule on the real path (v4 serve check)');
        $byName = $this->course("sheet.html never\n", $sections, 1, $files);
        $this->assertFalse(self::visible($byName, 1, 'sheet.html', '2026-10-01 00:00'), 'rule on the virtual name (v4 calendar check)');
        $none = $this->course("", $sections, 1, $files);
        $this->assertTrue(self::visible($none, 1, 'sheet.html', '2026-10-01 00:00'));
    }

    public function testUnreadableAccessFileHidesEverything(): void
    {
        $course = $this->course("class always\n", self::WEEKLY);
        unlink($course->directory . '/access.txt');
        mkdir($course->directory . '/access.txt'); // exists but can not be read as a file
        $this->assertFalse(self::visible($course, 1, 'index.html', '2026-10-01 00:00'));
        $problems = implode("\n", array_map('strval', AccessPolicy::forCourse($course)->problems()));
        $this->assertContains('can not be read', $problems);
        rmdir($course->directory . '/access.txt');
        file_put_contents($course->directory . '/access.txt', '');
    }

    public function testWindowDescriptions(): void
    {
        $course = $this->course("class_1/title 2026-09-14 13:30 until 2026-09-18 23:59\nclass_1/notes never\nclass_2 always\n"
            . "class_3/title 2026-09-30 until 2026-09-29\n", self::WEEKLY);
        $policy = AccessPolicy::forCourse($course);
        $describe = static function (int $n, string $f) use ($course, $policy): string {
            $event = $course->content->event('class', $n);
            $file = $event?->file($f);
            return $event !== null && $file !== null ? $policy->window($event, $file)->describe() : '';
        };
        $this->assertSame('opens Mon 14 Sep 2026 13:30 · locks Fri 18 Sep 2026 23:59', $describe(1, 'index.html'));
        $this->assertSame('never visible', $describe(1, 'notes.html'));
        $this->assertSame('visible', $describe(2, 'index.html'));
        $this->assertContains('locks before it opens', $describe(3, 'index.html'));
    }

    public function testParserForms(): void
    {
        $rules = AccessRuleParser::parse("# comment\n\nshared docs/x.pdf\t3 4 2026\nclass_1/a +2W 09:15\n"
            . "x +7 until +14\nbad line here\ny 2026-13-01\nz +12345\n", 'rules');
        $this->assertSame(['shared docs/x.pdf', 'class_1/a', 'x'], array_keys($rules->rules));
        $this->assertSame([
            'rules:6: can not understand "line here" (see docs/design/access-rules.md): bad line here',
            'rules:7: "2026-13-01" is not a real date: y 2026-13-01',
            'rules:8: "12345" is not a number of days of up to 4 digits (7, 7d, 2w, 3h, 90m) or a weekday in "+12345": z +12345',
        ], array_map('strval', $rules->problems));
        $this->assertSame('x +7 until +14', (string) $rules->get('x'));
    }

    /** Random input must only ever produce "ignored line" problems, never errors or warnings. */
    public function testFuzzedLinesNeverBreakTheParser(): void
    {
        mt_srand(4242);
        $alphabet = str_split("abcz019 +-:\t/_*.#untilfor 2026-09-14 13:30 start end monday 99");
        for ($i = 0; $i < 3000; $i++) {
            $line = '';
            $length = mt_rand(1, 40);
            for ($j = 0; $j < $length; $j++) {
                $line .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            try {
                AccessRuleParser::parseLine($line);
            } catch (InvalidArgumentException) {
                // reported as an ignored line: fine
            }
        }
        $this->assertTrue(true, 'no PHP errors or unexpected exceptions');
    }

    public function testPostMeeting(): void
    {
        $tz = new DateTimeZone(self::TZ);
        $event = new EventContext('2026-09-14', new MeetingTime('08:00', '08:50'), $tz, 2026);
        $noMeeting = new EventContext('2026-09-14', null, $tz, 2026);
        $unmapped = new EventContext(null, null, $tz, 2026);
        $config = fn (string $setting): Config => $this->postmeetingConfig($setting);

        $this->assertTrue(PostMeeting::visible($config('off'), $unmapped, self::at('2000-01-01')), 'off: always shown');
        $this->assertFalse(PostMeeting::visible($config('end'), $event, self::at('2026-09-14 08:49')));
        $this->assertTrue(PostMeeting::visible($config('end'), $event, self::at('2026-09-14 08:50')), 'end of the meeting');
        $this->assertFalse(PostMeeting::visible($config('end'), $noMeeting, self::at('2026-09-14 23:59')));
        $this->assertTrue(PostMeeting::visible($config('end'), $noMeeting, self::at('2026-09-15 00:00')), 'end of the day without a meeting');
        $this->assertFalse(PostMeeting::visible($config('13:00'), $event, self::at('2026-09-14 12:59')));
        $this->assertTrue(PostMeeting::visible($config('13:00'), $event, self::at('2026-09-14 13:00')), 'v4 $REVEALHOUR = 13');
        $this->assertTrue(PostMeeting::visible($config('24:00'), $event, self::at('2026-09-15 00:00')));
        $this->assertFalse(PostMeeting::visible($config('end'), $unmapped, self::at('2030-01-01')), 'not on the calendar: hidden');
    }

    private function postmeetingConfig(string $setting): Config
    {
        $config = IniConfigLoader::fromString("[course]\nnumber = T1\n[semester]\nstart = 2026-09-14\nend = 2026-12-18\n"
            . "[security]\npassword = pw\nsecret = " . self::SECRET . "\n[features]\npostmeeting = $setting\n")->config;
        if ($config === null) {
            throw new \RuntimeException('config');
        }
        return $config;
    }
}
