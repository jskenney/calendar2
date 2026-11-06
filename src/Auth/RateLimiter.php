<?php

declare(strict_types=1);

namespace Cal5\Auth;

/**
 * Slows down password guessing: after MAX failed logins from one address
 * within WINDOW seconds, further attempts from it fail (even with the right
 * password) until the window has passed.  Stored in small files, one per
 * address and course, in the system temporary directory.
 */
final class RateLimiter
{
    public const MAX = 10;
    public const WINDOW = 900;

    public function __construct(
        private readonly string $directory,
        private readonly int $max = self::MAX,
        private readonly int $window = self::WINDOW,
    ) {
    }

    public static function forCourse(string $courseDir): self
    {
        return new self(self::directoryFor($courseDir));
    }

    public static function directoryFor(string $courseDir): string
    {
        return sys_get_temp_dir() . '/cal5-logins-' . substr(sha1((string) realpath($courseDir)), 0, 16);
    }

    public function blocked(string $ip, int $now): bool
    {
        return count($this->failures($ip, $now)) >= $this->max;
    }

    public function fail(string $ip, int $now): void
    {
        $failures = $this->failures($ip, $now);
        $failures[] = $now;
        $this->write($ip, $failures);
    }

    public function clear(string $ip): void
    {
        @unlink($this->file($ip));
    }

    /** @return list<int> failure times still inside the window */
    private function failures(string $ip, int $now): array
    {
        $data = @file_get_contents($this->file($ip));
        $times = is_string($data) ? json_decode($data, true) : null;
        if (!is_array($times)) {
            return [];
        }
        return array_values(array_filter($times, fn ($t): bool => is_int($t) && $t > $now - $this->window));
    }

    /** @param list<int> $failures */
    private function write(string $ip, array $failures): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }
        @file_put_contents($this->file($ip), json_encode(array_slice($failures, -100)), LOCK_EX);
    }

    private function file(string $ip): string
    {
        return $this->directory . '/' . sha1($ip) . '.json';
    }
}
