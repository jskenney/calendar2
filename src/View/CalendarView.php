<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Support\Html;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * The calendar views (v4 ?show=):
 *
 *  - month:  calendar_display, a grid per month
 *  - list:   calendar_display_alt, every day with material, by month
 *  - agenda: calendar_display_dateless, the same without dates ("Available material")
 *
 * They show the same events, links and inline text as v4 (CalendarModel decides);
 * only the markup is new.  On narrow screens the month grid becomes a list of days.
 */
final class CalendarView
{
    public const VIEWS = ['calendar_display' => 'month', 'calendar_display_alt' => 'list', 'calendar_display_dateless' => 'agenda'];
    private const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function __construct(private readonly CalendarModel $model, private readonly string $entry)
    {
    }

    public function render(string $view): string
    {
        $tabs = $this->tabs($view);
        return match ($view) {
            'list' => $tabs . $this->list(true),
            'agenda' => $tabs . $this->list(false),
            default => $tabs . $this->month(),
        };
    }

    private function tabs(string $current): string
    {
        $html = '<div class="cal5-viewbar"><h1 class="cal5-viewbar-title">Calendar</h1>'
            . '<nav class="cal5-tabs" aria-label="Calendar views">';
        foreach (['month' => 'Month', 'list' => 'List', 'agenda' => 'Agenda'] as $view => $label) {
            $show = array_search($view, self::VIEWS, true);
            $attr = $view === $current ? ' aria-current="page"' : '';
            $html .= '<a href="' . Html::escape($this->entry . '?show=' . $show) . "\"$attr>" . Icons::svg($view === 'month' ? 'calendar' : ($view === 'list' ? 'list' : 'layers')) . $label . '</a>';
        }
        $html .= '</nav>';
        if ($current === 'month' && $this->inRange($this->model->today)) {
            $html .= '<a class="cal5-today-link" href="#today">' . Icons::svg('target') . 'Today</a>';
        }
        return $html . "</div>\n";
    }

    private function inRange(string $date): bool
    {
        [$from, $until] = $this->model->range();
        return $date >= $from && $date <= $until;
    }

    private function month(): string
    {
        [$from, $until] = $this->model->range();
        $days = $this->model->days();
        $weekends = $this->model->weekends();
        $columns = $weekends ? 7 : 5;
        $html = '';
        $first = new DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = new DateTimeImmutable(substr($until, 0, 7) . '-01');
        foreach (new DatePeriod($first, new DateInterval('P1M'), $last->modify('+1 day')) as $month) {
            $id = 'm-' . $month->format('Y-m');
            $html .= '<section class="cal5-month" aria-labelledby="' . $id . '"><h2 id="' . $id . '">' . $month->format('F Y') . "</h2>\n"
                . '<table class="cal5-grid cal5-cols-' . $columns . '"><thead><tr>';
            for ($i = 0; $i < $columns; $i++) {
                $html .= '<th scope="col"><abbr title="' . self::WEEKDAYS[$i] . '">' . substr(self::WEEKDAYS[$i], 0, 3) . '</abbr></th>';
            }
            $html .= "</tr></thead><tbody>\n";
            // weeks as rows of cells; weeks entirely outside the semester are left out
            $weeks = [];
            $week = [];
            $inside = false;
            $offset = (int) $month->format('N') - 1; // Monday = 0
            for ($i = 0; $i < min($offset, $columns); $i++) {
                $week[] = '<td class="cal5-pad" aria-hidden="true"></td>';
            }
            $end = $month->modify('last day of this month');
            for ($day = $month; $day <= $end; $day = $day->modify('+1 day')) {
                $weekday = (int) $day->format('N') - 1;
                $date = $day->format('Y-m-d');
                if ($weekday < $columns) {
                    $week[] = $this->dayCell($date, $days[$date] ?? [], $from, $until);
                    $inside = $inside || ($date >= $from && $date <= $until);
                }
                if ($weekday === 6 || $date === $end->format('Y-m-d')) {
                    while (count($week) < $columns) {
                        $week[] = '<td class="cal5-pad" aria-hidden="true"></td>';
                    }
                    if ($inside) {
                        $weeks[] = '<tr>' . implode('', $week) . '</tr>';
                    }
                    $week = [];
                    $inside = false;
                }
            }
            $html .= implode("\n", $weeks) . "\n";
            $html .= "</tbody></table></section>\n";
        }
        return $html;
    }

    /** @param list<CalendarEntry> $entries */
    private function dayCell(string $date, array $entries, string $from, string $until): string
    {
        $classes = ['cal5-day'];
        $outside = $date < $from || $date > $until;
        if ($outside) {
            $classes[] = 'cal5-outside';
        }
        if ($entries === []) {
            $classes[] = 'cal5-empty';
        }
        $today = $date === $this->model->today;
        if ($today) {
            $classes[] = 'cal5-today';
        }
        $label = (new DateTimeImmutable($date))->format('l j F');
        $number = (int) substr($date, 8, 2);
        $html = '<td class="' . implode(' ', $classes) . '"' . ($today ? ' id="today"' : '') . '>';
        if ($outside) {
            return $html . '<span class="cal5-date" aria-hidden="true">' . $number . '</span></td>';
        }
        $first = $entries[0] ?? null;
        $date = '<span class="cal5-visually-hidden">' . Html::escape($label) . ($today ? ' (today)' : '') . '</span><span aria-hidden="true">' . $number . '</span>';
        if ($this->model->instructor && $first !== null && $first->folder !== null) {
            // v4: the instructor's day number opens the event's file list
            $html .= '<a class="cal5-date" title="Files of this event" href="' . Html::escape(CalendarModel::eventLink($this->entry, $first->folder->type, $first->folder->number) . '&navbaronly=1') . '">' . $date . '</a>';
        } else {
            $html .= '<span class="cal5-date">' . $date . '</span>';
        }
        foreach ($entries as $entry) {
            $html .= $this->entryBlock($entry);
        }
        return $html . "</td>";
    }

    private function entryBlock(CalendarEntry $entry): string
    {
        $type = $entry->scheduled->type;
        $html = '<div class="cal5-entry cal5-c' . $this->model->colour($type) . ($entry->scheduled->combined ? ' cal5-combined' : '') . '">'
            . '<span class="cal5-tag calCore-cal-' . Html::escape(strtoupper($type)) . '">' . Html::escape(strtoupper($entry->heading())) . '</span>';
        $html .= $this->title($entry);
        if ($entry->boxes !== []) {
            $html .= '<ul class="cal5-boxes">';
            foreach ($entry->boxes as $box) {
                $html .= $this->boxItem($entry, $box);
            }
            $html .= '</ul>';
        }
        return $html . '</div>';
    }

    private function title(CalendarEntry $entry): string
    {
        $name = $entry->name();
        if ($name === '') {
            return '';
        }
        if ($entry->title === null || $entry->folder === null) {
            return '<span class="cal5-entry-title">' . Html::escape($name) . '</span>';
        }
        return '<a class="cal5-entry-title" href="' . Html::escape(CalendarModel::eventLink($this->entry, $entry->folder->type, $entry->folder->number)) . '">'
            . Html::escape($name) . '</a>' . $this->badge($entry->title);
    }

    private function boxItem(CalendarEntry $entry, CalendarBox $box): string
    {
        if ($box->inline !== null) {
            // v4 pastes the file's text into the calendar as it is (instructor-written HTML)
            return '<li class="cal5-inline">' . $box->inline . $this->badge($box) . '</li>';
        }
        $link = CalendarModel::fileLink($this->entry, $entry->folder ?? throw new \LogicException('box without event'), $box->file);
        return '<li><a href="' . Html::escape($link) . '">' . Icons::svg(self::fileIcon($box->file->name)) . Html::escape($box->label()) . '</a>' . $this->badge($box) . '</li>';
    }

    private static function fileIcon(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'file-text',
            'ppt', 'pptx' => 'presentation',
            'zip', 'gz', 'tgz', 'bz2' => 'archive',
            'mp4', 'webm', 'm4v', 'mp3', 'm4a' => 'play',
            default => 'file',
        };
    }

    /**
     * Release badges.  The instructor sees when hidden material opens (or that it is
     * closed); everyone sees when visible material locks again.
     */
    private function badge(CalendarBox $box): string
    {
        $closes = $box->window->closes();
        if ($box->state === 'opens') {
            $opens = $box->window->opens();
            return ' <span class="cal5-badge cal5-badge-opens" title="' . Html::escape($box->window->describe()) . '">' . Icons::svg('clock') . 'opens ' . Html::escape(self::short($opens)) . '</span>';
        }
        if ($box->state === 'closed') {
            return ' <span class="cal5-badge cal5-badge-closed" title="' . Html::escape($box->window->describe()) . '">' . Icons::svg('lock') . 'hidden</span>';
        }
        if ($closes !== null && $closes > $this->model->now()) {
            return ' <span class="cal5-badge cal5-badge-until" title="' . Html::escape($box->window->describe()) . '">until ' . Html::escape(self::short($closes)) . '</span>';
        }
        return '';
    }

    private static function short(?DateTimeImmutable $time): string
    {
        if ($time === null) {
            return '';
        }
        return $time->format($time->format('H:i') === '00:00' ? 'D j M' : 'D j M H:i');
    }

    /** The list (with dates, by month) or agenda (without dates) view. */
    private function list(bool $dated): string
    {
        $html = '';
        $currentMonth = null;
        $open = false;
        if (!$dated) {
            $html .= "<section class=\"cal5-list\"><h2>Available material</h2>\n<ol class=\"cal5-rows\">\n";
            $open = true;
        }
        foreach ($this->model->days() as $date => $entries) {
            $day = new DateTimeImmutable($date);
            if ($dated && $day->format('Y-m') !== $currentMonth) {
                if ($open) {
                    $html .= "</ol></section>\n";
                }
                $currentMonth = $day->format('Y-m');
                $html .= '<section class="cal5-list"><h2>' . $day->format('F Y') . "</h2>\n<ol class=\"cal5-rows\">\n";
                $open = true;
            }
            foreach ($entries as $i => $entry) {
                if (!$entry->scheduled->combined && $entry->folder === null) {
                    continue; // v4 lists only days with material (and what [combine] adds)
                }
                $html .= $this->row($date, $entry, $dated, $i === 0 || $entries[0]->folder === null);
            }
        }
        if ($open) {
            $html .= "</ol></section>\n";
        }
        return $html;
    }

    /** One row of the list views (and of search results). */
    public function row(string $date, CalendarEntry $entry, bool $dated, bool $showDate = true): string
    {
        $day = new DateTimeImmutable($date);
        $today = $date === $this->model->today;
        $html = '<li class="cal5-row cal5-c' . $this->model->colour($entry->scheduled->type) . ($today ? ' cal5-today' : '') . '">';
        if ($dated) {
            $html .= '<span class="cal5-row-date">' . ($showDate ? '<time datetime="' . $date . '">' . $day->format('D j M') . '</time>' : '') . ($today ? ' <span class="cal5-badge">today</span>' : '') . '</span>';
        }
        $html .= '<span class="cal5-tag calCore-cal-' . Html::escape(strtoupper($entry->scheduled->type)) . '">' . Html::escape(ucwords($entry->heading())) . '</span>'
            . '<span class="cal5-row-main">' . $this->title($entry);
        if ($entry->boxes !== []) {
            $html .= '<ul class="cal5-boxes cal5-boxes-inline">';
            foreach ($entry->boxes as $box) {
                $html .= $this->boxItem($entry, $box);
            }
            $html .= '</ul>';
        }
        return $html . "</span></li>\n";
    }
}
