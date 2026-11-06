<?php

declare(strict_types=1);

namespace Cal5\Auth;

/** The session, behind an interface so authentication can be tested without PHP sessions. */
interface SessionStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function id(): string;

    /** Give the session a new id (keeping its data) and return it. */
    public function regenerate(): string;
}
