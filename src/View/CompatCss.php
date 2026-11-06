<?php

declare(strict_types=1);

namespace Cal5\View;

/**
 * The generated parts of public/css/compat.css (milestone 8): Bootstrap 3's column
 * grid and the glyphicons.  `php bin/cal5 build-compat-css` writes them between the
 * marker comments; tests/unit/LegacyContentTest.php checks the file is up to date.
 */
final class CompatCss
{
    public const FILE = __DIR__ . '/../../public/css/compat.css';
    private const START = '/* generated:start (php bin/cal5 build-compat-css) */';
    private const END = '/* generated:end */';

    /** Bootstrap 3 grid: col-xs-N always, col-sm/md/lg-N from 768/992/1200px; offsets, push, pull. */
    public static function grid(): string
    {
        $css = '';
        foreach (['xs' => null, 'sm' => 768, 'md' => 992, 'lg' => 1200] as $size => $min) {
            $rules = '';
            for ($n = 1; $n <= 12; $n++) {
                $width = rtrim(rtrim(sprintf('%.6f', $n / 12 * 100), '0'), '.') . '%';
                $rules .= ":where(.cal5-page) .col-$size-$n { flex: 0 0 $width; max-width: $width; }\n";
            }
            for ($n = 0; $n <= 11; $n++) {
                $width = rtrim(rtrim(sprintf('%.6f', $n / 12 * 100), '0'), '.') . '%';
                $rules .= ":where(.cal5-page) .col-$size-offset-$n { margin-left: $width; }\n";
            }
            $css .= $min === null ? $rules : "@media (min-width: {$min}px) {\n" . $rules . "}\n";
        }
        return $css;
    }

    /** One mask per glyphicon name Cal5 maps (Icons::GLYPHICONS). */
    public static function glyphicons(): string
    {
        $byIcon = [];
        foreach (Icons::GLYPHICONS as $glyph => $icon) {
            $byIcon[$icon][] = '.glyphicon-' . $glyph;
        }
        ksort($byIcon);
        $css = '';
        foreach ($byIcon as $icon => $selectors) {
            $css .= ':where(.cal5-page) :is(' . implode(', ', $selectors) . ') { --glyph: url("' . Icons::dataUri($icon) . "\"); }\n";
        }
        return $css;
    }

    public static function generated(): string
    {
        return self::START . "\n" . self::grid() . self::glyphicons() . self::END;
    }

    /** compat.css with the generated part replaced. */
    public static function build(string $css): string
    {
        $start = strpos($css, self::START);
        $end = strpos($css, self::END);
        if ($start === false || $end === false) {
            return rtrim($css) . "\n\n" . self::generated() . "\n";
        }
        return substr($css, 0, $start) . self::generated() . substr($css, $end + strlen(self::END));
    }
}
