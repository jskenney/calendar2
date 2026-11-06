<?php

declare(strict_types=1);

namespace Cal5\Access;

/** One line of the access file: target, when it opens, and when (if ever) it locks again. */
final class Rule
{
    public function __construct(
        public readonly string $target,
        public readonly When $open,
        public readonly ?When $close,
        public readonly int $line,
    ) {
    }

    public function __toString(): string
    {
        $close = $this->close === null ? '' : ($this->close instanceof ForDurationWhen ? ' ' . $this->close : ' until ' . $this->close);
        return $this->target . ' ' . $this->open . $close;
    }
}
