<?php

declare(strict_types=1);

namespace Cal5\Config;

final class FileSettings
{
    /**
     * @param list<string> $loadPages pages allowed with ?load= (error, lock and the guide are always added at use)
     * @param list<string> $allowedPaths extra directories symlinks may point into
     */
    public function __construct(
        public readonly ?string $access,
        public readonly ?string $virtual,
        public readonly array $loadPages,
        public readonly array $allowedPaths,
    ) {
    }
}
