<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\AccessPolicy;
use Cal5\Auth\AuthResult;
use Cal5\Auth\SessionStore;
use Cal5\Content\ContentFile;
use Cal5\Content\EventFolder;
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
 * An event page (?type=T&event=N) or one of its files (&key=K), chosen exactly as
 * v4 does it:
 *
 *  - a key of a file of this event the viewer may see: that file
 *  - a key that names no file of the course at all: the event's title file
 *  - a key of some other (or hidden) file: nothing
 *  - no key: the title file, for students only while it is visible
 *  - students never get the source of PHP files
 *  - pages (.html, .htm, .md, .txt) are rendered; every other file is sent as is
 */
final class EventPage
{
    /** Extensions of server-side scripts: their source is never sent to students (calendar-core 4.6). */
    private const SCRIPTS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar'];

    /** @param array<string, mixed> $phpVariables variables for <calphp> scripts (v4: the course's settings) */
    public function __construct(
        private readonly Course $course,
        private readonly AccessPolicy $policy,
        private readonly AuthResult $auth,
        private readonly Request $request,
        private readonly SessionStore $session,
        private readonly string $csrf,
        private readonly string $entry,
        private readonly string $assetBase,
        private readonly array $phpVariables = [],
        /** @var list<string> configuration warnings, shown to the instructor */
        private readonly array $configWarnings = [],
        /** Added after the content (the debug view) */
        private readonly string $extra = '',
        /** The instructor's way back from ?as=student */
        private readonly ?string $preview = null,
    ) {
    }

    /** Sends the response (a page, or a file). */
    public function handle(PageRequest $page): void
    {
        $type = (string) $page->type;
        $number = (int) $page->event;
        $folder = $this->course->content->event($type, $number);
        $files = $folder === null ? [] : $this->visibleFiles($folder);
        $file = $folder === null ? null : $this->select($folder, $files, $page->key);
        $navbarOnly = $this->request->has('navbaronly');

        if ($file !== null && !$file->kind->isPage() && !$navbarOnly) {
            Download::send($file->actualPath, $file->name, $this->request->header('Range'), $this->request->method() === 'HEAD');
            return;
        }
        echo $this->render($type, $number, $folder, $files, $file, $page->key, $navbarOnly);
    }

    /**
     * The event's files the viewer may see (v4 $other).
     *
     * @return array<string, ContentFile>
     */
    public function visibleFiles(EventFolder $folder): array
    {
        if ($this->auth->instructor) {
            return $folder->files;
        }
        $visible = [];
        foreach ($folder->files as $name => $file) {
            if ($this->policy->visibleToStudents($folder, $file)) {
                $visible[$name] = $file;
            }
        }
        return $visible;
    }

    /** @param array<string, ContentFile> $files */
    public function select(EventFolder $folder, array $files, ?string $key): ?ContentFile
    {
        if ($key !== null && $this->courseHasKey($key)) {
            $file = null;
            foreach ($files as $candidate) {
                if ($candidate->key === $key) {
                    $file = $candidate;
                }
            }
        } else {
            $title = $folder->boxFile('title');
            $file = $title !== null && isset($files[$title->name]) ? $title : null;
        }
        if ($file !== null && !$this->auth->instructor && self::isScript($file->actualPath)) {
            return null;
        }
        return $file;
    }

    public static function isScript(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SCRIPTS, true);
    }

    private function courseHasKey(string $key): bool
    {
        foreach ($this->course->content->types as $events) {
            foreach ($events as $event) {
                if ($event->fileByKey($key) !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string, ContentFile> $files */
    private function render(string $type, int $number, ?EventFolder $folder, array $files, ?ContentFile $file, ?string $key, bool $navbarOnly): string
    {
        $config = $this->course->config;
        $instructor = $this->auth->instructor;
        $eventName = $folder?->name ?? '';
        $html = $file === null ? '' : self::source($file, $config->display->markdownMenuLevels);

        $page = new PageContext(
            config: $config,
            instructor: $instructor,
            request: $this->request,
            session: $this->session,
            files: $files,
            type: $type,
            event: $number,
            replace: $this->replaceValues($type, $number, $eventName),
            eventContext: $folder === null ? null : $this->policy->context($folder),
            now: $this->policy->now(),
            entry: $this->entry,
            phpVariables: $this->phpVariables,
        );
        $pipeline = new TagPipeline($this->request->server('SERVER_NAME'), $this->request->server('SERVER_ADDR'));
        $rendered = $pipeline->process($html, $page);

        $warnings = $instructor ? $this->configWarnings : [];
        if ($instructor) {
            if ($config->display->headingWarnings && $rendered->headings->oldAnchors !== []) {
                $names = array_map(static fn (array $a): string => $a['tag'] . ' name="' . $a['name'] . '"', $rendered->headings->oldAnchors);
                $warnings[] = 'This page uses old style <a name> anchors (use id on a heading instead): ' . implode(', ', $names);
            }
            foreach ($this->policy->problems() as $problem) {
                $warnings[] = (string) $problem;
            }
        }

        $mode = Layout::FULL;
        if ($this->request->has('nocss') || $file?->kind === FileKind::Htm) {
            $mode = Layout::BARE;
        } elseif ($this->request->has('print')) {
            $mode = Layout::PRINT;
        }
        $layout = new Layout(
            config: $config,
            course: $this->course,
            policy: $this->policy,
            auth: $this->auth,
            csrf: $this->csrf,
            entry: $this->entry,
            assetBase: $this->assetBase,
            type: $type,
            event: $number,
            key: $key,
            eventName: $eventName,
            files: $instructor ? $files : [],
            hasAnswers: $rendered->hasAnswers,
            usesAce: $rendered->usesAce,
            headings: $rendered->headings,
            warnings: $warnings,
            answersShown: $this->request->has('answers'),
            preview: $this->preview,
        );
        return $layout->render(($navbarOnly ? '' : $rendered->html) . $this->extra, $mode);
    }

    /**
     * The file's text, ready for the tag pipeline: Markdown converted (in v4's
     * <div class="cal4-markdown">), plain text escaped.
     *
     * @param list<int> $menuLevels
     */
    public static function source(ContentFile $file, array $menuLevels): string
    {
        $text = (string) @file_get_contents($file->actualPath);
        return match ($file->kind) {
            FileKind::Markdown => '<div class="cal4-markdown">' . PHP_EOL . Markdown::convert($text, $menuLevels) . PHP_EOL . '</div>',
            FileKind::Text => '<pre class="cal5-text">' . Html::escape($text) . '</pre>',
            default => $text,
        };
    }

    /**
     * Values for <replace value="...">: the course's own (v4 $PAGE_MODIFY), then
     * v4's built-in ones where the course did not set them.
     *
     * @return array<string, string>
     */
    private function replaceValues(string $type, int $number, string $eventName): array
    {
        $config = $this->course->config;
        return $config->replace + [
            'event' => (string) $number,
            'zevent' => str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'type' => $type,
            'ctype' => ucfirst($type),
            'course' => $config->course->number,
            'coursename' => $config->course->name,
            'coursenamefull' => $config->course->fullName,
            'title' => $eventName,
            'access_file' => (string) $config->files->access,
            'class_file' => (string) $config->files->virtual,
        ];
    }
}
