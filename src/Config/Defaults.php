<?php

declare(strict_types=1);

namespace Cal5\Config;

/** Default values for settings a calendar.ini.php leaves out. */
final class Defaults
{
    /**
     * Boxes shown in each calendar day (the v4 sample course's boxes, with .md versions).
     * @var array<string, list<string>>
     */
    public const BOXES = [
        'title' => ['index.html', 'index.md', 'slides.pdf', 'slides.pptx', 'slides.ppt'],
        'instructor' => ['instructor.html', 'instructor.md', 'instructor.pdf', 'instructor.pptx', 'inline:instructor.txt'],
        'reading' => ['reading.html', 'reading.md', 'reading.pdf', 'reading.pptx', 'inline:reading.txt'],
        'notes' => ['notes.html', 'notes.md', 'inline:notes.txt'],
        'lab-solution' => ['labanswers.html', 'labanswers.md', 'labanswers.pdf', 'labanswers.pptx'],
        'homework' => ['homework.html', 'homework.md', 'homework.pdf', 'homework.pptx', 'inline:homework.txt'],
        'homework-solution' => ['answers.html', 'answers.md', 'answers.pdf', 'answers.pptx', 'inline:answers.txt'],
    ];

    /** v4's default navbar menus. @var array<string, string> type => icon */
    public const MENUS = ['class' => 'blackboard', 'lab' => 'knight'];

    /** @var list<string> */
    public const LOAD_PAGES = ['home', 'policy', 'resources'];

    public const SESSION_SECONDS = 43200; // 12 hours, v4 $LOGON_TIME default

    /** @var list<string> */
    public const LEGACY_JS = ['jquery', 'datatables', 'chartjs'];

    /** The published sample secret of the v4 calendar shell; refused like calendar-core 4.5 does. */
    public const SAMPLE_SECRET = 'A really long phrase that should be impossible to guess, you dont need to remember this...';

    /** @return list<Box> */
    public static function boxes(): array
    {
        $boxes = [];
        foreach (self::BOXES as $name => $files) {
            $boxes[] = new Box($name, array_map([Values::class, 'candidate'], $files));
        }
        return $boxes;
    }

    /** @return list<MenuItem> */
    public static function menus(): array
    {
        $menus = [];
        foreach (self::MENUS as $type => $icon) {
            $menus[] = new MenuItem($type, Values::menuLabel($type), $icon);
        }
        return $menus;
    }
}
