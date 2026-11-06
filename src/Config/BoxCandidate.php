<?php

declare(strict_types=1);

namespace Cal5\Config;

/** A file name that can fill a box.  "inline" files are pasted into the calendar box (v4 $SRC). */
final class BoxCandidate
{
    public function __construct(public readonly string $file, public readonly bool $inline = false)
    {
    }

    public function __toString(): string
    {
        return ($this->inline ? 'inline:' : '') . $this->file;
    }
}
