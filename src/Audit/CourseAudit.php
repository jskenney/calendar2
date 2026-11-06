<?php

declare(strict_types=1);

namespace Cal5\Audit;

use Cal5\Course;
use Cal5\Render\LegacyScripts;
use Cal5\View\CompatCss;
use Cal5\View\Icons;

/**
 * bin/cal5 audit (milestone 8): what a course's own pages rely on from v4's front end,
 * and whether Cal5 handles it.
 *
 * Scanned: every page of every event (.html, .htm, .md), the course's root pages
 * (*.html, *.md), and its own stylesheets and scripts ([style]).  Reported:
 *
 *  - Bootstrap 3 and Skeleton classes, glyphicons     -> compat.css (legacy_css)
 *  - jQuery, DataTables, Chart.js                     -> legacy_js
 *  - Bootstrap's JavaScript (data-toggle, .modal(...)), Font Awesome icons,
 *    hard-coded calendar/... paths to v4's files       -> not provided: needs attention
 *  - <calphp>, <ace>, .htm files, scripts from other hosts (for information)
 */
final class CourseAudit
{
    /** Classes that come from Bootstrap 3 (prefixes and names), to tell them from a course's own classes. */
    private const BOOTSTRAP_PREFIXES = ['col-', 'btn', 'alert', 'panel', 'label', 'text-', 'bg-', 'glyphicon', 'nav', 'navbar',
        'dropdown', 'modal', 'carousel', 'collapse', 'tooltip', 'popover', 'table', 'img-', 'list-group', 'progress', 'badge',
        'well', 'jumbotron', 'pull-', 'hidden-', 'visible-', 'input-group', 'form-', 'has-', 'help-block', 'caret',
        'breadcrumb', 'pagination', 'pager', 'media', 'thumbnail', 'embed-responsive', 'page-header', 'lead', 'center-block',
        'clearfix', 'sr-only', 'container', 'row', 'list-unstyled', 'list-inline', 'tab-',
        'small', 'hidden', 'show', 'invisible', 'control-label', 'input-lg',
        'input-sm', 'dl-horizontal', 'initialism', 'blockquote-reverse', 'text-hide', 'pre-scrollable'];
    /** Skeleton's classes. */
    private const SKELETON = ['container', 'row', 'column', 'columns', 'one', 'two', 'three', 'four', 'five', 'six', 'seven',
        'eight', 'nine', 'ten', 'eleven', 'twelve', 'one-third', 'two-thirds', 'one-half', 'button', 'button-primary',
        'u-full-width', 'u-max-full-width', 'u-pull-right', 'u-pull-left', 'u-cf', 'offset-by-one', 'offset-by-two',
        'offset-by-three', 'offset-by-four', 'offset-by-five', 'offset-by-six', 'offset-by-seven', 'offset-by-eight',
        'offset-by-nine', 'offset-by-ten', 'offset-by-eleven', 'offset-by-one-third', 'offset-by-two-thirds', 'offset-by-one-half'];
    /** v4 files a course page may link to directly; only the icon is kept at its v4 path. */
    private const V4_PATHS = '#\bcalendar/(bootstrap|jquery|datatables\.net|chartjs|Skeleton|Font-Awesome|fonts|css|js|MathJax[^/"\']*|highlight|ace-builds|bootstrap3-ie10-viewport-bug-workaround|images/(?!web-icon\.png)[^"\'\s>]+)[^"\'\s>]*#';

    /** @var array<string, array<string, true>> finding => files */
    private array $found = [];
    /** @var array<string, int> */
    private array $counts = [];
    /** @var list<string> */
    private array $files = [];

    public function __construct(private readonly Course $course)
    {
    }

    /**
     * @return array{files: int, findings: array<string, array<string, list<string>>>, attention: list<string>,
     *               suggest: array<string, string>, libraries: list<string>}
     */
    public function run(): array
    {
        foreach ($this->sources() as $label => $path) {
            $this->scanFile($label, $path);
        }
        $handled = self::handledClasses();
        $bootstrapCovered = [];
        $bootstrapMissing = [];
        $skeleton = [];
        $glyphMapped = [];
        $glyphMissing = [];
        foreach ($this->found as $key => $files) {
            [$kind, $name] = explode(':', $key, 2) + [1 => ''];
            $files = array_keys($files);
            if ($kind === 'class') {
                if (str_starts_with($name, 'glyphicon-')) {
                    $glyph = substr($name, 10);
                    if (Icons::resolve($glyph) !== null) {
                        $glyphMapped[$glyph] = $files;
                    } else {
                        $glyphMissing[$glyph] = $files;
                    }
                } elseif (in_array($name, self::SKELETON, true) && !in_array($name, ['container', 'row'], true)) {
                    $skeleton[$name] = $files;
                } elseif (self::isBootstrap($name)) {
                    if (isset($handled[$name])) {
                        $bootstrapCovered[$name] = $files;
                    } else {
                        $bootstrapMissing[$name] = $files;
                    }
                }
            }
        }
        $findings = [
            'bootstrap classes (compat.css)' => $bootstrapCovered,
            'skeleton classes (compat.css)' => $skeleton,
            'glyphicons (compat.css)' => $glyphMapped,
            'bootstrap classes not in compat.css' => $bootstrapMissing,
            'glyphicons without an icon' => $glyphMissing,
        ];
        foreach (['jquery' => 'jQuery', 'datatables' => 'DataTables', 'chartjs' => 'Chart.js'] as $lib => $label) {
            $findings['scripts'][$label] = array_keys($this->found["js:$lib"] ?? []);
        }
        foreach (['bootstrap-js' => "Bootstrap's JavaScript (data-toggle, .modal(), .tooltip(), ...)", 'font-awesome' => 'Font Awesome icons (fa fa-...)',
                  'v4-path' => 'links to v4 files (calendar/...)'] as $kind => $label) {
            $findings['not provided'][$label] = array_keys($this->found[$kind] ?? []);
        }
        foreach (['calphp' => '<calphp> (runs PHP)', 'ace' => '<ace> editor boxes', 'htm' => '.htm pages (shown without the layout)',
                  'external' => 'scripts or styles from other hosts'] as $kind => $label) {
            $findings['information'][$label] = array_keys($this->found[$kind] ?? []);
        }
        foreach ($findings as $group => $items) {
            $findings[$group] = array_filter($items, static fn (array $files): bool => $files !== []);
            ksort($findings[$group]);
        }

        $attention = [];
        foreach ($findings['bootstrap classes not in compat.css'] as $name => $files) {
            $attention[] = "class \"$name\" is not in compat.css (" . self::where($files) . ')';
        }
        foreach ($findings['glyphicons without an icon'] as $name => $files) {
            $attention[] = "glyphicon-$name has no Cal5 icon (" . self::where($files) . ')';
        }
        foreach ($findings['not provided'] as $label => $files) {
            $attention[] = "$label: not provided by Cal5 (" . self::where($files) . ')';
        }

        $usesCss = $bootstrapCovered !== [] || $bootstrapMissing !== [] || $skeleton !== [] || $glyphMapped !== [] || $glyphMissing !== [];
        $libs = array_keys(array_filter(['jquery' => 'jQuery', 'datatables' => 'DataTables', 'chartjs' => 'Chart.js'],
            static fn (string $label): bool => isset($findings['scripts'][$label])));
        $suggest = [
            'legacy_css' => $usesCss ? 'yes' : 'no',
            'legacy_js' => $libs === [] ? '' : 'auto',
        ];
        return ['files' => count($this->files), 'findings' => $findings, 'attention' => $attention, 'suggest' => $suggest, 'libraries' => $libs];
    }

    /** @param list<string> $files */
    private static function where(array $files): string
    {
        $shown = array_slice($files, 0, 3);
        return implode(', ', $shown) . (count($files) > 3 ? ', and ' . (count($files) - 3) . ' more' : '');
    }

    private static function isBootstrap(string $class): bool
    {
        foreach (self::BOOTSTRAP_PREFIXES as $prefix) {
            if ($class === $prefix || (str_ends_with($prefix, '-') && str_starts_with($class, $prefix))
                || (!str_ends_with($prefix, '-') && str_starts_with($class, $prefix . '-'))) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, true> class names compat.css styles */
    public static function handledClasses(): array
    {
        $css = (string) @file_get_contents(CompatCss::FILE);
        preg_match_all('/\.([a-z][a-z0-9-]*)/', (string) preg_replace('/url\("[^"]*"\)/', '', $css), $m);
        return array_fill_keys($m[1], true);
    }

    /** @return array<string, string> label => path of every file to scan */
    private function sources(): array
    {
        $dir = $this->course->directory;
        $sources = [];
        foreach (array_merge(glob($dir . '/*.html') ?: [], glob($dir . '/*.htm') ?: [], glob($dir . '/*.md') ?: []) as $path) {
            $sources[basename($path)] = $path;
        }
        foreach ($this->course->content->types as $events) {
            foreach ($events as $event) {
                foreach ($event->files as $name => $file) {
                    $ext = strtolower(pathinfo($file->actualPath, PATHINFO_EXTENSION));
                    if (in_array($ext, ['html', 'htm', 'md', 'js', 'css'], true)) {
                        $sources[$event->type . '/' . $event->directory . '/' . $name] = $file->actualPath;
                    }
                }
            }
        }
        $style = $this->course->config->style;
        foreach (array_merge($style->css, $style->cssCalendar, $style->js, ...array_values($style->cssByType), ...array_values($style->jsByType)) as $file) {
            if (is_file($dir . '/' . $file)) {
                $sources[$file] = $dir . '/' . $file;
            }
        }
        ksort($sources);
        return $sources;
    }

    private function note(string $kind, string $file): void
    {
        $this->found[$kind][$file] = true;
    }

    private function scanFile(string $label, string $path): void
    {
        $text = (string) @file_get_contents($path);
        $this->files[] = $label;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'htm') {
            $this->note('htm', $label);
        }
        if ($ext === 'js') {
            $this->scripts($label, $text);
            return;
        }
        if ($ext === 'css') {
            if (preg_match(self::V4_PATHS, $text)) {
                $this->note('v4-path', $label);
            }
            return;
        }
        if (preg_match_all('/\bclass\s*=\s*("[^"]*"|\'[^\']*\')/i', $text, $m)) {
            foreach ($m[1] as $value) {
                foreach (preg_split('/\s+/', trim(substr($value, 1, -1))) ?: [] as $class) {
                    if ($class !== '') {
                        $this->note('class:' . $class, $label);
                        if ($class === 'fa' || str_starts_with($class, 'fa-')) {
                            $this->note('font-awesome', $label);
                        }
                    }
                }
            }
        }
        $this->scripts($label, LegacyScripts::scriptText($text));
        if (preg_match('/\bdata-(?:toggle|dismiss|ride|spy)\s*=/i', $text)) {
            $this->note('bootstrap-js', $label);
        }
        if (preg_match('/<calphp\b/i', $text)) {
            $this->note('calphp', $label);
        }
        if (preg_match('/<ace\b/i', $text)) {
            $this->note('ace', $label);
        }
        if (preg_match(self::V4_PATHS, $text)) {
            $this->note('v4-path', $label);
        }
        if (preg_match('/<(?:script|link)\b[^>]*\b(?:src|href)\s*=\s*["\']?(?:https?:)?\/\//i', $text)) {
            $this->note('external', $label);
        }
    }

    private function scripts(string $label, string $js): void
    {
        foreach (LegacyScripts::usedBy($js) as $lib) {
            $this->note('js:' . $lib, $label);
        }
        if (preg_match('/\.\s*(?:modal|collapse|tooltip|popover|tab|carousel|scrollspy|affix|dropdown)\s*\(/', $js)) {
            $this->note('bootstrap-js', $label);
        }
    }
}
