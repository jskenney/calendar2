<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

final class AlwaysWhen implements When
{
    public function reached(EventContext $context, DateTimeImmutable $now): bool
    {
        return true;
    }

    public function at(EventContext $context): ?DateTimeImmutable
    {
        return new DateTimeImmutable('@0');
    }

    public function __toString(): string
    {
        return 'always';
    }
}
