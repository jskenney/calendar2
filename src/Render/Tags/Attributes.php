<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

/** Helpers shared by the tags: find tags and read their quoted attributes (v4 rules). */
final class Attributes
{
    /** @return list<string> every opening tag <name ...> in the html */
    public static function tags(string $html, string $name): array
    {
        preg_match_all('/<' . $name . '[^>]+>/i', $html, $m);
        return $m[0];
    }

    /** The value of attr="..." or attr='...' (v4 accepted both for these tags). */
    public static function quoted(string $tag, string $attr): ?string
    {
        if (preg_match('/' . $attr . '=(\x27[^\x27]*\x27|\x22[^\x22]*\x22)/i', $tag, $m)) {
            return substr($m[1], 1, -1);
        }
        return null;
    }

    /** The value of attr="..." (double quotes only, as v4 read <url> and <calphp>). */
    public static function doubleQuoted(string $tag, string $attr): ?string
    {
        if (preg_match('/' . $attr . '=("[^"]*")/i', $tag, $m)) {
            return substr($m[1], 1, -1);
        }
        return null;
    }

    /** Replace the written forms v4 recognised: <x attr="v">, <x attr='v'>, and with "/>" or " />". */
    public static function replaceForms(string $html, string $name, string $attr, string $value, string $with): string
    {
        foreach (['"' . $value . '"', "'" . $value . "'"] as $quoted) {
            foreach (['>', '/>', ' />'] as $end) {
                $html = str_ireplace('<' . $name . ' ' . $attr . '=' . $quoted . $end, $with, $html);
            }
        }
        return $html;
    }
}
