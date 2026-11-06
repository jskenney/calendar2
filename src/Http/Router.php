<?php

declare(strict_types=1);

namespace Cal5\Http;

/**
 * Maps every v4 URL form to what Cal5 shows (docs/design/routing.md), in v4's order:
 *
 *  1. a configuration that can not run (the published sample secret, an access or virtual
 *     file that can not be read): the error page; nothing else is shown
 *  2. a locked site, for students: the lock page
 *  3. type/event/key are validated as calendar-core 4.5 does; if any is invalid, all
 *     three are dropped (PageRequest)
 *  4. ?navbaronly with an event: the instructor's status page for it
 *  5. an event (without ?show / ?load): the event page or file
 *  6. nothing asked for (no type, event, key, load or show): the start page, which is
 *     today's lecture (display.start_page = today) when there is one, else ?load=home
 *  7. otherwise: the ?show= view, then the ?load= page
 *
 * ?edit / ?editor (the removed editor) are ignored; ?show=calendar_file_editor shows a note.
 */
final class Router
{
    /**
     * @param list<string> $types configured event types
     * @param array{0: string, 1: string}|null $error  title and explanation when the course can not run
     * @param callable(): ?array{0: string, 1: int} $today today's lecture (type, number), asked only when needed
     */
    public static function route(Request $request, array $types, bool $instructor, bool $locked, ?array $error, string $startPage, callable $today): Route
    {
        $page = PageRequest::from($request, $types);
        if ($error !== null) {
            return new Route(Route::SITE, PageRequest::none(), 'error', null, ['error' => $error[0], 'error-comment' => $error[1]], 'the course can not run');
        }
        if ($locked && !$instructor) {
            return new Route(Route::SITE, PageRequest::none(), 'lock', null, [], 'the site is locked');
        }
        $load = $request->param('load');
        $show = $request->param('show');
        if ($request->has('navbaronly') && $show === null) {
            $show = 'calendar_file_selector'; // v4: the instructor's file list
        }
        if ($page->isEvent() && $load === null && $show === null) {
            return new Route(Route::EVENT, $page, null, null, [], 'an event');
        }
        if ($page->type === null && $page->event === null && $page->key === null && $load === null && $show === null) {
            if ($startPage === 'today' && ($event = $today()) !== null) {
                return new Route(Route::EVENT, PageRequest::forEvent($event[0], $event[1]), null, null, [], "today's lecture");
            }
            return new Route(Route::SITE, $page, 'home', null, [], 'the start page');
        }
        return new Route(Route::SITE, $page, $load, $show, [], 'a view or page');
    }
}
