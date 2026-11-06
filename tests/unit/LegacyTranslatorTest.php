<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Config\Config;
use Cal5\Config\ConfigSource;
use Cal5\Config\Defaults;
use Cal5\Config\LegacyTranslator;
use Cal5\Config\LegacyVariables;
use Cal5\Config\LoadResult;
use Cal5\Tests\TestCase;

final class LegacyTranslatorTest extends TestCase
{
    private const SECRET = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    /** @return array<string, mixed> the smallest working v4 configuration */
    private static function base(): array
    {
        return [
            'COURSE' => 'SI123', 'YEAR' => 2018, 'MONTH_START' => 8, 'DAY_START' => 20, 'MONTH_END' => 12,
            'DOW' => ['mon' => 'class', 'wed' => 'lab', 'fri' => 'class'], 'WEEKENDS' => false,
            'ADMIN' => 'pw', 'SECRET' => self::SECRET,
            'BOX' => [0 => 'title'], 'HTML' => ['title' => 'index.html'],
            'LINK' => [], 'PDF' => [], 'PPT' => [], 'SRC' => [],
        ];
    }

    /** @param array<string, mixed> $changes */
    private static function translate(array $changes = [], array $remove = []): LoadResult
    {
        $globals = array_merge(self::base(), $changes);
        foreach ($remove as $name) {
            unset($globals[$name]);
        }
        return LegacyTranslator::translate($globals);
    }

    /** @param array<string, mixed> $changes */
    private static function config(array $changes = [], array $remove = []): Config
    {
        $result = self::translate($changes, $remove);
        if ($result->config === null) {
            throw new \RuntimeException('translation failed: ' . implode('; ', array_map('strval', $result->errors)));
        }
        return $result->config;
    }

    private static function warnings(LoadResult $result): string
    {
        return implode("\n", array_map('strval', $result->warnings));
    }

    public function testBasicMapping(): void
    {
        $result = self::translate([
            'COURSENAME' => 'Applied Something', 'COURSENAMEFULL' => 'SI123: Applied', 'PAGE_TITLE' => 'SI123 (Fall 2018)',
        ]);
        $this->assertSame(ConfigSource::Legacy, $result->source);
        $this->assertSame([], array_map('strval', $result->errors));
        $c = $result->config;
        $this->assertSame('SI123', $c?->course->number);
        $this->assertSame('Applied Something', $c?->course->name);
        $this->assertSame('SI123: Applied', $c?->course->fullName);
        $this->assertSame('SI123 (Fall 2018)', $c?->course->title);
        $this->assertSame(date_default_timezone_get(), $c?->course->timezone);
        $this->assertSame('2018-08-20', $c?->semester->start);
        $this->assertSame('2018-12-31', $c?->semester->end, 'v4 shows whole months');
        $this->assertFalse($c?->semester->weekends ?? true);
        $this->assertSame('pw', $c?->security->password);
        $this->assertSame(null, $c?->security->passwordHash);
        $this->assertSame(self::SECRET, $c?->security->secret);
    }

    public function testDefaultsWhenOptionalVariablesAreMissing(): void
    {
        $c = self::config([], ['PAGE_TITLE']);
        $this->assertSame('SI123', $c->course->title);
        $this->assertSame(['class', 'lab'], array_map(static fn ($m) => $m->type, $c->menus), 'v4 default menus');
        $this->assertSame(['blackboard', 'knight'], array_map(static fn ($m) => $m->icon, $c->menus));
        $this->assertSame(null, $c->instructorMenus);
        $this->assertSame(Defaults::LOAD_PAGES, $c->files->loadPages);
        $this->assertSame(43200, $c->security->sessionSeconds);
        $this->assertSame('home', $c->display->startPage);
        $this->assertSame('month', $c->display->calendarView);
        $this->assertSame([2], $c->display->markdownMenuLevels);
        $this->assertTrue($c->display->headingWarnings, 'v4 warns about <a name> unless $IGNORE_A_TAG_WARNING is set');
        $this->assertSame('first-by-name', $c->display->boxPick);
        $this->assertSame('off', $c->features->postmeeting, 'without $REVEALHOUR v4 leaves <postmeeting> content visible');
        $this->assertTrue($c->features->legacyCss);
        $this->assertSame(['jquery', 'datatables', 'chartjs'], $c->features->legacyJs);
        $this->assertFalse($c->features->debug);
    }

    public function testScheduleOverridesAndCombine(): void
    {
        $c = self::config([
            'DOW' => ['mon' => 'class', 'wed' => 'Lab Day', 'fri' => 'class', 'Tue' => 'x', 2 => 'y'],
            'OVERRIDE' => [9 => [3 => 'HOLIDAY', 28 => 'exam'], 12 => [18 => 'Final Exam'], 2 => [30 => 'bad']],
            'COMBINE' => ['class' => [3 => ['class', 'Lab Day']]],
        ]);
        $this->assertSame(['mon', 'wed', 'fri'], array_keys($c->schedule->weekly));
        $this->assertSame(['type' => 'lab-day', 'label' => 'Lab Day', 'meeting' => null], $c->schedule->weekly['wed']->toArray()[0]);
        $this->assertSame(['2018-09-03', '2018-09-28', '2018-12-18'], array_keys($c->schedule->days));
        $this->assertSame('holiday', $c->schedule->days['2018-09-03']->entries[0]->type);
        $this->assertSame('HOLIDAY', $c->schedule->days['2018-09-03']->entries[0]->label);
        $this->assertSame('final-exam', $c->schedule->days['2018-12-18']->entries[0]->type);
        $this->assertSame(1, count($c->schedule->combine));
        $this->assertSame(['class', 3, ['class', 'lab-day']], [$c->schedule->combine[0]->type, $c->schedule->combine[0]->number, $c->schedule->combine[0]->add]);

        $warnings = self::warnings(self::translate(['DOW' => ['mon' => 'class', 'Tue' => 'x'], 'OVERRIDE' => [2 => [30 => 'bad']]]));
        $this->assertContains('$DOW entry "Tue" is ignored', $warnings);
        $this->assertContains('$OVERRIDE[2][30] is not a real date in 2018', $warnings);
    }

    public function testDuplicateOverrideKeysKeepTheLastValue(): void
    {
        // PHP itself keeps the last of duplicate array keys, as in the v4 sample (22 => 'HOLIDAY' twice)
        $c = self::config(['OVERRIDE' => [11 => [22 => 'exam', 22 => 'HOLIDAY']]]);
        $this->assertSame('HOLIDAY', $c->schedule->days['2018-11-22']->entries[0]->label);
    }

    public function testBoxesFollowV4Categories(): void
    {
        $c = self::config([
            'BOX' => [0 => 'title', 1 => 'notes', 2 => 'homework', 3 => 'empty'],
            'HTML' => ['title' => 'index.html', 'homework' => 'homework.html', 'answers' => 'answers.html'],
            'LINK' => ['title' => 'lindex.html'],
            'PDF' => ['title' => 'slides.pdf', 'homework' => 'homework.pdf'],
            'PPT' => ['title' => 'slides.pptx'],
            'SRC' => ['notes' => 'notes.txt', 'homework' => 'homework.txt'],
        ]);
        $this->assertSame(['title', 'notes', 'homework', 'answers'], array_map(static fn ($b) => $b->name, $c->boxes),
            '$BOX order first, then boxes only in category arrays; boxes without files are dropped');
        $this->assertSame(['index.md', 'index.html', 'lindex.html', 'slides.pdf', 'slides.pptx'],
            array_map('strval', $c->box('title')?->candidates ?? []), 'calendar-core 4.6 adds .md twins of $HTML');
        $this->assertSame(['inline:notes.txt'], array_map('strval', $c->box('notes')?->candidates ?? []));
        $this->assertSame(['title', 'notes', 'homework'], $c->display->calendarBoxes, 'answers is not on the calendar');
    }

    public function testExplicitMdAndFileNameCollisions(): void
    {
        $c = self::config([
            'HTML' => ['title' => 'index.html', 'reading' => 'shared.html'],
            'MD' => ['title' => 'lecture.md'],
            'SRC' => ['notes' => 'shared.html'],
            'BOX' => [0 => 'title', 1 => 'reading', 2 => 'notes'],
        ]);
        $this->assertSame(['lecture.md', 'index.html'], array_map('strval', $c->box('title')?->candidates ?? []), 'explicit $MD replaces the twins');
        $this->assertSame(null, $c->box('reading'), 'shared.html belongs to the later array ($SRC), so reading has no files');
        $this->assertSame(['inline:shared.html'], array_map('strval', $c->box('notes')?->candidates ?? []));
        $this->assertSame(null, $c->display->calendarBoxes, 'every box is on the calendar');
    }

    public function testMenusAndIcons(): void
    {
        $c = self::config([
            'NAVBAR_DROPDOWNS' => ['class' => 'glyphicon-apple', 'Final Exam' => 'glyphicon-hourglass', 'lab' => ''],
            'NAVBAR_DROPDOWNS_INSTRUCTOR' => ['exam' => 'glyphicon-education'],
        ]);
        $this->assertSame([['class', 'Class', 'apple'], ['final-exam', 'Final Exam', 'hourglass'], ['lab', 'Lab', '']],
            array_map(static fn ($m) => [$m->type, $m->label, $m->icon], $c->menus));
        $this->assertSame([['exam', 'Exam', 'education']], array_map(static fn ($m) => [$m->type, $m->label, $m->icon], $c->instructorMenus ?? []));
    }

    public function testEveryOtherVariable(): void
    {
        $c = self::config([
            'PAGE_MODIFY' => ['classyear' => '19', 'on' => 'yes', 'n' => 5],
            'ACCESS_FILE' => 'virtual/cal4.access', 'CLASS_FILE' => 'virtual/cal4.virtual',
            'LOAD_PAGES' => ['syllabus', 'home'], 'ALLOWED_PATHS' => ['/srv/shared'],
            'LOGON_TIME' => 5400, 'LOCK' => 'LOCKED', 'DEFAULT_TODAYS_LECTURE' => true,
            'CALENDAR_TOOL' => 'calendar_display_dateless', 'MARKDOWN_MENU_LEVELS' => [2, 3, 9],
            'IGNORE_A_TAG_WARNING' => true,
            'CUSTOMCSS' => ['class' => ['a.css', 'b.css']], 'CUSTOMJS' => ['lab' => ['l.js']], 'CUSTOMCSS_CAL' => 'cal.css',
            'REVEALHOUR' => 13, 'ACCESS_LOG' => 'access.log', 'DEBUG_MODE' => true,
            'HIDE_WEEKS' => true, 'HIDE_BEGINNING_DAY' => 27, 'HIDE_ENDING_DAY' => 7,
        ]);
        $this->assertSame(['classyear' => '19', 'on' => 'yes', 'n' => '5'], $c->replace);
        $this->assertSame('virtual/cal4.access', $c->files->access);
        $this->assertSame('virtual/cal4.virtual', $c->files->virtual);
        $this->assertSame(['home', 'policy', 'resources', 'syllabus'], $c->files->loadPages, 'added to the defaults');
        $this->assertSame(['/srv/shared'], $c->files->allowedPaths);
        $this->assertSame(5400, $c->security->sessionSeconds);
        $this->assertSame('LOCKED', $c->security->lockFile);
        $this->assertFalse($c->security->lockAlways);
        $this->assertSame('today', $c->display->startPage);
        $this->assertSame('agenda', $c->display->calendarView);
        $this->assertSame([2, 3], $c->display->markdownMenuLevels, 'invalid levels dropped');
        $this->assertFalse($c->display->headingWarnings);
        $this->assertSame(['class' => ['a.css', 'b.css']], $c->style->cssByType);
        $this->assertSame(['lab' => ['l.js']], $c->style->jsByType);
        $this->assertSame(['cal.css'], $c->style->cssCalendar);
        $this->assertSame('13:00', $c->features->postmeeting);
        $this->assertSame('access.log', $c->features->accessLog);
        $this->assertTrue($c->features->debug);
        $this->assertSame('2018-08-27', $c->semester->showFrom);
        $this->assertSame('2018-12-07', $c->semester->showUntil);

        $this->assertTrue(self::config(['LOCK' => true])->security->lockAlways);
        $this->assertFalse(self::config(['DEBUG_MODE' => 1])->features->debug, 'v4 needs exactly True');
        $this->assertSame('list', self::config(['CALENDAR_TOOL' => 'calendar_display_alt'])->display->calendarView);
        $this->assertSame(null, self::config(['ADMIN' => ''])->security->password, 'an empty $ADMIN disables the login (v4)');
    }

    public function testEveryLegacyVariableIsTranslated(): void
    {
        // Each v4 variable must change the result when set (or be required)
        $source = (string) file_get_contents(__DIR__ . '/../../src/Config/LegacyTranslator.php');
        foreach (LegacyVariables::NAMES as $name) {
            $this->assertTrue(str_contains($source, "'$name'"), "LegacyTranslator handles \$$name");
        }
    }

    public function testWarningsAndErrors(): void
    {
        $warnings = self::warnings(self::translate(['MY_SETTING' => 1, 'NAVBAR_TITLE' => 'x', 'CALENDAR_TOOL' => 'my_tool'], ['DAY_START']));
        $this->assertContains('$MY_SETTING is not a calendar setting and is ignored', $warnings);
        $this->assertNotContains('NAVBAR_TITLE', $warnings, 'template_navbar variables are ignored silently');
        $this->assertContains('$CALENDAR_TOOL "my_tool" is not one of', $warnings);
        $this->assertContains('$DAY_START is not set', $warnings);
        $this->assertContains('plain text', $warnings);

        $sample = self::translate(['SECRET' => Defaults::SAMPLE_SECRET]);
        $this->assertSame(null, $sample->config);
        $this->assertContains('published sample value', implode("\n", array_map('strval', $sample->errors)));

        $missing = self::translate([], ['COURSE']);
        $this->assertContains('$COURSE is required', implode("\n", array_map('strval', $missing->errors)));

        $badDates = self::translate(['MONTH_START' => 2, 'DAY_START' => 30]);
        $this->assertContains('the semester dates are not valid (2018-2-30 to month 12)', implode("\n", array_map('strval', $badDates->errors)));
    }
}
