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
 * The page around course content: <head> (theme, MathJax, highlight.js, Ace and the
 * course's own CSS/JS) and the navbar.  Milestone 6 keeps it simple; milestone 7
 * brings the new design.  The links it shows follow v4's rules exactly:
 *
 *  - event menus (menus / instructor_menus) link each event whose title the viewer
 *    may see (the instructor: every event)
 *  - the instructor gets the event's files ("Associated files")
 *  - pages with <student> answers get a show / hide answers link
 */
final class Layout
{
    public const FULL = 'full';     // navbar and theme
    public const PRINT = 'print';   // theme only (?print)
    public const BARE = 'bare';     // the content only (?nocss, .htm files)

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
    ) {
    }

    public function render(string $content, string $mode = self::FULL): string
    {
        if ($mode === self::BARE) {
            return $content;
        }
        $instructor = $this->auth->instructor ? 'yes' : 'no';
        $nav = $mode === self::FULL ? $this->navbar() : '';
        $warnings = '';
        if ($this->auth->instructor && $this->warnings !== []) {
            $warnings = '<div class="cal5-warnings" role="note"><strong>Only you see these warnings:</strong><ul>';
            foreach ($this->warnings as $warning) {
                $warnings .= '<li>' . Html::escape($warning) . '</li>';
            }
            $warnings .= '</ul></div>';
        }
        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n" . $this->head() . "</head>\n"
            . '<body class="cal5" data-cal5-version="' . Html::escape(Version::VERSION) . '" data-instructor="' . $instructor . '">' . "\n"
            . $nav
            . "<main class=\"cal5-page\">\n" . $warnings . $content . "\n</main>\n"
            . $this->scripts()
            . "</body>\n</html>\n";
    }

    private function head(): string
    {
        $a = Html::escape($this->assetBase);
        $title = Html::escape($this->eventName !== '' ? $this->eventName . ' · ' . $this->config->course->title : $this->config->course->title);
        $head = "  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "  <meta name=\"robots\" content=\"noindex\">\n  <title>$title</title>\n"
            . "  <link rel=\"icon\" href=\"{$a}images/web-icon.png\">\n"
            . "  <link rel=\"stylesheet\" href=\"{$a}public/css/cal5.css\">\n"
            . "  <link rel=\"stylesheet\" href=\"{$a}public/vendor/highlight/styles/color-brewer.min.css\">\n"
            . "  <script>window.MathJax = { tex: { inlineMath: [['\\\\(', '\\\\)']], processEscapes: true } };</script>\n"
            . "  <script defer src=\"{$a}public/vendor/mathjax/tex-chtml-full.js\"></script>\n"
            . "  <script defer src=\"{$a}public/vendor/highlight/highlight.min.js\"></script>\n";
        if ($this->usesAce) {
            $head .= "  <script src=\"{$a}public/vendor/ace/ace.js\"></script>\n";
        }
        $style = $this->config->style;
        $css = array_merge($style->css, $this->type !== null ? ($style->cssByType[$this->type] ?? []) : []);
        foreach ($css as $file) {
            $head .= $this->courseAsset($file, '  <link rel="stylesheet" href="%s">');
        }
        $js = array_merge($style->js, $this->type !== null ? ($style->jsByType[$this->type] ?? []) : []);
        foreach ($js as $file) {
            $head .= $this->courseAsset($file, '  <script src="%s"></script>');
        }
        return $head;
    }

    /** A course stylesheet or script (v4 leaves a comment when the file is missing). */
    private function courseAsset(string $file, string $format): string
    {
        $exists = is_file($this->course->directory . '/' . $file);
        $tag = sprintf($format, Html::escape($file));
        return $exists ? $tag . "\n" : '  <!-- missing: ' . Html::escape($file) . " -->\n";
    }

    private function scripts(): string
    {
        return "<script>document.addEventListener('DOMContentLoaded', function () { if (window.hljs) { hljs.highlightAll(); } });</script>\n";
    }

    private function navbar(): string
    {
        $e = $this->entry;
        $course = Html::escape(trim($this->config->course->number . ' - ' . $this->config->course->name, ' -'));
        $calendarView = ['month' => 'calendar_display', 'list' => 'calendar_display_alt', 'agenda' => 'calendar_display_dateless'][$this->config->display->calendarView];
        $html = "<header class=\"cal5-nav\">\n  <nav aria-label=\"Course\">\n"
            . "    <a class=\"cal5-brand\" href=\"$e?load=home\">$course</a>\n";
        $hideMenus = $this->locked && !$this->auth->instructor;
        if (!$hideMenus) {
            $html .= "    <a href=\"$e?show=$calendarView\">Calendar</a>\n"
                . "    <a href=\"$e?load=resources\">Resources</a>\n"
                . "    <a href=\"$e?load=policy\">Information</a>\n";
            $html .= $this->eventMenus();
        }
        $html .= $this->pageMenu();
        if ($this->hasAnswers && $this->type !== null && $this->event !== null) {
            $link = "$e?type=" . rawurlencode($this->type) . '&event=' . $this->event
                . ($this->key !== null ? '&key=' . $this->key : '');
            $html .= $this->answersShown
                ? '    <a class="cal5-answers" href="' . Html::escape($link) . "\">Hide answers</a>\n"
                : '    <a class="cal5-answers" href="' . Html::escape($link . '&answers=yes') . "\">Show answers</a>\n";
        }
        if ($this->auth->instructor && $this->files !== [] && !$hideMenus) {
            $html .= "    <details class=\"cal5-menu\"><summary>Files</summary><ul>\n";
            foreach ($this->files as $name => $file) {
                $link = "$e?key=" . $file->key . '&type=' . rawurlencode((string) $this->type) . '&event=' . $this->event;
                $html .= '      <li><a href="' . Html::escape($link) . '">' . Html::escape($name) . "</a></li>\n";
            }
            $html .= "    </ul></details>\n";
        }
        $html .= $this->login();
        return $html . "  </nav>\n</header>\n";
    }

    private function eventMenus(): string
    {
        $menus = $this->auth->instructor && $this->config->instructorMenus !== null ? $this->config->instructorMenus : $this->config->menus;
        $html = '';
        foreach ($menus as $menu) {
            $items = '';
            foreach ($this->course->content->events($menu->type) as $folder) {
                $label = $folder->number . ' - ' . $folder->name;
                $title = $folder->boxFile('title');
                $visible = $this->auth->instructor
                    || ($title !== null && $this->policy->visibleToStudents($folder, $title));
                $current = $this->type === $menu->type && $this->event === $folder->number ? ' aria-current="page"' : '';
                $items .= $visible
                    ? '      <li><a href="' . Html::escape($this->entry . '?type=' . rawurlencode($menu->type) . '&event=' . $folder->number) . "\"$current>" . Html::escape($label) . "</a></li>\n"
                    : '      <li><span class="cal5-unavailable" title="Material not online at this time">' . Html::escape($label) . "</span></li>\n";
            }
            $html .= '    <details class="cal5-menu"><summary>' . Html::escape($menu->label) . "</summary><ul>\n" . $items . "    </ul></details>\n";
        }
        return $html;
    }

    private function pageMenu(): string
    {
        $menu = $this->headings?->menu() ?? [];
        if ($menu === []) {
            return '';
        }
        $html = '    <details class="cal5-menu"><summary>' . Html::escape($this->eventName !== '' ? $this->eventName : 'On this page') . "</summary><ul>\n";
        foreach ($menu as $anchor => $label) {
            $html .= '      <li><a href="#' . Html::escape($anchor) . '">' . Html::escape($label) . "</a></li>\n";
        }
        return $html . "    </ul></details>\n";
    }

    private function login(): string
    {
        if (!$this->config->security->loginEnabled()) {
            return '';
        }
        $csrf = Html::escape($this->csrf);
        if ($this->auth->instructor) {
            return "    <form method=\"post\" class=\"cal5-login\"><input type=\"hidden\" name=\"password\" value=\"\">"
                . "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><button type=\"submit\">Log out</button></form>\n";
        }
        $nonce = Html::escape((string) $this->auth->nonce);
        return "    <form method=\"post\" class=\"cal5-login\" data-nonce=\"$nonce\"><label class=\"cal5-visually-hidden\" for=\"cal5-password\">Instructor password</label>"
            . "<input id=\"cal5-password\" type=\"password\" name=\"password\" placeholder=\"Instructor\" autocomplete=\"current-password\">"
            . "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><button type=\"submit\">Log in</button></form>\n";
    }
}
