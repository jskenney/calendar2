<?php

declare(strict_types=1);

namespace Cal5\Tests;

/** HTTP client with a cookie jar (one jar per simulated user). */
final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly Server $server)
    {
    }

    public function get(string $path): Response
    {
        return $this->request('GET', $path, null);
    }

    /** @param array<string, string> $data */
    public function post(string $path, array $data): Response
    {
        return $this->request('POST', $path, $data);
    }

    /** Log in the way a v4 course does: load the page, then post the password. */
    public function login(string $entry, string $password): Response
    {
        $this->get($entry);
        return $this->post($entry, ['password' => $password]);
    }

    /** Value of name="csrf" (or another hidden field) in a form, if present. */
    public static function formValue(Response $response, string $field = 'csrf'): ?string
    {
        $pattern = '/name=["\']' . preg_quote($field, '/') . '["\'][^>]*value=["\']([^"\']*)["\']/';
        return preg_match($pattern, $response->body, $m) ? html_entity_decode($m[1]) : null;
    }

    /** @param array<string, string>|null $data */
    private function request(string $method, string $path, ?array $data): Response
    {
        $headers = [];
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $options = ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30];
        if ($data !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = http_build_query($data);
        }
        $options['header'] = implode("\r\n", $headers);
        $context = stream_context_create(['http' => $options]);
        $body = @file_get_contents($this->server->url($path), false, $context);
        $raw = $http_response_header ?? [];

        // Keep only the headers of the final response
        $responseHeaders = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+\d+#', $line)) {
                $responseHeaders = [];
            }
            $responseHeaders[] = $line;
        }
        $status = 0;
        if (isset($responseHeaders[0]) && preg_match('#^HTTP/\S+\s+(\d+)#', $responseHeaders[0], $m)) {
            $status = (int) $m[1];
        }
        foreach ($responseHeaders as $line) {
            if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $m)) {
                if ($m[2] === '' || $m[2] === 'deleted') {
                    unset($this->cookies[$m[1]]);
                } else {
                    $this->cookies[$m[1]] = $m[2];
                }
            }
        }
        return new Response($status, $responseHeaders, $body === false ? '' : $body);
    }
}
