<?php

declare(strict_types=1);

namespace Cal5\Http;

/**
 * The parts of an HTTP request Cal5 uses.  Built from PHP's globals once per
 * request (fromGlobals) or by hand in tests.  Values are only ever read as
 * strings: arrays sent as parameters (x[]=1) are treated as missing.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $cookies = [],
        private readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_COOKIE, $_SERVER);
    }

    /** A parameter from the query string or form (POST wins, like v4's $_REQUEST order). */
    public function param(string $name): ?string
    {
        $value = $this->post[$name] ?? $this->query[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function has(string $name): bool
    {
        return $this->param($name) !== null;
    }

    /** Was the parameter sent at all, in any form (string or array)? */
    public function sent(string $name): bool
    {
        return array_key_exists($name, $this->post) || array_key_exists($name, $this->query);
    }

    /** A request header, such as "Range". */
    public function header(string $name): ?string
    {
        $value = $this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
        return is_string($value) ? $value : null;
    }

    public function server(string $name): string
    {
        $value = $this->server[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    public function postParam(string $name): ?string
    {
        $value = $this->post[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * A cookie.  PHP turns "." and " " in cookie names into "_" when it fills $_COOKIE,
     * so both spellings are tried.
     */
    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? $this->cookies[strtr($name, ['.' => '_', ' ' => '_'])] ?? null;
        return is_string($value) ? $value : null;
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function clientIp(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? '';
        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
