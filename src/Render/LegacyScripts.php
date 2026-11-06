<?php

declare(strict_types=1);

namespace Cal5\Render;

/**
 * Which legacy libraries a page needs (milestone 8).  v4 loaded jQuery, DataTables
 * and Chart.js on every page; Cal5 loads them only where they are used:
 *
 *  - features.legacy_js lists libraries loaded on every page, and/or "auto"
 *  - "auto" (the setting for translated v4 configurations) looks at the page's own
 *    scripts (inline <script> blocks, on...="" handlers, javascript: links) and at the
 *    course's script files, and loads what they use.  DataTables needs jQuery.
 */
final class LegacyScripts
{
    public const ORDER = ['jquery', 'datatables', 'chartjs'];

    /** The JavaScript written in a page: inline <script> blocks, event handler attributes, javascript: URLs. */
    public static function scriptText(string $html): string
    {
        $text = '';
        if (preg_match_all('#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $script) {
                if (!preg_match('/\bsrc\s*=/i', $script[1]) && !preg_match('/\btype\s*=\s*["\']?(?:text\/x-mathjax|math\/)/i', $script[1])) {
                    $text .= $script[2] . "\n";
                }
            }
        }
        if (preg_match_all('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')/i', $html, $m)) {
            $text .= implode("\n", array_map(static fn (string $v): string => html_entity_decode(substr($v, 1, -1), ENT_QUOTES | ENT_HTML5), $m[1])) . "\n";
        }
        if (preg_match_all('/\bhref\s*=\s*["\']\s*javascript:([^"\']*)/i', $html, $m)) {
            $text .= implode("\n", $m[1]) . "\n";
        }
        return $text;
    }

    /**
     * Libraries a piece of JavaScript uses.
     *
     * @return list<string> in loading order
     */
    public static function usedBy(string $js): array
    {
        $used = [];
        if (preg_match('/\b[dD]ataTable\s*\(|\$\.fn\.dataTable\b/', $js)) {
            $used['datatables'] = true;
            $used['jquery'] = true;
        }
        if (preg_match('/(?<![\w$.])jQuery\b|(?<![\w$.])\$\s*\(|(?<![\w$.])\$\s*\.\s*[A-Za-z_]/', $js)) {
            $used['jquery'] = true;
        }
        if (preg_match('/\bnew\s+Chart\s*\(|(?<![\w.])Chart\s*\.\s*(?:defaults|helpers|plugins|pluginService|controllers|register|Tooltip)\b/', $js)) {
            $used['chartjs'] = true;
        }
        return array_values(array_filter(self::ORDER, static fn (string $lib): bool => isset($used[$lib])));
    }

    /**
     * The libraries to load on a page.
     *
     * @param list<string> $setting features.legacy_js
     * @param string $html the page's content
     * @param string $courseScripts the text of the course's own script files ([style] js)
     * @return list<string> in loading order
     */
    public static function forPage(array $setting, string $html, string $courseScripts = ''): array
    {
        $load = array_fill_keys(array_intersect($setting, self::ORDER), true);
        if (in_array('auto', $setting, true)) {
            foreach (self::usedBy(self::scriptText($html) . "\n" . $courseScripts) as $lib) {
                $load[$lib] = true;
            }
        }
        if (isset($load['datatables'])) {
            $load['jquery'] = true;
        }
        return array_values(array_filter(self::ORDER, static fn (string $lib): bool => isset($load[$lib])));
    }
}
