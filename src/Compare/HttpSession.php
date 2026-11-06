<?php

declare(strict_types=1);

namespace Cal5\Compare;

/** A browser-like session (cookie jar) against one PhpServer. */
final class HttpSession
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly PhpServer $server)
    {
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function get(string $path): array
    {
        $header = $this->cookies === [] ? '' : 'Cookie: ' . implode('; ', array_map(
            static fn (string $k, string $v): string => "$k=$v", array_keys($this->cookies), $this->cookies));
        $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0,
            'timeout' => 60, 'header' => $header]]);
        $body = @file_get_contents($this->server->url($path), false, $context);
        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $headers[$name] = trim($value);
                if ($name === 'set-cookie' && preg_match('/^\s*([^=;\s]+)=([^;]*)/', $value, $c)) {
                    if ($c[2] === '' || $c[2] === 'deleted') {
                        unset($this->cookies[$c[1]]);
                    } else {
                        $this->cookies[$c[1]] = $c[2];
                    }
                }
            }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body === false ? '' : $body];
    }
}
