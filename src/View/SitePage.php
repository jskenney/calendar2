<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\AccessPolicy;
use Cal5\Auth\AuthResult;
use Cal5\Auth\SessionStore;
use Cal5\Content\FileKind;
use Cal5\Course;
use Cal5\Http\Download;
use Cal5\Http\PageRequest;
use Cal5\Http\Request;
use Cal5\Render\Markdown;
use Cal5\Render\PageContext;
use Cal5\Render\TagPipeline;
use Cal5\Support\Html;

/**
 * Every page that is not an event page (docs/design/routing.md), in v4's order:
 *
 *  - ?show=VIEW output first: the calendar views, search, keyword search, the
 *    instructor's status page (file selector) and the note that the editor is gone
 *  - then the page content: ?load=NAME (allow-listed .pdf / .html / .md), or the start
 *    page when nothing else was asked for
 *
 * The page content goes through the same tag pipeline as event pages (<replace>,
 * <inst>, <student>, <lock>, ...), as in v4.
 */
final class SitePage
{
    /** Pages Cal5 itself provides (v4 looked in calendar/ first, so these win over a course page). */
    private const LIBRARY_PAGES = ['error', 'instructor'];

    /**
     * @param array<string, mixed> $phpVariables
     * @param list<string> $warnings shown to the instructor
     * @param array<string, string> $replace extra <replace> values (the error page's)
     */
    public function __construct(
        private readonly Course $course,
        private readonly AccessPolicy $policy,
        private readonly AuthResult $auth,
        private readonly Request $request,
        private readonly SessionStore $session,
        private readonly string $csrf,
        private readonly string $entry,
        private readonly string $assetBase,
        private readonly string $libraryDir,
        private readonly bool $locked = false,
        private readonly array $warnings = [],
        private readonly string $extra = '',
        private readonly array $replace = [],
        private readonly ?string $preview = null,
    ) {
    }

    /** Pages ?load= may show to this viewer (v4: the course's list, plus error, lock, and the guide for the instructor). @return list<string> */
    public function loadable(): array
    {
        $pages = array_merge($this->course->config->files->loadPages, ['error', 'lock']);
        if ($this->auth->instructor) {
            $pages[] = 'instructor';
        }
        return array_values(array_unique($pages));
    }

    /**
     * @param string|null $load the page to show (?load=, or "home" / "error" / "lock" chosen by the router)
     * @param string|null $show the view to show (?show=)
     */
    public function handle(PageRequest $page, ?string $load, ?string $show): void
    {
        $instructor = $this->auth->instructor;
        $folder = $page->isEvent() ? $this->course->content->event((string) $page->type, (int) $page->event) : null;

        // the view (?show=), output before the page content as in v4
        $view = '';
        $kind = Layout::PAGE_OTHER;
        $title = '';
        $calendarView = $show !== null ? (CalendarView::VIEWS[$show] ?? null) : null;
        $model = new CalendarModel($this->course, $this->policy, $instructor);
        $calendar = new CalendarView($model, $this->entry);
        if ($calendarView !== null) {
            $view = $calendar->render($calendarView);
            $kind = Layout::PAGE_CALENDAR;
            $title = 'Calendar';
        } elseif ($show === 'calendar_search') {
            $view = (new SearchView($model, $calendar, $this->entry))->search($this->request->param('search'));
            $title = 'Search';
        } elseif ($show === 'calendar_keyword') {
            $view = (new SearchView($model, $calendar, $this->entry))->keyword($this->request->param('keyword'));
            $title = 'Keywords';
        } elseif ($show === 'calendar_file_selector' && $instructor) {
            $view = (new StatusView($this->course, $this->policy, $this->entry))->render($folder);
            $title = 'Status';
            $kind = $folder !== null ? Layout::PAGE_EVENT : Layout::PAGE_OTHER;
        } elseif ($show === 'calendar_file_editor' && $instructor) {
            $view = '<div class="cal5-notice" role="note">' . Icons::svg('info') . '<div><strong>The file editor has been removed.</strong> '
                . 'Edit course files with your usual editor; the status page (the day numbers in the month view) shows every file of an event.</div></div>';
        }

        // the page content (?load=)
        $content = '';
        $headingsSource = '';
        if ($load !== null) {
            $file = $this->loadFile($load);
            if ($file !== null && str_ends_with($file, '.pdf')) {
                Download::send($file, basename($file), $this->request->header('Range'), $this->request->method() === 'HEAD');
                return;
            }
            if ($file !== null) {
                $text = (string) @file_get_contents($file);
                $content = str_ends_with($file, '.md')
                    ? '<div class="cal4-markdown">' . PHP_EOL . Markdown::convert($text, $this->course->config->display->markdownMenuLevels) . PHP_EOL . '</div>'
                    : $text;
            } elseif ($load === 'lock') {
                $content = '<div class="cal5-lockpage"><h1>' . Icons::svg('lock') . ' This course site is locked.</h1>'
                    . '<p>The instructor has locked the site for now. Please check back later.</p></div>';
            }
            if ($title === '' && $load !== 'home' && ($file !== null || $load === 'lock')) {
                $title = $load === 'instructor' ? 'Instructor guide' : ucfirst(str_replace(['-', '_'], ' ', $load));
            }
            if ($view === '' && $kind === Layout::PAGE_OTHER) {
                $kind = Layout::PAGE_CONTENT;
            }
        }

        $context = new PageContext(
            config: $this->course->config,
            instructor: $instructor,
            request: $this->request,
            session: $this->session,
            files: $folder !== null ? $this->eventFiles($folder) : [],
            type: $page->type,
            event: $page->event,
            replace: $this->replace + $this->course->config->replace + self::defaults($this->course, $page, $folder?->name ?? ''),
            eventContext: $folder !== null ? $this->policy->context($folder) : null,
            now: $this->policy->now(),
            entry: $this->entry,
        );
        $rendered = (new TagPipeline($this->request->server('SERVER_NAME'), $this->request->server('SERVER_ADDR')))->process($content, $context);
        $navbarOnly = $this->request->has('navbaronly');

        $warnings = $instructor ? $this->warnings : [];
        if ($instructor) {
            foreach ($this->policy->problems() as $problem) {
                $warnings[] = (string) $problem;
            }
        }
        $layout = new Layout(
            config: $this->course->config,
            course: $this->course,
            policy: $this->policy,
            auth: $this->auth,
            csrf: $this->csrf,
            entry: $this->entry,
            assetBase: $this->assetBase,
            locked: $this->locked,
            type: $folder !== null ? $folder->type : null,
            event: $folder?->number,
            eventName: $folder?->name ?? '',
            files: $instructor && $folder !== null ? $folder->files : [],
            hasAnswers: $rendered->hasAnswers,
            usesAce: $rendered->usesAce,
            headings: $rendered->headings,
            warnings: $warnings,
            answersShown: $this->request->has('answers'),
            page: $kind,
            title: $title,
            preview: $this->preview,
        );
        $mode = $this->request->has('nocss') ? Layout::BARE : ($this->request->has('print') ? Layout::PRINT : Layout::FULL);
        echo $layout->render($view . ($navbarOnly ? '' : $rendered->html) . $this->extra, $mode);
    }

    /** The file ?load=NAME shows, or null (not allowed, or no such page). */
    public function loadFile(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name) || !in_array($name, $this->loadable(), true)) {
            return null;
        }
        $dir = $this->course->directory;
        if (is_file("$dir/$name.pdf")) {
            return "$dir/$name.pdf";
        }
        if (in_array($name, self::LIBRARY_PAGES, true)) {
            foreach (['html', 'md'] as $ext) {
                if (is_file("{$this->libraryDir}/pages/$name.$ext")) {
                    return "{$this->libraryDir}/pages/$name.$ext";
                }
            }
        }
        foreach (['html', 'md'] as $ext) {
            if (is_file("$dir/$name.$ext")) {
                return "$dir/$name.$ext";
            }
        }
        return null;
    }

    /** @return array<string, \Cal5\Content\ContentFile> */
    private function eventFiles(\Cal5\Content\EventFolder $folder): array
    {
        if ($this->auth->instructor) {
            return $folder->files;
        }
        return array_filter($folder->files, fn ($file): bool => $this->policy->visibleToStudents($folder, $file));
    }

    /**
     * v4's built-in <replace> values (only the ones that exist for this request).
     *
     * @return array<string, string>
     */
    public static function defaults(Course $course, PageRequest $page, string $eventName): array
    {
        $config = $course->config;
        $values = [];
        if ($page->event !== null) {
            $values['event'] = (string) $page->event;
            $values['zevent'] = str_pad((string) $page->event, 2, '0', STR_PAD_LEFT);
        }
        if ($page->type !== null) {
            $values['type'] = $page->type;
            $values['ctype'] = ucfirst($page->type);
        }
        return $values + [
            'course' => $config->course->number,
            'coursename' => $config->course->name,
            'coursenamefull' => $config->course->fullName,
            'title' => $eventName,
            'access_file' => (string) $config->files->access,
            'class_file' => (string) $config->files->virtual,
        ];
    }

    /** The event v4's "today's lecture" start page shows, or null (display.start_page = today). */
    public function todaysEvent(): ?\Cal5\Content\EventFolder
    {
        $today = $this->policy->now()->format('Y-m-d');
        $entry = $this->course->schedule->day($today)?->entries[0] ?? null;
        if ($entry === null || $entry->number === null) {
            return null;
        }
        $folder = $this->course->content->event($entry->type, $entry->number);
        $title = $folder?->boxFile('title');
        if ($folder === null || $title === null) {
            return null;
        }
        if (!$this->auth->instructor && !$this->policy->visibleToStudents($folder, $title)) {
            return null;
        }
        return in_array($title->kind, [FileKind::Html, FileKind::Htm, FileKind::Markdown], true) ? $folder : null;
    }
}
