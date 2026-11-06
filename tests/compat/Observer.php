<?php

declare(strict_types=1);

namespace Cal5\Tests\Compat;

use Cal5\Tests\Response;

/**
 * Reduces a response to the facts that must match between v4 and Cal5,
 * independent of page layout and styling:
 *
 *  - pages:     status, which content markers are visible, the keyed file links
 *               and the event links on the page, and any PHP errors
 *  - downloads: status, content type, inline/attachment, SHA-1 of the body
 */
final class Observer
{
    /** @param array{pattern?: string, list?: list<string>} $markers */
    public function __construct(private readonly array $markers)
    {
    }

    /** @return array<string, mixed> */
    public function observe(Response $response): array
    {
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $disposition = $response->header('Content-Disposition');
        $isPage = ($type === '' || $type === 'text/html') && ($disposition === null || stripos($disposition, 'attachment') === false);

        if (!$isPage) {
            $download = [
                'status' => $response->status,
                'kind' => 'download',
                'content_type' => $type,
                'disposition' => $disposition === null ? 'none' : (stripos($disposition, 'attachment') !== false ? 'attachment' : 'inline'),
                'sha1' => sha1($response->body),
            ];
            if (str_starts_with($type, 'text/')) {
                // text files: also which markers they contain (Cal5 shows .txt files as pages)
                $download['markers'] = $this->markers($response->body);
            }
            return $download;
        }

        $links = self::links($response->body);
        return [
            'status' => $response->status,
            'kind' => 'page',
            'markers' => $this->markers($response->body),
            'keyed_links' => $links['keyed'],
            'event_links' => $links['event'],
            // file paths are reduced to file names so recordings do not depend on where they were made
            'php_errors' => array_map(
                static fn (string $e): string => substr((string) preg_replace('#(?:/[^/\s]+)+/([^/\s]+\.php)#', '$1', $e), 0, 120),
                $response->phpErrors(),
            ),
        ];
    }

    /** @return list<string> markers present in the body, sorted */
    public function markers(string $body): array
    {
        $found = [];
        if (isset($this->markers['pattern'])) {
            preg_match_all('/' . str_replace('/', '\/', $this->markers['pattern']) . '/', $body, $m);
            $found = $m[0];
        }
        foreach ($this->markers['list'] ?? [] as $marker) {
            if (str_contains($body, $marker)) {
                $found[] = $marker;
            }
        }
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }

    /**
     * calendar.php links on a page, reduced to their key/type/event parameters.
     *
     * @return array{keyed: list<string>, event: list<string>}
     */
    public static function links(string $body): array
    {
        $keyed = [];
        $event = [];
        preg_match_all('/calendar\.php\?([^"\'\s<>]*)/', $body, $m);
        foreach ($m[1] as $query) {
            parse_str(html_entity_decode($query, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $params);
            $parts = [];
            foreach (['key', 'type', 'event'] as $name) {
                if (isset($params[$name]) && is_string($params[$name]) && $params[$name] !== '') {
                    $parts[] = $name . '=' . $params[$name];
                }
            }
            if (isset($params['key'])) {
                $keyed[] = implode('&', $parts);
            } elseif (isset($params['type'], $params['event'])) {
                $event[] = implode('&', $parts);
            }
        }
        $keyed = array_values(array_unique($keyed));
        $event = array_values(array_unique($event));
        sort($keyed);
        sort($event);
        return ['keyed' => $keyed, 'event' => $event];
    }
}
