<?php

declare(strict_types=1);

namespace Cal5\Http;

/** What a request shows (Router::route). */
final class Route
{
    public const EVENT = 'event';   // an event page or one of its files (View\EventPage)
    public const SITE = 'site';     // anything else: ?show= views and ?load= pages (View\SitePage)

    /** @param array<string, string> $replace extra <replace> values (the error page's) */
    public function __construct(
        public readonly string $kind,
        public readonly PageRequest $page,
        public readonly ?string $load = null,
        public readonly ?string $show = null,
        public readonly array $replace = [],
        /** Why: for the debug view and the tests */
        public readonly string $reason = '',
    ) {
    }

    /** @return array<string, string> */
    public function describe(): array
    {
        return [
            'route' => $this->kind,
            'reason' => $this->reason,
            'event' => $this->page->isEvent() ? $this->page->type . ' ' . $this->page->event : '-',
            'file key' => $this->page->key ?? '-',
            'load' => $this->load ?? '-',
            'show' => $this->show ?? '-',
        ];
    }
}
