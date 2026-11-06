<?php

declare(strict_types=1);

namespace Cal5\Auth;

final class AuthResult
{
    public const LOGGED_IN = 'logged-in';
    public const LOGGED_OUT = 'logged-out';
    public const FAILED = 'failed';
    public const BLOCKED = 'blocked';

    /** @param list<Cookie> $cookies */
    public function __construct(
        public readonly bool $instructor,
        public readonly array $cookies,
        /** What happened on this request: one of the constants above, or null */
        public readonly ?string $event,
        /** The nonce for the login form's challenge-response (null when logged in or login disabled) */
        public readonly ?string $nonce,
    ) {
    }
}
