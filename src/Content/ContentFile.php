<?php

declare(strict_types=1);

namespace Cal5\Content;

/** A file that belongs to an event (found in its directory, or placed there by the virtual file). */
final class ContentFile
{
    public function __construct(
        /** Name inside the event: a relative path such as "notes.html" or "sub/a.png", or the virtual name */
        public readonly string $name,
        /** Absolute path of the file on disk (symbolic links resolved) */
        public readonly string $actualPath,
        public readonly string $key,
        public readonly FileKind $kind,
        /** The box this file belongs to (its "category" in v4), or null */
        public readonly ?string $box,
        /** Listed as "inline:" for its box: the text is pasted into the calendar box */
        public readonly bool $inline,
        /** The real file of a virtual file, as written in the virtual file; null for real files */
        public readonly ?string $virtualSource = null,
    ) {
    }

    public function isVirtual(): bool
    {
        return $this->virtualSource !== null;
    }
}
