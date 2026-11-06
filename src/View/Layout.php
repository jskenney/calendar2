<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\AccessPolicy;
use Cal5\Auth\AuthResult;
use Cal5\Config\Config;
use Cal5\Content\ContentFile;
use Cal5\Course;
use Cal5\Render\HeadingIndex;
use Cal5\Support\Html;
use Cal5\Version;

/**
 * The page around course content (docs/design/ui.md):
 *
 *  - <head>: theme, MathJax on every page, highlight.js, Ace only when the page has an
 *    <ace>, the course's own CSS/JS
 *  - header: course title, Calendar / Resources / Information, one menu per event
 *    type, search, and the instructor login
 *  - on event pages, a page bar: the event's sections, the instructor's files, the
 *    show/hide answers link and print
 *
 * The links follow v4's rules exactly: an event menu links each event whose title
 * the viewer may see (the instructor: every event); a locked site shows students
 * no menus.  Menus are <details> elements, so they work without JavaScript;
 * public/js/cal5.js only adds conveniences (closing menus, hashing the password).
 */
final class Layout
{
    public const FULL = 'full';     // header and theme
    public const PRINT = 'print';   // theme only (?print)
    public const BARE = 'bare';     // the content only (?nocss, .htm files)

    /** The page being shown, for the header's "current page" marks. */
    public const PAGE_EVENT = 'event';
    public const PAGE_CALENDAR = 'calendar';
    public const PAGE_OTHER = 'other';

    /**
     * @param array<string, ContentFile> $files the event's files the viewer may see
     * @param list<string> $warnings shown to the instructor
     */
    public function __construct(
        private readonly Config $config,
        private readonly Course $course,
        private readonly AccessPolicy $policy,
        private readonly AuthResult $auth,
        private readonly string $csrf,
        private readonly string $entry,
        private readonly string $assetBase,
        private readonly bool $locked = false,
        private readonly ?string $type = null,
        private readonly ?int $event = null,
        private readonly ?string $key = null,
        private readonly string $eventName = '',
        private readonly array $files = [],
        private readonly bool $hasAnswers = false,
        private readonly bool $usesAce = false,
        private readonly ?HeadingIndex $headings = null,
        private readonly array $warnings = [],
        private readonly bool $answersShown = false,
        private readonly string $page = self::PAGE_EVENT,
        private readonly string $title = '',
    ) {
    }

    public function render(string $content, string $mode = self::FULL): string
    {
        if ($mode === self::BARE) {
            return $content;
        }
        $instructor = $this->auth->instructor ? 'yes' : 'no';
        $header = $mode === self::FULL ? $this->header() : '';
        $warnings = '';
        if ($this->auth->instructor && $this->warnings !== []) {
            $warnings = '<div class="cal5-warnings" role="note">' . Icons::svg('circle-alert')
                . '<div><strong>Only you see these warnings</strong><ul>';
            foreach ($this->warnings as $warning) {
                $warnings .= '<li>' . Html::escape($warning) . '</li>';
            }
            $warnings .= "</ul></div></div>\n";
        }
        $pageClass = 'cal5-page cal5-page-' . $this->page . ($mode === self::PRINT ? ' cal5-print' : '');
        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n" . $this->head() . "</head>\n"
            . '<body class="cal5" data-cal5-version="' . Html::escape(Version::VERSION) . '" data-instructor="' . $instructor . '">' . "\n"
            . ($mode === self::FULL ? "<a class=\"cal5-skip\" href=\"#cal5-main\">Skip to content</a>\n" : '')
            . $header
            . '<main id="cal5-main" class="' . $pageClass . "\" tabindex=\"-1\">\n" . $warnings . $content . "\n</main>\n"
            . ($mode === self::FULL ? $this->footer() : '')
            . "</body>\n</html>\n";
    }

    private function head(): string
    {
        $a = Html::escape($this->assetBase);
        $name = $this->title !== '' ? $this->title : $this->eventName;
        $title = Html::escape($name !== '' ? $name . ' · ' . $this->config->course->title : $this->config->course->title);
        $head = "  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "  <meta name=\"robots\" content=\"noindex\">\n  <meta name=\"color-scheme\" content=\"light dark\">\n  <title>$title</title>\n"
            . "  <link rel=\"icon\" href=\"{$a}images/web-icon.png\">\n"
            . "  <link rel=\"stylesheet\" href=\"{$a}public/css/cal5.css\">\n"
            . "  <link rel=\"stylesheet\" href=\"{$a}public/vendor/highlight/styles/color-brewer.min.css\" media=\"(prefers-color-scheme: light)\">\n"
            . "  <link rel=\"stylesheet\" href=\"{$a}public/vendor/highlight/styles/github-dark.min.css\" media=\"(prefers-color-scheme: dark)\">\n"
            . '  <script>' . self::mathJaxConfig($this->assetBase) . "</script>\n"
            . "  <script defer src=\"{$a}public/vendor/mathjax/tex-chtml.js\"></script>\n"
            . "  <script defer src=\"{$a}public/vendor/highlight/highlight.min.js\"></script>\n"
            . "  <script defer src=\"{$a}public/js/cal5.js\"></script>\n";
        if ($this->usesAce) {
            $head .= "  <script src=\"{$a}public/vendor/ace/ace.js\"></script>\n";
        }
        $style = $this->config->style;
        $css = array_merge($style->css, $this->type !== null ? ($style->cssByType[$this->type] ?? []) : [],
            $this->page === self::PAGE_CALENDAR ? $style->cssCalendar : []);
        foreach ($css as $file) {
            $head .= $this->courseAsset($file, '  <link rel="stylesheet" href="%s">');
        }
        $js = array_merge($style->js, $this->type !== null ? ($style->jsByType[$this->type] ?? []) : []);
        foreach ($js as $file) {
            $head .= $this->courseAsset($file, '  <script src="%s"></script>');
        }
        return $head;
    }

    /**
     * TeX extensions loaded on every page, on top of MathJax 4's defaults (base, ams,
     * newcommand, textmacros, noundefined, require, autoload, configmacros): the rest of
     * what v3's tex-chtml-full.js had, which v4 courses were written against, so macros
     * such as \\coloneqq (mathtools) or \\dv (physics) keep working.  Not loaded:
     * texhtml (raw HTML inside TeX), colorv2 and fontsizev3 (old variants), and the
     * alternative blackboard fonts.
     */
    public const MATHJAX_PACKAGES = ['action', 'amscd', 'bbox', 'boldsymbol', 'braket', 'bussproofs', 'cancel', 'cases',
        'centernot', 'color', 'colortbl', 'empheq', 'enclose', 'extpfeil', 'gensymb', 'html', 'mathtools',
        'mhchem', 'physics', 'setoptions', 'tagformat', 'textcomp', 'unicode', 'upgreek', 'verb'];

    /**
     * MathJax 4 settings: \\( \\) for inline math (as in v4; $ is left alone), every
     * file served from calendar/public/vendor (fonts included, nothing from a CDN).
     */
    public static function mathJaxConfig(string $assetBase): string
    {
        $config = [
            'loader' => [
                'paths' => ['fonts' => $assetBase . 'public/vendor'],
                'load' => array_map(static fn (string $p): string => '[tex]/' . $p, self::MATHJAX_PACKAGES),
            ],
            'tex' => [
                'inlineMath' => [['\\(', '\\)']],
                'processEscapes' => true,
                'packages' => ['[+]' => self::MATHJAX_PACKAGES],
            ],
            'output' => ['fontPath' => $assetBase . 'public/vendor/%%FONT%%-font'],
        ];
        return 'window.MathJax = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) . ';';
    }

    /** A course stylesheet or script (v4 leaves a comment when the file is missing). */
    private function courseAsset(string $file, string $format): string
    {
        $exists = is_file($this->course->directory . '/' . $file);
        $tag = sprintf($format, Html::escape($file));
        return $exists ? $tag . "\n" : '  <!-- missing: ' . Html::escape($file) . " -->\n";
    }

    /** The calendar view the "Calendar" link opens (display.calendar_view, v4 $CALENDAR_TOOL). */
    public function calendarShow(): string
    {
        return (string) array_search($this->config->display->calendarView, CalendarView::VIEWS, true);
    }

    private function link(string $query): string
    {
        return Html::escape($this->entry . '?' . $query);
    }

    private function header(): string
    {
        $course = $this->config->course;
        $locked = $this->locked && !$this->auth->instructor;
        $a = Html::escape($this->assetBase);
        $html = "<header class=\"cal5-header\">\n<div class=\"cal5-bar\">\n"
            . '  <a class="cal5-brand" href="' . $this->link('load=home') . '"><img src="' . $a . 'images/web-icon.png" alt="" width="28" height="28">'
            . '<span class="cal5-brand-number">' . Html::escape($course->number) . '</span>'
            . ($course->name !== '' ? '<span class="cal5-brand-name">' . Html::escape($course->name) . '</span>' : '') . "</a>\n";
        if (!$locked) {
            $current = fn (string $page): string => $this->page === $page ? ' aria-current="page"' : '';
            $html .= "  <nav class=\"cal5-primary\" aria-label=\"Course\">\n"
                . '    <a href="' . $this->link('show=' . $this->calendarShow()) . '"' . $current(self::PAGE_CALENDAR) . '>' . Icons::svg('calendar') . "<span>Calendar</span></a>\n"
                . '    <a href="' . $this->link('load=resources') . '" title="Resources">' . Icons::svg('briefcase') . "<span>Resources</span></a>\n"
                . '    <a href="' . $this->link('load=policy') . '" title="Information">' . Icons::svg('info') . "<span>Information</span></a>\n";
            if ($this->auth->instructor) {
                $html .= '    <a href="' . $this->link('load=instructor') . '" title="Instructor guide">' . Icons::svg('graduation-cap') . "<span>Guide</span></a>\n";
            }
            $html .= $this->eventMenus() . "  </nav>\n";
        }
        $html .= "  <div class=\"cal5-tools\">\n";
        if ($this->locked) {
            $html .= '    <span class="cal5-locked-badge" title="The site is locked: students only see the lock page">' . Icons::svg('lock') . "<span>Locked</span></span>\n";
        }
        if (!$locked) {
            $html .= '    <form class="cal5-search" role="search" method="get" action="' . Html::escape($this->entry) . '">'
                . '<input type="hidden" name="show" value="calendar_search">'
                . '<label class="cal5-visually-hidden" for="cal5-search">Search the course</label>'
                . '<input id="cal5-search" type="search" name="search" placeholder="Search" autocomplete="off">'
                . '<button type="submit" title="Search">' . Icons::svg('search') . "<span class=\"cal5-visually-hidden\">Search</span></button></form>\n";
        }
        $html .= $this->login() . "  </div>\n</div>\n";
        if ($this->page === self::PAGE_EVENT && !$locked) {
            $html .= $this->pageBar();
        }
        return $html . "</header>\n";
    }

    private function eventMenus(): string
    {
        $menus = $this->auth->instructor && $this->config->instructorMenus !== null ? $this->config->instructorMenus : $this->config->menus;
        $html = '';
        foreach ($menus as $menu) {
            $items = '';
            foreach ($this->course->content->events($menu->type) as $folder) {
                $title = $folder->boxFile('title');
                $visible = $this->auth->instructor
                    || ($title !== null && $this->policy->visibleToStudents($folder, $title));
                $date = $this->course->schedule->dateOf($folder->type, $folder->number);
                $when = $date === null ? '' : '<span class="cal5-menu-date">' . (new \DateTimeImmutable($date))->format('M j') . '</span>';
                $label = '<span class="cal5-menu-num">' . $folder->number . '</span><span class="cal5-menu-name">' . Html::escape($folder->name) . '</span>' . $when;
                $current = $this->type === $menu->type && $this->event === $folder->number ? ' aria-current="page"' : '';
                $items .= $visible
                    ? '      <li><a href="' . $this->link('type=' . rawurlencode($menu->type) . '&event=' . $folder->number) . "\"$current>" . $label . "</a></li>\n"
                    : '      <li><span class="cal5-unavailable" title="Material not online at this time">' . $label . "</span></li>\n";
            }
            if ($items === '') {
                $items = "      <li><span class=\"cal5-unavailable\">Nothing yet</span></li>\n";
            }
            $active = $this->type === $menu->type ? ' cal5-active' : '';
            $html .= '    <details class="cal5-menu' . $active . '"><summary>' . Icons::svg($menu->icon !== '' ? $menu->icon : 'circle-dot')
                . '<span>' . Html::escape($menu->label) . '</span>' . Icons::svg('chevron-down', 'cal5-caret') . "</summary>\n"
                . '      <ul class="cal5-menu-list">' . "\n" . $items . "      </ul></details>\n";
        }
        return $html;
    }

    /** The event's bar: title, sections, the instructor's files, answers, print. */
    private function pageBar(): string
    {
        if ($this->type === null || $this->event === null) {
            return '';
        }
        $html = "<div class=\"cal5-pagebar\">\n  <div class=\"cal5-pagebar-inner\">\n"
            . '    <span class="cal5-pagebar-title"><span class="cal5-tag">' . Html::escape(ucwords(str_replace('-', ' ', $this->type)) . ' ' . $this->event) . '</span>'
            . Html::escape($this->eventName) . "</span>\n";
        $menu = $this->headings?->menu() ?? [];
        if ($menu !== []) {
            $html .= '    <details class="cal5-menu"><summary>' . Icons::svg('list') . '<span>Sections</span>' . Icons::svg('chevron-down', 'cal5-caret') . "</summary>\n      <ul class=\"cal5-menu-list\">\n";
            foreach ($menu as $anchor => $label) {
                $html .= '        <li><a href="#' . Html::escape($anchor) . '">' . Html::escape($label) . "</a></li>\n";
            }
            $html .= "      </ul></details>\n";
        }
        if ($this->auth->instructor && $this->files !== []) {
            $html .= '    <details class="cal5-menu"><summary>' . Icons::svg('folder-open') . '<span>Files</span>' . Icons::svg('chevron-down', 'cal5-caret') . "</summary>\n      <ul class=\"cal5-menu-list cal5-files\">\n";
            foreach ($this->files as $name => $file) {
                $state = $this->fileState($file);
                $html .= '        <li><a href="' . $this->link('key=' . $file->key . '&type=' . rawurlencode($this->type) . '&event=' . $this->event) . '">'
                    . Html::escape($name) . $state . "</a></li>\n";
            }
            $html .= "      </ul></details>\n";
        }
        if ($this->hasAnswers) {
            $link = 'type=' . rawurlencode($this->type) . '&event=' . $this->event . ($this->key !== null ? '&key=' . $this->key : '');
            $html .= $this->answersShown
                ? '    <a class="cal5-button" href="' . $this->link($link) . '" aria-pressed="true">' . Icons::svg('eye-off') . "<span>Hide answers</span></a>\n"
                : '    <a class="cal5-button" href="' . $this->link($link . '&answers=yes') . '" aria-pressed="false">' . Icons::svg('eye') . "<span>Show answers</span></a>\n";
        }
        // v4's print button: the same page again with "print", in a new tab
        $html .= '    <form method="post" target="_blank" class="cal5-print-form"><input type="hidden" name="print" value="1">'
            . '<button type="submit" class="cal5-button">' . Icons::svg('printer') . "<span>Print</span></button></form>\n";
        return $html . "  </div>\n</div>\n";
    }

    /** For the instructor's file list: why students can not see a file now. */
    private function fileState(ContentFile $file): string
    {
        if ($this->type === null || $this->event === null) {
            return '';
        }
        $folder = $this->course->content->event($this->type, $this->event);
        if ($folder === null || $this->policy->visibleToStudents($folder, $file)) {
            return '';
        }
        return ' <span class="cal5-badge cal5-badge-closed" title="' . Html::escape($this->policy->window($folder, $file)->describe()) . '">hidden</span>';
    }

    private function login(): string
    {
        if (!$this->config->security->loginEnabled()) {
            return '';
        }
        $csrf = Html::escape($this->csrf);
        if ($this->auth->instructor) {
            return '    <form method="post" class="cal5-logout"><input type="hidden" name="password" value="">'
                . "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><button type=\"submit\" class=\"cal5-button\">" . Icons::svg('log-out') . "<span>Log out</span></button></form>\n";
        }
        $nonce = Html::escape((string) $this->auth->nonce);
        // plain-text passwords (v4): the browser sends sha256(password . nonce), as v4's form did
        $hash = $this->config->security->passwordHash === null ? ' data-hash="sha256"' : '';
        return '    <details class="cal5-menu cal5-login-menu"><summary title="Instructor login">' . Icons::svg('log-in') . "<span class=\"cal5-visually-hidden\">Instructor login</span></summary>\n"
            . "      <form method=\"post\" class=\"cal5-login\" data-nonce=\"$nonce\"$hash>"
            . '<label for="cal5-password">Instructor password</label>'
            . '<input id="cal5-password" type="password" name="password" autocomplete="current-password" required>'
            . "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><button type=\"submit\" class=\"cal5-button cal5-primary-button\">Log in</button></form></details>\n";
    }

    private function footer(): string
    {
        $course = $this->config->course;
        $name = $course->fullName !== '' ? $course->fullName : trim($course->number . ' ' . $course->name);
        $html = '<footer class="cal5-footer"><span>' . Html::escape($name) . '</span>';
        if ($this->auth->instructor) {
            $html .= '<span>Cal5 ' . Html::escape(Version::VERSION) . '</span>';
        }
        return $html . "</footer>\n";
    }
}
