<?php

declare(strict_types=1);

namespace Cal5\Auth;

/**
 * PHP's own session, started with safe settings.  The session name and storage
 * stay PHP's defaults, the same as v4, so an instructor logged in under v4
 * stays logged in when the course switches to Cal5.
 */
final class PhpSession implements SessionStore
{
    public static function start(bool $https): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');   // never adopt a session id the server did not create
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax']);
            session_start();
        }
        return new self();
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function id(): string
    {
        return (string) session_id();
    }

    public function regenerate(): string
    {
        session_regenerate_id(true);
        return (string) session_id();
    }
}
