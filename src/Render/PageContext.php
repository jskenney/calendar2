<?php

declare(strict_types=1);

namespace Cal5\Render;

use Cal5\Access\EventContext;
use Cal5\Auth\SessionStore;
use Cal5\Config\Config;
use Cal5\Content\ContentFile;
use Cal5\Http\Request;
use DateTimeImmutable;

/** Everything the tag processors need to know about the page being rendered. */
final class PageContext
{
    /**
     * @param array<string, ContentFile> $files the event's files this viewer may see (v4 $other), by name
     * @param array<string, string> $replace values for <replace value="...">
     * @param array<string, mixed> $phpVariables variables available to <calphp> scripts
     */
    public function __construct(
        public readonly Config $config,
        public readonly bool $instructor,
        public readonly Request $request,
        public readonly SessionStore $session,
        public readonly array $files,
        public readonly ?string $type,
        public readonly ?int $event,
        public readonly array $replace,
        public readonly ?EventContext $eventContext,
        public readonly DateTimeImmutable $now,
        public readonly string $entry = 'calendar.php',
        public readonly array $phpVariables = [],
    ) {
    }

    /** calendar.php?key=K&type=T&event=E (v4's link; values URL-encoded, safe inside quoted attributes) */
    public function fileLink(ContentFile $file): string
    {
        $link = $this->entry . '?key=' . rawurlencode($file->key);
        if ($this->type !== null) {
            $link .= '&type=' . rawurlencode($this->type);
        }
        if ($this->event !== null) {
            $link .= '&event=' . $this->event;
        }
        return $link;
    }
}
