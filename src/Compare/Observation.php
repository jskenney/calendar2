<?php

declare(strict_types=1);

namespace Cal5\Compare;

/**
 * What must match between v4 and Cal5 for one response (bin/cal5 compare), independent
 * of the layout:
 *
 *  - every response: the status, and whether it is a page or a file
 *  - files: content type, inline/attachment, SHA-1 of the body (text files: their text)
 *  - pages: the keyed file links and the event links on the page
 *  - content pages (event pages, ?load= pages): the text of the course content, without
 *    the navbar, forms, scripts and the instructor's warnings
 */
final class Observation
{
    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     * @return array<string, mixed>
     */
    public static function of(array $response, string $entry, bool $content, bool $v4): array
    {
        $type = strtolower(trim(explode(';', $response['headers']['content-type'] ?? '')[0]));
        $disposition = $response['headers']['content-disposition'] ?? null;
        $isPage = ($type === '' || $type === 'text/html') && ($disposition === null || stripos($disposition, 'attachment') === false);
        if (!$isPage) {
            $file = [
                'status' => $response['status'],
                'kind' => 'download',
                'content_type' => $type,
                'disposition' => $disposition === null ? 'none' : (stripos($disposition, 'attachment') !== false ? 'attachment' : 'inline'),
                'sha1' => sha1($response['body']),
            ];
            if (str_starts_with($type, 'text/')) {
                $file['text'] = self::words($response['body']);
            }
            return $file;
        }
        $links = self::links($response['body'], $entry);
        $page = ['status' => $response['status'], 'kind' => 'page', 'keyed_links' => $links['keyed'], 'event_links' => $links['event']];
        if ($content) {
            $page['text'] = self::words(self::content($response['body'], $v4));
        }
        return $page;
    }

    /**
     * Links to the course's entry script on a page, reduced to key/type/event.
     *
     * @return array{keyed: list<string>, event: list<string>, all: list<string>}
     */
    public static function links(string $body, string $entry): array
    {
        $keyed = [];
        $event = [];
        $all = [];
        preg_match_all('/' . preg_quote($entry, '/') . '\?([^"\'\s<>#]*)/', $body, $m);
        foreach ($m[1] as $query) {
            $query = html_entity_decode($query, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $all[] = $query;
            parse_str($query, $params);
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
        foreach (['keyed', 'event', 'all'] as $list) {
            $$list = array_values(array_unique($$list));
            sort($$list);
        }
        return ['keyed' => $keyed, 'event' => $event, 'all' => $all];
    }

    /** The course content of a page: v4's container, or Cal5's <main>. */
    public static function content(string $html, bool $v4): string
    {
        if ($v4) {
            $start = strpos($html, '<!-- Begin providing the contents of the page -->');
            $end = strrpos($html, '</div> <!-- /container -->');
            $html = $start === false ? $html : substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
            $html = (string) preg_replace('#<div class=.cal4-config-warnings.*?</ul></div>#s', '', $html);
            $html = (string) preg_replace('#<div style="color:red;">WARNING: You are using the <em>old</em>.*?</ol></div>#s', '', $html);
            $html = (string) preg_replace('#<pre><code>INSTRUCTOR=.*#s', '', $html); // v4's debug dump
        } else {
            if (preg_match('#<main\b[^>]*>(.*)</main>#s', $html, $m)) {
                $html = $m[1];
            }
            $html = (string) preg_replace('#<div class="cal5-warnings".*?</ul></div></div>#s', '', $html);
            $html = (string) preg_replace('#<section class="cal5-debug".*?</section>#s', '', $html);
        }
        // forms (the <lock> unlock form) and scripts are presented differently on purpose
        return (string) preg_replace(['#<form\b.*?</form>#si', '#<script\b.*?</script>#si', '#<style\b.*?</style>#si'], ' ', $html);
    }

    /** Text as a normalized word sequence. */
    public static function words(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<(br|p|div|li|tr|h\d)\b#i', ' <$1', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
