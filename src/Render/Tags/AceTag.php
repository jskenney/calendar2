<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Render\PageContext;

/**
 * <ace name="x" mode="python" theme="tomorrow" height="48px" readonly="true"
 * nolinenumbers="true" textwrap="true">code</ace>: an Ace editor box.  An element
 * with id "ace_<name>" shows the editor's contents as HTML (v4).  Unlike v4 no
 * jQuery is needed, attribute values are limited to safe characters before
 * they go into the script, and Ace's background syntax checkers (workers) are
 * not used: the boxes show and run code, they do not lint it.
 */
final class AceTag implements Tag
{
    public bool $used = false;

    public function apply(string $html, PageContext $page): string
    {
        preg_match_all('#<\s*?ace\b[^>]*>(.*?)</ace\b[^>]*>#s', $html, $ace);
        $scripts = '';
        foreach ($ace[0] as $i => $whole) {
            $this->used = true;
            $attr = static fn (string $name, string $default, string $allowed): string =>
                (preg_match('/' . $name . '=("[^"]*")/i', $whole, $m) && preg_match('/^[' . $allowed . ']+$/', substr($m[1], 1, -1)))
                    ? substr($m[1], 1, -1) : $default;
            $name = $attr('name', "ace$i", 'A-Za-z0-9_');
            $mode = $attr('mode', 'html_elixir', 'A-Za-z0-9_');
            $theme = $attr('theme', 'tomorrow', 'A-Za-z0-9_');
            $height = $attr('height', '48px', 'A-Za-z0-9.%');
            $flag = static fn (string $name): bool => (bool) preg_match('/' . $name . '="true"/i', $whole);
            $code = str_ireplace(['<', '>'], ['&lt;', '&gt;'], $ace[1][$i]);
            $html = str_ireplace($whole, '<div id="' . $name . '" class="cal5-ace">' . $code . '</div>', $html);
            $scripts .= "\n<script>\n(function () {\n"
                . "  var editor = ace.edit(\"$name\");\n"
                . "  editor.setTheme(\"ace/theme/$theme\");\n"
                . "  editor.getSession().setMode(\"ace/mode/$mode\");\n"
                . "  editor.getSession().setUseWorker(false);\n"
                . ($flag('textwrap') ? "  editor.getSession().setUseWrapMode(true);\n" : '')
                . ($flag('readonly') ? "  editor.setReadOnly(true);\n" : '')
                . ($flag('nolinenumbers') ? "  editor.renderer.setShowGutter(false);\n" : '')
                . "  function show() { var target = document.getElementById(\"ace_$name\"); if (target) { target.innerHTML = editor.getValue(); } }\n"
                . "  editor.on(\"input\", show);\n  show();\n  window[\"$name\"] = editor;\n"
                . "})();\n</script>\n<style>#$name { height: $height; }</style>\n";
        }
        return $html . $scripts;
    }
}
