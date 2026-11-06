<?php

declare(strict_types=1);

namespace Cal5\Config;

/** A navbar menu listing the events of one type. */
final class MenuItem
{
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $icon = '',
    ) {
    }

    public function __toString(): string
    {
        return $this->label . ($this->icon !== '' ? ' | ' . $this->icon : '');
    }
}
