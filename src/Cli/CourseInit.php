<?php

declare(strict_types=1);

namespace Cal5\Cli;

use Cal5\Config\IniConfigLoader;
use Cal5\Config\IniWriter;
use RuntimeException;

/**
 * bin/cal5 init (used by installer.sh): sets up a new course in a directory, with a
 * calendar.ini.php (random secret, hashed password), index.php, starter pages, a first
 * class, release rules with examples, and .htaccess files that keep the material
 * directories private.  Existing courses are never touched.
 */
final class CourseInit
{
    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param array{number: string, name: string, full_name: string, start: string, end: string, class_days: list<string>,
     *              lab_days: list<string>, timezone: string, password: string} $answers
     * @return list<string> the files written
     */
    public static function create(string $dir, array $answers): array
    {
        foreach (['calendar.php', 'calendar.ini.php'] as $existing) {
            if (is_file("$dir/$existing")) {
                throw new RuntimeException("$existing already exists here: this is a course already (see docs/migration.md to move a v4 course)");
            }
        }
        self::validate($answers);
        $q = static fn (string $value): string => IniWriter::quote($value);
        $schedule = [];
        foreach (self::WEEKDAYS as $day) {
            $types = array_merge(in_array($day, $answers['class_days'], true) ? ['class'] : [], in_array($day, $answers['lab_days'], true) ? ['lab'] : []);
            if ($types !== []) {
                $schedule[] = "$day = " . implode(' + ', $types);
            }
        }
        $menus = "class = Classes | blackboard\n" . ($answers['lab_days'] !== [] ? "lab   = Labs    | knight\n" : '');
        $hash = password_hash($answers['password'], PASSWORD_DEFAULT);
        $secret = bin2hex(random_bytes(32));
        $ini = <<<INI
;<?php http_response_code(404); exit; ?>
; Course configuration (Cal5).  Every setting: calendar/docs/design/configuration.md
; Check it with: php calendar/bin/cal5 check

[course]
number    = {$q($answers['number'])}
name      = {$q($answers['name'])}
full_name = {$q($answers['full_name'])}
title     = {$q($answers['number'])}
timezone  = {$q($answers['timezone'])}

[semester]
start    = {$answers['start']}
end      = {$answers['end']}
weekends = no

[schedule]
; weekday = type [meeting time], for example: mon = class 08:00-08:50
INI;
        $ini .= "\n" . implode("\n", $schedule) . "\n\n[days]\n; date = what happens that day, for example:\n; 2026-09-07 = holiday\n; 2026-10-02 = exam 08:00-09:50\n\n"
            . "[boxes]\n; box = files that can fill it, the first one found wins\n"
            . "title             = index.html, index.md, slides.pdf, slides.pptx\n"
            . "slides            = slides.pdf, slides.pptx, slides.ppt\n"
            . "reading           = reading.html, reading.md, reading.pdf, inline:reading.txt\n"
            . "homework          = homework.html, homework.md, homework.pdf\n"
            . "homework-solution = answers.html, answers.md, answers.pdf\n"
            . "lab-solution      = labanswers.html, labanswers.md, labanswers.pdf\n\n"
            . "[menus]\n$menus\n"
            . "[files]\naccess    = virtual/cal4.access\nvirtual   = virtual/cal4.virtual\nload_pages = home, policy, resources\n\n"
            . "[security]\npassword_hash = $hash\nsecret        = $secret\nsession_hours = 12\n\n"
            . "[display]\nstart_page    = home\ncalendar_view = month\n";

        $files = [
            'calendar.ini.php' => $ini,
            'index.php' => "<?php require __DIR__ . '/calendar/index.php';\n",
            'home.md' => "# {$answers['number']}: {$answers['name']}\n\nWelcome. The **Calendar** shows every class; its material appears as it is released.\n",
            'policy.md' => "# Course policy\n\nGrading, deadlines and contact information.\n",
            'resources.md' => "# Resources\n\nBooks, links and software for the course.\n",
            'class/01.Welcome/index.md' => "# Welcome\n\n## Overview\n\nThe first class of {$answers['number']}.\n\n## Before next class\n\nRead the course policy.\n",
            'class/01.Welcome/homework.md' => "# Homework 1\n\n1. Read the course policy.\n2. Answer: what do you want to learn? <student>**Answer:** anything!</student>\n",
            'class/.htaccess' => "# Material is only served through the calendar (release rules)\nRequire all denied\n",
            'virtual/.htaccess' => "Require all denied\n",
            'virtual/cal4.access' => "# Release rules: target  when.  Reference: calendar/docs/release-rules.md\n"
                . "# Class pages two days before class, at 08:00\nclass/title                 -2 08:00\n"
                . "# Homework solutions a week after class at 17:00, for two weeks\nclass/homework-solution     +7 17:00 until +21 23:59\n",
            'virtual/cal4.virtual' => "# Virtual files: name-in-event  real-file  type  event-number\n",
        ];
        if ($answers['lab_days'] !== []) {
            $files['lab/01.Setup/index.md'] = "# Lab 1: Setup\n\n## Steps\n\n1. Install the software.\n2. Run the first example.\n";
            $files['lab/.htaccess'] = "Require all denied\n";
            $files['virtual/cal4.access'] .= "# Lab solutions the day after the lab\nlab/lab-solution            +1\n";
        }
        foreach ($files as $path => $content) {
            if (!is_dir(dirname("$dir/$path"))) {
                mkdir(dirname("$dir/$path"), 0775, true);
            }
            if (!is_file("$dir/$path")) {
                file_put_contents("$dir/$path", $content);
            }
        }
        $check = IniConfigLoader::fromFile("$dir/calendar.ini.php");
        if (!$check->ok()) {
            throw new RuntimeException('the configuration written has errors: ' . implode('; ', array_map('strval', $check->errors)));
        }
        return array_keys($files);
    }

    /** @param array<string, mixed> $answers */
    private static function validate(array $answers): void
    {
        if (trim($answers['number']) === '') {
            throw new RuntimeException('the course number is required');
        }
        foreach (['start', 'end'] as $key) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $answers[$key]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $answers[$key]) || $date === false || $date->format('Y-m-d') !== $answers[$key]) {
                throw new RuntimeException("the semester $key must be a date like 2026-08-24");
            }
        }
        if ($answers['end'] < $answers['start']) {
            throw new RuntimeException('the semester ends before it starts');
        }
        if ($answers['class_days'] === []) {
            throw new RuntimeException('at least one class day is needed (mon, tue, wed, thu, fri, sat, sun)');
        }
        foreach (array_merge($answers['class_days'], $answers['lab_days']) as $day) {
            if (!in_array($day, self::WEEKDAYS, true)) {
                throw new RuntimeException("\"$day\" is not a weekday (mon, tue, wed, thu, fri, sat, sun)");
            }
        }
        if (!in_array($answers['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException("\"{$answers['timezone']}\" is not a time zone (for example America/New_York)");
        }
        if (strlen($answers['password']) < 8) {
            throw new RuntimeException('the instructor password needs at least 8 characters');
        }
    }

    /** @return list<string> "mon wed fri", "mon,wed" -> [mon, wed, fri] */
    public static function days(string $text): array
    {
        $days = preg_split('/[\s,]+/', strtolower(trim($text))) ?: [];
        return array_values(array_filter(array_map(static fn (string $d): string => substr($d, 0, 3), $days), static fn (string $d): bool => $d !== ''));
    }
}
