<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Content\FileKind;
use Cal5\Support\Html;

/**
 * ?show=calendar_search&search=TEXT and ?show=calendar_keyword&keyword=NAME (v4 names).
 *
 * As in v4, each calendar day's event is a result when the viewer may see its title
 * page (.html or .md) and that page
 *  - contains TEXT (plain text, not case sensitive), or
 *  - has the anchor <a name="NAME"></a> (the keyword; not case sensitive).
 * Results are shown like the list view: the event, its title link and its boxes.
 * Without a keyword, Cal5 lists the keywords the course's pages define.
 */
final class SearchView
{
    public function __construct(
        private readonly CalendarModel $model,
        private readonly CalendarView $calendar,
        private readonly string $entry,
    ) {
    }

    public function search(?string $term): string
    {
        $term = trim((string) $term);
        $html = '<div class="cal5-viewbar"><h1 class="cal5-viewbar-title">Search</h1></div>'
            . '<form class="cal5-search cal5-search-page" role="search" method="get" action="' . Html::escape($this->entry) . '">'
            . '<input type="hidden" name="show" value="calendar_search">'
            . '<label class="cal5-visually-hidden" for="cal5-search-page">Search the course</label>'
            . '<input id="cal5-search-page" type="search" name="search" value="' . Html::escape($term) . '" placeholder="Search the course pages">'
            . '<button type="submit" title="Search">' . Icons::svg('search') . "<span>Search</span></button></form>\n";
        if ($term === '') {
            return $html . '<p class="cal5-note">Type a word or phrase to find the class pages that contain it.</p>';
        }
        $rows = $this->rows(static fn (string $text): bool => stripos($text, $term) !== false);
        return $html . $this->results($rows, 'containing <mark>' . Html::escape($term) . '</mark>');
    }

    public function keyword(?string $keyword): string
    {
        $keyword = trim((string) $keyword);
        $html = '<div class="cal5-viewbar"><h1 class="cal5-viewbar-title">Keywords</h1></div>';
        if ($keyword === '') {
            return $html . $this->keywordIndex();
        }
        $rows = $this->rows(static function (string $text) use ($keyword): bool {
            foreach (self::anchors($text) as $anchor) {
                if (strtolower($anchor) === strtolower($keyword)) {
                    return true;
                }
            }
            return false;
        });
        return $html . $this->results($rows, 'with the keyword <mark>' . Html::escape($keyword) . '</mark>');
    }

    /** v4's keyword anchors: <a name="..."></a> exactly. @return list<string> */
    public static function anchors(string $text): array
    {
        preg_match_all('/<a name=\"(.*?)\"\><\/a>/s', $text, $m);
        return $m[1];
    }

    /**
     * Rows of the days whose title page matches.
     *
     * @param callable(string): bool $matches
     * @return list<string>
     */
    private function rows(callable $matches): array
    {
        $rows = [];
        foreach ($this->pages() as [$date, $entry, $text]) {
            if ($matches($text)) {
                $rows[] = $this->calendar->row($date, $entry, true);
            }
        }
        return $rows;
    }

    /** @return list<array{0: string, 1: CalendarEntry, 2: string}> date, entry and title page text of each searchable day */
    private function pages(): array
    {
        $pages = [];
        foreach ($this->model->days() as $date => $entries) {
            $entry = $entries[0] ?? null; // v4 searches the day's own event, not what [combine] adds
            if ($entry === null || $entry->scheduled->combined || $entry->folder === null || $entry->title === null) {
                continue;
            }
            $kind = $entry->title->file->kind;
            if (!in_array($kind, [FileKind::Html, FileKind::Htm, FileKind::Markdown], true)) {
                continue;
            }
            $pages[] = [$date, $entry, (string) @file_get_contents($entry->title->file->actualPath)];
        }
        return $pages;
    }

    /** @param list<string> $rows */
    private function results(array $rows, string $what): string
    {
        $count = count($rows);
        $html = '<p class="cal5-result-count" role="status">' . $count . ' class page' . ($count === 1 ? '' : 's') . ' ' . $what . '</p>';
        if ($rows === []) {
            return $html;
        }
        return $html . "<section class=\"cal5-list\"><ol class=\"cal5-rows\">\n" . implode('', $rows) . "</ol></section>\n";
    }

    private function keywordIndex(): string
    {
        $keywords = [];
        foreach ($this->pages() as [, , $text]) {
            foreach (self::anchors($text) as $anchor) {
                if ($anchor !== '') {
                    $keywords[strtolower($anchor)] ??= $anchor;
                }
            }
        }
        if ($keywords === []) {
            return '<p class="cal5-note">No keywords yet. A class page defines one with <code>&lt;a name="keyword"&gt;&lt;/a&gt;</code>.</p>';
        }
        ksort($keywords);
        $html = '<ul class="cal5-keywords">';
        foreach ($keywords as $keyword) {
            $html .= '<li><a href="' . Html::escape($this->entry . '?show=calendar_keyword&keyword=' . rawurlencode($keyword)) . '">' . Html::escape($keyword) . '</a></li>';
        }
        return $html . "</ul>\n";
    }
}
