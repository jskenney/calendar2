<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Tests\TestCase;
use Cal5\View\Icons;

/**
 * Milestone 7: the theme's colour contrast (WCAG AA, 4.5:1 for text) in the light and
 * dark themes, the icons, and the size of the one script.
 */
final class ThemeTest extends TestCase
{
    private const CSS = __DIR__ . '/../../public/css/cal5.css';

    /** @return array{0: array<string, string>, 1: array<string, string>} light and dark tokens */
    private static function tokens(): array
    {
        $css = (string) file_get_contents(self::CSS);
        $read = static function (string $block): array {
            preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-f]{6})/i', $block, $m, PREG_SET_ORDER);
            $tokens = [];
            foreach ($m as $match) {
                $tokens[$match[1]] = strtolower($match[2]);
            }
            return $tokens;
        };
        $darkAt = (int) strpos($css, '@media (prefers-color-scheme: dark)');
        $light = $read(substr($css, 0, $darkAt));
        $dark = array_merge($light, $read(substr($css, $darkAt, (int) strpos($css, '/* ---------- Base') - $darkAt)));
        return [$light, $dark];
    }

    private static function luminance(string $hex): float
    {
        $channel = static function (int $v): float {
            $c = $v / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $channel((int) hexdec(substr($hex, 1, 2))) + 0.7152 * $channel((int) hexdec(substr($hex, 3, 2)))
            + 0.0722 * $channel((int) hexdec(substr($hex, 5, 2)));
    }

    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** $a laid over $b with opacity $p */
    private static function mix(string $a, string $b, float $p): string
    {
        $out = '#';
        for ($i = 1; $i <= 5; $i += 2) {
            $out .= sprintf('%02x', (int) round(hexdec(substr($a, $i, 2)) * $p + hexdec(substr($b, $i, 2)) * (1 - $p)));
        }
        return $out;
    }

    public function testContrastInBothThemes(): void
    {
        $this->assertTrue(abs(self::contrast('#000000', '#ffffff') - 21) < 0.01, 'the formula');
        foreach (array_combine(['light', 'dark'], self::tokens()) as $theme => $t) {
            $pairs = [['text', 'bg'], ['text', 'surface'], ['muted', 'bg'], ['muted', 'surface'], ['muted', 'surface-2'],
                ['accent', 'bg'], ['accent', 'surface'], ['accent', 'accent-soft'], ['accent-text', 'accent'],
                ['warn-text', 'warn-bg'], ['text', 'today'], ['accent', 'today'], ['muted', 'today']];
            for ($i = 0; $i < 8; $i++) {
                $pairs[] = ["c$i", "c$i-bg"];
            }
            foreach ($pairs as [$fore, $back]) {
                $this->assertTrue(isset($t[$fore], $t[$back]), "$theme: --$fore and --$back are defined");
                $ratio = self::contrast($t[$fore] ?? '#000000', $t[$back] ?? '#000000');
                $this->assertTrue($ratio >= 4.5, sprintf('%s: --%s on --%s is %.2f:1', $theme, $fore, $back, $ratio));
            }
            // calendar entries: the type's tint (55%) over the surface, with text, links and muted text on it
            for ($i = 0; $i < 8; $i++) {
                $background = self::mix($t["c$i-bg"], $t['surface'], 0.55);
                foreach (['text', 'accent', 'muted'] as $fore) {
                    $ratio = self::contrast($t[$fore], $background);
                    $this->assertTrue($ratio >= 4.5, sprintf('%s: --%s on a c%d entry is %.2f:1', $theme, $fore, $i, $ratio));
                }
            }
        }
    }

    public function testThemeBasics(): void
    {
        $css = (string) file_get_contents(self::CSS);
        $this->assertContains('@media (prefers-color-scheme: dark)', $css);
        $this->assertContains('@media print', $css);
        $this->assertContains(':focus-visible', $css, 'focus styles');
        $this->assertContains('prefers-reduced-motion', $css);
        $this->assertNotContains('@import', $css, 'one stylesheet');
        $this->assertFalse((bool) preg_match('/https?:\/\//', $css), 'nothing from other hosts');
    }

    public function testScriptBudget(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../public/js/cal5.js');
        $this->assertTrue(strlen($js) < 10240, 'cal5.js is under 10 KB (' . strlen($js) . ' bytes)');
        $this->assertNotContains('jQuery', $js);
        $this->assertNotContains('$(', $js);
    }

    public function testIcons(): void
    {
        $this->assertSame('presentation', Icons::resolve('glyphicon-blackboard'));
        $this->assertSame('flask-conical', Icons::resolve('knight'), 'translated v4 configs drop the glyphicon- prefix');
        $this->assertSame('hourglass', Icons::resolve('Hourglass'));
        $this->assertSame('calendar', Icons::resolve('calendar'), 'Cal5 names');
        $this->assertSame(null, Icons::resolve('glyphicon-no-such-icon'));
        $this->assertSame('', Icons::svg('no-such-icon'));
        $svg = Icons::svg('lock');
        $this->assertContains('aria-hidden="true"', $svg, 'icons are decorative');
        $this->assertContains('viewBox="0 0 24 24"', $svg);
        foreach (Icons::GLYPHICONS as $glyph => $icon) {
            $this->assertTrue(in_array($icon, Icons::names(), true), "glyphicon-$glyph maps to an icon that exists ($icon)");
        }
        // the icons v4's default menus and the fixtures use
        foreach (['blackboard', 'knight', 'apple', 'hourglass', 'education'] as $glyph) {
            $this->assertTrue(Icons::resolve($glyph) !== null, $glyph);
        }
    }
}
