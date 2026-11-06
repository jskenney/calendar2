<?php

declare(strict_types=1);

namespace Cal5\Config;

final class SecuritySettings
{
    public function __construct(
        /** password_hash() output, or null */
        public readonly ?string $passwordHash,
        /** plain-text password (v4 $ADMIN style), or null; no password at all disables the login */
        public readonly ?string $password,
        public readonly string $secret,
        public readonly int $sessionSeconds,
        /** locked for students all the time */
        public readonly bool $lockAlways,
        /** locked for students while this file exists */
        public readonly ?string $lockFile,
    ) {
    }

    public function loginEnabled(): bool
    {
        return ($this->passwordHash ?? '') !== '' || ($this->password ?? '') !== '';
    }
}
