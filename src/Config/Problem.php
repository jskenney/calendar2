<?php

declare(strict_types=1);

namespace Cal5\Config;

/** A configuration error or warning, with where it came from when known. */
final class Problem
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
    ) {
    }

    public function __toString(): string
    {
        $where = '';
        if ($this->file !== null) {
            $where = $this->file . ($this->line !== null ? ':' . $this->line : '') . ': ';
        }
        return $where . $this->message;
    }
}
