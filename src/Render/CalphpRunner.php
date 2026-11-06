<?php

declare(strict_types=1);

namespace Cal5\Render;

use Cal5\Render\Tags\Attributes;

/**
 * <calphp src="script.php">: runs a PHP file of the event where the tag is and
 * puts its output there (v4).  Instructor-written code, run as in v4: in the
 * script's own directory, with the course's v4 variables ($COURSE, ...) and
 * $INSTRUCTOR available.
 */
final class CalphpRunner
{
    public static function run(string $html, PageContext $page): string
    {
        $output = '';
        while (preg_match('/<calphp[^>]+>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $output .= substr($html, 0, $m[0][1]);
            $src = Attributes::doubleQuoted($m[0][0], 'src');
            if ($src !== null && isset($page->files[$src])) {
                $output .= self::include($page->files[$src]->actualPath, $page);
            }
            $html = substr($html, $m[0][1] + strlen($m[0][0]));
        }
        return $output . $html;
    }

    private static function include(string $script, PageContext $page): string
    {
        $real = realpath($script);
        if ($real === false) {
            return '';
        }
        $cwd = (string) getcwd();
        chdir(dirname($real));
        ob_start();
        try {
            (static function (string $cal5Script, array $cal5Variables): void {
                extract($cal5Variables, EXTR_SKIP);
                include $cal5Script;
            })($real, $page->phpVariables + ['INSTRUCTOR' => $page->instructor]);
        } finally {
            $result = (string) ob_get_clean();
            chdir($cwd);
        }
        return $result;
    }
}
