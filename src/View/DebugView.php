<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\AccessPolicy;
use Cal5\Auth\SessionStore;
use Cal5\Config\LoadResult;
use Cal5\Course;
use Cal5\Http\PageRequest;
use Cal5\Http\Request;
use Cal5\Support\Html;

/**
 * ?debug (v4): what Cal5 decided for this request, for the instructor of a course with
 * features.debug on (v4 $DEBUG_MODE).  Never shows the password, the secret, file keys
 * of other events or session values (only their names).
 */
final class DebugView
{
    private const SECRET_PARAMS = ['password', 'lock', 'csrf'];

    public static function enabled(LoadResult $config, bool $instructor, Request $request): bool
    {
        return $instructor && ($config->config?->features->debug ?? false) && $request->sent('debug');
    }

    /** @param array<string, mixed> $route */
    public static function render(Course $course, AccessPolicy $policy, LoadResult $config, Request $request, SessionStore $session, PageRequest $page, array $route): string
    {
        $params = [];
        foreach (['get' => $_GET, 'post' => $_POST] as $source => $values) {
            foreach ($values as $name => $value) {
                $params[] = [$source, (string) $name, in_array($name, self::SECRET_PARAMS, true) ? '(removed)' : (is_string($value) ? $value : '(array)')];
            }
        }
        $rows = static function (array $rows): string {
            $html = '';
            foreach ($rows as $row) {
                $html .= '<tr>' . implode('', array_map(static fn ($cell): string => '<td>' . Html::escape((string) $cell) . '</td>', $row)) . '</tr>';
            }
            return $html;
        };
        $html = '<section class="cal5-debug" aria-label="Debug"><h2>Debug</h2>'
            . '<p class="cal5-note">Shown because <code>[features] debug = yes</code> and the address has <code>debug</code>. Only you see it.</p>'
            . '<h3>Request</h3><table><tr><th>From</th><th>Parameter</th><th>Value</th></tr>' . $rows($params) . '</table>'
            . '<h3>Decision</h3><table>' . $rows(array_map(static fn ($k, $v): array => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v], array_keys($route), $route)) . '</table>'
            . '<h3>Configuration</h3><table>' . $rows([
                ['source', $config->source->value],
                ['errors', (string) count($config->errors)],
                ['warnings', (string) count($config->warnings)],
                ['time now', $policy->now()->format('Y-m-d H:i T')],
            ]) . '</table>'
            . '<h3>Session</h3><p>' . Html::escape(implode(', ', array_keys(self::sessionNames($session))) ?: '(empty)') . '</p>';

        $folder = $page->isEvent() ? $course->content->event((string) $page->type, (int) $page->event) : null;
        if ($folder !== null) {
            $files = [];
            foreach ($folder->files as $name => $file) {
                $files[] = [$name, (string) $file->box, $file->kind->value, $policy->visibleToStudents($folder, $file) ? 'yes' : 'no',
                    $policy->window($folder, $file)->describe(), $file->isVirtual() ? 'virtual: ' . $file->virtualSource : ''];
            }
            $html .= '<h3>Event ' . Html::escape($folder->type . ' ' . $folder->number . ' (' . $folder->directory . ')') . '</h3>'
                . '<table><tr><th>File</th><th>Box</th><th>Kind</th><th>Students now</th><th>Release</th><th></th></tr>' . $rows($files) . '</table>';
        }
        return $html . '</section>';
    }

    /** @return array<string, true> */
    private static function sessionNames(SessionStore $session): array
    {
        if ($session instanceof \Cal5\Auth\ArraySession) {
            return array_fill_keys(array_keys($session->all()), true);
        }
        return isset($_SESSION) && is_array($_SESSION) ? array_fill_keys(array_map('strval', array_keys($_SESSION)), true) : [];
    }
}
