<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Http\AccessLog;
use Cal5\Http\Request;
use Cal5\Http\Route;
use Cal5\Http\Router;
use Cal5\Tests\TestCase;

/** Milestone 9: what each v4 URL form shows (Router), and the access log line. */
final class RouterTest extends TestCase
{
    /** @param array<string, mixed> $query */
    private static function route(array $query, bool $instructor = false, bool $locked = false, ?array $error = null, string $start = 'home', ?array $today = null): Route
    {
        return Router::route(new Request($query), ['class', 'lab'], $instructor, $locked, $error, $start, static fn (): ?array => $today);
    }

    public function testEventsAndTheStartPage(): void
    {
        $event = self::route(['type' => 'class', 'event' => '3']);
        $this->assertSame([Route::EVENT, 'class', 3], [$event->kind, $event->page->type, $event->page->event]);
        $file = self::route(['type' => 'class', 'event' => '3', 'key' => str_repeat('a', 40)]);
        $this->assertSame([Route::EVENT, str_repeat('a', 40)], [$file->kind, $file->page->key]);

        $home = self::route([]);
        $this->assertSame([Route::SITE, 'home', null], [$home->kind, $home->load, $home->show]);
        foreach ([['type' => 'class', 'event' => '02'], ['type' => 'exam', 'event' => '1'], ['event' => ['1'], 'type' => 'class']] as $bad) {
            $this->assertSame('home', self::route($bad)->load, json_encode($bad) ?: '');
        }
        $this->assertSame(null, self::route(['key' => str_repeat('b', 40)])->load, 'a key alone: no start page (v4 shows an empty page)');
        $this->assertSame(null, self::route(['type' => 'class'])->load, 'a type alone: the same');

        $today = self::route([], false, false, null, 'today', ['lab', 2]);
        $this->assertSame([Route::EVENT, 'lab', 2], [$today->kind, $today->page->type, $today->page->event], "today's lecture");
        $this->assertSame('home', self::route([], false, false, null, 'today', null)->load, 'no lecture today: home');
    }

    public function testViewsAndPages(): void
    {
        $this->assertSame(['policy', null], [self::route(['load' => 'policy'])->load, self::route(['load' => 'policy'])->show]);
        $search = self::route(['show' => 'calendar_search', 'search' => 'x']);
        $this->assertSame([Route::SITE, 'calendar_search', null], [$search->kind, $search->show, $search->load]);
        $both = self::route(['type' => 'class', 'event' => '1', 'show' => 'calendar_display']);
        $this->assertSame([Route::SITE, 'calendar_display', 'class'], [$both->kind, $both->show, $both->page->type], 'a view with an event: the view (v4 showed it first)');
        $status = self::route(['navbaronly' => '1', 'type' => 'class', 'event' => '2'], true);
        $this->assertSame([Route::SITE, 'calendar_file_selector', 2], [$status->kind, $status->show, $status->page->event], 'navbaronly: the status page');
        $this->assertSame(Route::EVENT, self::route(['type' => 'class', 'event' => '1', 'edit' => '1', 'editor' => 'x'])->kind, 'the editor parameters are ignored');
    }

    public function testErrorsAndLocks(): void
    {
        $error = self::route(['type' => 'class', 'event' => '1'], true, false, ['Title', 'Comment']);
        $this->assertSame([Route::SITE, 'error', null, ['error' => 'Title', 'error-comment' => 'Comment']], [$error->kind, $error->load, $error->page->type, $error->replace]);
        $locked = self::route(['type' => 'class', 'event' => '1', 'show' => 'calendar_display'], false, true);
        $this->assertSame([Route::SITE, 'lock', null, null], [$locked->kind, $locked->load, $locked->show, $locked->page->type], 'students: the lock page, nothing else');
        $this->assertSame(Route::EVENT, self::route(['type' => 'class', 'event' => '1'], true, true)->kind, 'the instructor is not locked out');
        $this->assertSame('an event', self::route(['type' => 'class', 'event' => '1'])->describe()['reason']);
    }

    public function testAccessLogLine(): void
    {
        $now = new \DateTimeImmutable('2026-09-14 13:30:05');
        $request = new Request(['type' => 'class'], [], [], ['REQUEST_URI' => '/c/calendar.php?type=class&event=1&lock=open&password=pw&csrf=t', 'REMOTE_ADDR' => '10.1.2.3']);
        $this->assertSame("20260914-13:30:05 10.1.2.3 type=class&event=1&lock=REMOVED&password=REMOVED&csrf=REMOVED\n", AccessLog::line($request, $now));
        $home = new Request([], ['password' => 'secret'], [], ['REQUEST_URI' => '/c/calendar.php', 'REMOTE_ADDR' => '10.1.2.3', 'REQUEST_METHOD' => 'POST']);
        $this->assertSame("20260914-13:30:05 10.1.2.3 home\n", AccessLog::line($home, $now), 'no query: "home"; posted values are never logged (v4)');
        $this->assertSame(null, AccessLog::path(null, '/c'));
        $this->assertSame('/c/logs/a.log', AccessLog::path('logs/a.log', '/c'));
        $this->assertSame('/var/log/a.log', AccessLog::path('/var/log/a.log', '/c'));
    }
}
