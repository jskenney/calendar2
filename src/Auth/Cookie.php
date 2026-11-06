<?php

declare(strict_types=1);

namespace Cal5\Auth;

/** A cookie to send with the response (always HttpOnly and SameSite=Lax; Secure over HTTPS). */
final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        /** Unix time; in the past deletes the cookie */
        public readonly int $expires,
    ) {
    }

    public function send(bool $https): void
    {
        if (!headers_sent()) {
            setcookie($this->name, $this->value, [
                'expires' => $this->expires,
                'path' => '',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}
