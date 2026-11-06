<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

/** "for 48h": a fixed time after the opening moment. */
final class ForDurationWhen implements When
{
    public function __construct(
        private readonly When $open,
        public readonly int $seconds,
        private readonly string $text,
    ) {
    }

    public function reached(EventContext $context, DateTimeImmutable $now): bool
    {
        $at = $this->at($context);
        return $at !== null && $now >= $at;
    }

    public function at(EventContext $context): ?DateTimeImmutable
    {
        $open = $this->open->at($context);
        return $open === null ? null : $open->setTimestamp($open->getTimestamp() + $this->seconds);
    }

    public function __toString(): string
    {
        return 'for ' . $this->text;
    }
}
