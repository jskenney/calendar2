<?php

declare(strict_types=1);

namespace Cal5\Auth;

/** An in-memory session (tests and the command line). */
final class ArraySession implements SessionStore
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [], private string $id = 'test-session')
    {
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function regenerate(): string
    {
        $this->id = bin2hex(random_bytes(16));
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
