<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\AccessPolicy;
use Cal5\Content\EventFolder;
use Cal5\Course;
use Cal5\Support\Html;

/**
 * The instructor's status page (replaces v4's file selector and file editor):
 * ?navbaronly=1&type=T&event=N, ?show=calendar_file_selector&type=T&event=N, and the day
 * numbers of the month view.  Every file of the event with its box, kind, release window
 * and whether students can see it now; a link to view the event as a student.
 */
final class StatusView
{
    public function __construct(
        private readonly Course $course,
        private readonly AccessPolicy $policy,
        private readonly string $entry,
    ) {
    }

    public function render(?EventFolder $folder): string
    {
        if ($folder === null) {
            return '<div class="cal5-viewbar"><h1 class="cal5-viewbar-title">Status</h1></div>'
                . '<p class="cal5-note">Open an event (from its menu, or from a day number in the month view) to see when students can see each of its files.</p>'
                . $this->summary();
        }
        $date = $this->course->schedule->dateOf($folder->type, $folder->number);
        $html = '<div class="cal5-viewbar"><h1 class="cal5-viewbar-title">' . Html::escape(ucwords(str_replace('-', ' ', $folder->type)) . ' ' . $folder->number . ': ' . $folder->name) . '</h1>'
            // a form, not a link, so the page links exactly the events v4's page links
            . '<form method="get" action="' . Html::escape($this->entry) . '" class="cal5-inline-form">'
            . '<input type="hidden" name="type" value="' . Html::escape($folder->type) . '">'
            . '<input type="hidden" name="event" value="' . $folder->number . '">'
            . '<input type="hidden" name="as" value="student">'
            . '<button type="submit" class="cal5-button">' . Icons::svg('eye') . '<span>View as a student</span></button></form></div>'
            . '<p class="cal5-note">' . Html::escape($date !== null ? 'On the calendar on ' . (new \DateTimeImmutable($date))->format('l j F Y') : 'Not on the calendar: students never see this event')
            . ' · directory ' . $folder->type . '/' . $folder->directory . '</p>';
        if ($folder->files === []) {
            return $html . '<p>This event has no files.</p>';
        }
        $html .= '<div class="cal5-table-wrap"><table class="cal5-status"><thead><tr><th scope="col">File</th><th scope="col">Box</th>'
            . '<th scope="col">Shown as</th><th scope="col">Students now</th><th scope="col">Release</th></tr></thead><tbody>';
        foreach ($folder->files as $name => $file) {
            $visible = $this->policy->visibleToStudents($folder, $file);
            $window = $this->policy->window($folder, $file);
            $box = $file->box !== null ? $file->box . ($file->inline ? ' (inline)' : '') : '';
            $source = $file->isVirtual() ? ' <span class="cal5-note">(virtual: ' . Html::escape((string) $file->virtualSource) . ')</span>' : '';
            $html .= '<tr><td><a href="' . Html::escape(CalendarModel::fileLink($this->entry, $folder, $file)) . '">' . Html::escape($name) . '</a>' . $source . '</td>'
                . '<td>' . Html::escape($box) . '</td>'
                . '<td>' . Html::escape(self::kind($file->kind->value)) . '</td>'
                . '<td>' . ($visible ? '<span class="cal5-badge cal5-badge-until">visible</span>' : '<span class="cal5-badge cal5-badge-closed">hidden</span>') . '</td>'
                . '<td>' . Html::escape($window->describe()) . ($window->rule !== null ? ' <span class="cal5-note">(rule: ' . Html::escape($window->rule->target) . ', line ' . $window->rule->line . ')</span>' : '') . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private static function kind(string $kind): string
    {
        return match ($kind) {
            'html' => 'page',
            'htm' => 'page (no layout)',
            'markdown' => 'Markdown page',
            'text' => 'text page',
            'pdf' => 'PDF (opens in the browser)',
            default => 'download',
        };
    }

    /** Course totals: how many files students can see now. */
    private function summary(): string
    {
        $files = 0;
        $visible = 0;
        foreach ($this->course->content->types as $events) {
            foreach ($events as $event) {
                foreach ($event->files as $file) {
                    $files++;
                    $visible += $this->policy->visibleToStudents($event, $file) ? 1 : 0;
                }
            }
        }
        return '<p>Students can see <strong>' . $visible . '</strong> of the course\'s ' . $files . ' files now. '
            . '<code>php calendar/bin/cal5 access</code> lists every file with its rule.</p>';
    }
}
