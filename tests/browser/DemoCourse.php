<?php

declare(strict_types=1);

namespace Cal5\Tests\Browser;

use DateInterval;
use DateTimeImmutable;

/**
 * A demo course in calendar.ini.php form whose semester is placed around a given day,
 * so screenshots show "today", upcoming releases and re-locked material.  Used by
 * tests/browser (screenshots and the browser check), not by the compatibility suite.
 */
final class DemoCourse
{
    public const PASSWORD = 'demo-password';

    private const TOPICS = ['Welcome', 'Pixels and Color', 'Vectors', 'Matrices', 'Transformations', 'Homogeneous Coordinates',
        'Viewing', 'Projection', 'Clipping', 'Rasterization', 'Shading', 'Lighting', 'Textures', 'Mipmaps', 'Shadows',
        'Curves', 'Surfaces', 'Meshes', 'Scene Graphs', 'Animation', 'Quaternions', 'Ray Tracing', 'Acceleration',
        'Global Illumination', 'Sampling', 'Antialiasing', 'GPU Pipeline', 'Shaders', 'Compute', 'Particles',
        'Collision', 'Physics', 'Visualization', 'Volume Rendering', 'Image Processing', 'Review', 'Projects',
        'Presentations', 'Wrap-up', 'Extra'];
    private const LABS = ['Setup', 'Drawing Lines', 'Triangles', 'Transforms', 'Camera', 'Lighting', 'Textures', 'Shadows',
        'Curves', 'Meshes', 'Animation', 'Ray Tracer', 'Shaders', 'Particles', 'Final Lab', 'Extra Lab'];

    /** @return array<string, string> path => contents */
    public static function files(DateTimeImmutable $today): array
    {
        // a 16-week semester that started about 10 weeks ago, on a Monday
        $start = $today->modify('monday this week')->sub(new DateInterval('P70D'));
        $end = $start->add(new DateInterval('P109D'));
        $holiday = $start->add(new DateInterval('P35D'));          // a Monday
        $exam1 = $start->add(new DateInterval('P46D'));            // a Friday
        $exam2 = $start->add(new DateInterval('P95D'));
        $d = static fn (DateTimeImmutable $day): string => $day->format('Y-m-d');

        $files = [
            'index.php' => "<?php require __DIR__ . '/calendar/index.php';\n",
            'calendar.ini.php' => ";<?php http_response_code(404); exit; ?>\n"
                . "[course]\nnumber = SI460\nname = Computer Graphics\nfull_name = SI460: Computer Graphics\ntitle = SI460\ntimezone = America/New_York\n"
                . "[semester]\nstart = {$d($start)}\nend = {$d($end)}\n"
                . "[schedule]\nmon = class 09:00-09:50\nwed = class 09:00-09:50\nthu = lab 13:30-15:20\nfri = class 09:00-09:50\n"
                . "[days]\n{$d($holiday)} = holiday\n{$d($exam1)} = exam 09:00-09:50\n{$d($exam2)} = exam 09:00-09:50\n"
                . "[boxes]\ntitle = index.html, index.md, slides.pdf\nslides = slides.pdf, slides.pptx\nreading = inline:reading.txt\n"
                . "homework = homework.html, homework.md\nhomework-solution = answers.html, answers.md\nlab-solution = labanswers.md\n"
                . "[menus]\nclass = Classes | blackboard\nlab = Labs | knight\nexam = Exams | hourglass\n"
                . "[files]\naccess = virtual/cal4.access\n"
                . "[security]\npassword = " . self::PASSWORD . "\nsecret = 3f2a9c4e8b7d6a5f4e3d2c1b0a9f8e7d6c5b4a3f2e1d0c9b\n"
                . "[display]\ncalendar_view = month\n",
            'home.html' => "<h1>SI460: Computer Graphics</h1>\n<p>Welcome to the course.</p>\n",
            // release rules: titles two days before class at 08:00, solutions a week later at 17:00
            // and locked again after two weeks, labs open at the start of the meeting
            'virtual/cal4.access' => "class/title -2 08:00\nclass/homework-solution +7 17:00 until +21 23:59\n"
                . "lab start-15m\nlab/lab-solution end+1d\nexam/title always\nclass_4/slides never\n",
        ];
        foreach (self::TOPICS as $i => $topic) {
            $dir = sprintf('class/%02d.%s/', $i + 1, $topic);
            $files[$dir . 'index.md'] = self::lecture($i + 1, $topic);
            $files[$dir . 'slides.pdf'] = "%PDF-1.4\n% demo slides for $topic\n";
            if ($i % 2 === 0) {
                $files[$dir . 'homework.html'] = "<h1>Homework " . ($i + 1) . "</h1>\n<ol><li>Problem one</li><li>Problem two <student><b>Answer:</b> 42</student></li></ol>\n";
                $files[$dir . 'answers.html'] = "<h1>Solutions</h1>\n<p>Worked solutions for homework " . ($i + 1) . ".</p>\n";
            }
            if ($i % 3 === 1) {
                $files[$dir . 'reading.txt'] = "Read: chapter " . ($i + 2) . "\n";
            }
        }
        foreach (self::LABS as $i => $lab) {
            $dir = sprintf('lab/%02d.%s/', $i + 1, $lab);
            $files[$dir . 'index.md'] = "# Lab " . ($i + 1) . ": $lab\n\n## Goals\n\nBuild something that draws.\n\n## Steps\n\n1. Clone the starter code.\n2. Run `make`.\n3. Submit.\n";
            $files[$dir . 'labanswers.md'] = "# Lab " . ($i + 1) . " solution\n\nThe reference implementation.\n";
        }
        $files['exam/01.Midterm/index.html'] = "<h1>Midterm</h1>\n<p>Covers classes 1-14.</p>\n";
        $files['exam/02.Final/index.html'] = "<h1>Final</h1>\n<p>Cumulative.</p>\n";
        return $files;
    }

    private static function lecture(int $number, string $topic): string
    {
        return "# $topic\n\n"
            . "## Overview\n\nClass $number introduces **$topic**. The key equation is \\(p' = M p\\), where\n\n"
            . "\\[ M = \\begin{bmatrix} \\cos\\theta & -\\sin\\theta \\\\ \\sin\\theta & \\cos\\theta \\end{bmatrix} \\]\n\n"
            . "## Example\n\n```python\nimport numpy as np\n\ndef rotate(p, theta):\n    m = np.array([[np.cos(theta), -np.sin(theta)],\n                  [np.sin(theta),  np.cos(theta)]])\n    return m @ p  # rotate the point\n```\n\n"
            . "## Exercises\n\n| Problem | Points |\n|---|---|\n| Rotate a square | 10 |\n| Compose two rotations | 15 |\n\n"
            . "> Tip: draw a picture first.\n";
    }
}
