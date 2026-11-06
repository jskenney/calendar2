<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Audit\CourseAudit;
use Cal5\Render\LegacyScripts;
use Cal5\Tests\TestCase;
use Cal5\View\CompatCss;
use Cal5\View\Icons;

/** Milestone 8: which pages get jQuery, DataTables and Chart.js, and what compat.css covers. */
final class LegacyContentTest extends TestCase
{
    public function testScriptDetection(): void
    {
        $this->assertSame(['jquery'], LegacyScripts::usedBy("$('#x').hide();"));
        $this->assertSame(['jquery'], LegacyScripts::usedBy('$.ajax({url: "a"});'));
        $this->assertSame(['jquery'], LegacyScripts::usedBy('jQuery(document).ready(f);'));
        $this->assertSame(['jquery', 'datatables'], LegacyScripts::usedBy("$('#t').DataTable();"), 'DataTables needs jQuery');
        $this->assertSame(['jquery', 'datatables'], LegacyScripts::usedBy("$('#t').dataTable({});"));
        $this->assertSame(['chartjs'], LegacyScripts::usedBy("new Chart(ctx, {type: 'bar'});"));
        $this->assertSame(['chartjs'], LegacyScripts::usedBy('Chart.defaults.global.legend.display = false;'));
        foreach (['var total = $amount + 1;', 'x.$(y)', 'price.$value', 'let s = "$5";', 'myChart.update();', 'const charts = 1;'] as $js) {
            $this->assertSame([], LegacyScripts::usedBy($js), $js);
        }
    }

    public function testOnlyScriptsCount(): void
    {
        $text = '<p>It costs $(5) and \\(f(x) = $x\\)</p><script type="text/x-mathjax-config">$(x)</script>';
        $this->assertSame([], LegacyScripts::usedBy(LegacyScripts::scriptText($text)), 'text and MathJax configuration are not scripts');
        $this->assertSame([], LegacyScripts::usedBy(LegacyScripts::scriptText('<script src="jquery-plugin.js"></script>')));
        $this->assertSame(['jquery'], LegacyScripts::usedBy(LegacyScripts::scriptText('<button onclick="$(&#39;#a&#39;).toggle()">x</button>')), 'event handlers');
        $this->assertSame(['jquery'], LegacyScripts::usedBy(LegacyScripts::scriptText('<a href="javascript:$(\'#a\').show()">x</a>')), 'javascript: links');
        $this->assertSame(['chartjs'], LegacyScripts::usedBy(LegacyScripts::scriptText("<SCRIPT>\nnew Chart(c, {});\n</SCRIPT>")));
    }

    public function testWhatPagesLoad(): void
    {
        $jquery = "<script>$('#a').hide();</script>";
        $this->assertSame(['jquery'], LegacyScripts::forPage(['auto'], $jquery));
        $this->assertSame([], LegacyScripts::forPage(['auto'], '<p>plain</p>'), 'auto: nothing on pages that do not need it');
        $this->assertSame([], LegacyScripts::forPage([], $jquery), 'off: nothing, even if the page uses jQuery');
        $this->assertSame(['jquery', 'chartjs'], LegacyScripts::forPage(['chartjs', 'jquery'], '<p>plain</p>'), 'listed: on every page, in order');
        $this->assertSame(['jquery', 'datatables'], LegacyScripts::forPage(['datatables'], ''), 'DataTables brings jQuery');
        $this->assertSame(['jquery', 'chartjs'], LegacyScripts::forPage(['auto', 'chartjs'], $jquery));
        $this->assertSame(['jquery'], LegacyScripts::forPage(['auto'], '<p>plain</p>', "jQuery('.x').fadeIn();"), "the course's own scripts count");
    }

    public function testGeneratedCssIsUpToDate(): void
    {
        $css = (string) file_get_contents(CompatCss::FILE);
        $this->assertSame(CompatCss::build($css), $css, 'public/css/compat.css is up to date (run: php bin/cal5 build-compat-css)');
        foreach (array_keys(Icons::GLYPHICONS) as $glyph) {
            $this->assertContains('.glyphicon-' . $glyph . ',', str_replace(')', ',', $css), "glyphicon-$glyph has a mask");
            $this->assertTrue(Icons::dataUri($glyph) !== null, $glyph);
        }
        $this->assertContains('.col-md-6 { flex: 0 0 50%; max-width: 50%; }', $css);
        $this->assertContains('.col-xs-12 { flex: 0 0 100%; max-width: 100%; }', $css);
        $this->assertContains('@media (min-width: 992px)', $css);
        $this->assertFalse((bool) preg_match('/https?:\/\/(?!www\.w3\.org)/', $css), 'nothing from other hosts');
    }

    /** @return list<string> a selector list split at its top-level commas */
    private static function selectors(string $list): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($list) as $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $parts[] = $current;
        return $parts;
    }

    public function testCompatCssCoversTheMilestoneList(): void
    {
        $handled = CourseAudit::handledClasses();
        // MILESTONES.md: table*, row / columns / col-*, btn*, alert*, panel*, well, jumbotron, label, badge, text-*, pull-*,
        // img-responsive, glyphicons
        foreach (['table', 'table-striped', 'table-bordered', 'table-hover', 'table-condensed', 'table-responsive', 'row', 'column',
                  'columns', 'six', 'one-third', 'col-xs-6', 'col-sm-4', 'col-md-8', 'col-lg-3', 'col-md-offset-2', 'btn', 'btn-primary',
                  'btn-default', 'btn-success', 'btn-info', 'btn-warning', 'btn-danger', 'btn-link', 'btn-lg', 'btn-sm', 'btn-xs',
                  'btn-block', 'alert', 'alert-success', 'alert-info', 'alert-warning', 'alert-danger', 'panel', 'panel-default',
                  'panel-primary', 'panel-heading', 'panel-title', 'panel-body', 'panel-footer', 'well', 'jumbotron', 'label',
                  'label-default', 'label-success', 'badge', 'text-muted', 'text-center', 'text-right', 'text-danger', 'pull-left',
                  'pull-right', 'img-responsive', 'glyphicon', 'button', 'button-primary', 'u-full-width', 'lead', 'list-unstyled'] as $class) {
            $this->assertTrue(isset($handled[$class]), "compat.css styles .$class");
        }
        $css = (string) file_get_contents(CompatCss::FILE);
        $rules = preg_replace('/\/\*.*?\*\//s', '', $css);
        foreach (explode('}', (string) $rules) as $rule) {
            $selector = trim(explode('{', $rule)[0]);
            if ($selector === '' || str_starts_with($selector, ':root') || str_starts_with($selector, '@media') || $selector === ':root') {
                continue;
            }
            foreach (self::selectors((string) preg_replace('/^@media[^{]*\{/', '', $selector)) as $part) {
                $part = trim($part);
                if ($part !== '' && !str_starts_with($part, ':root') && !str_starts_with($part, '@media') && !preg_match('/^[.\w-]+\)?$/', $part)) {
                    $this->assertTrue(str_starts_with($part, ':where(.cal5-page)'), "scoped to the page content: $part");
                }
            }
        }
    }
}
