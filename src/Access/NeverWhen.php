<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

final class NeverWhen implements When
{
    public function reached(EventContext $context, DateTimeImmutable $now): bool
    {
        return false;
    }

    public function at(EventContext $context): ?DateTimeImmutable
    {
        return null;
    }

    public function __toString(): string
    {
        return 'never';
    }
}
