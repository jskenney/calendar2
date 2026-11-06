<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Render\PageContext;

/**
 * <img src="f">, <source src="f">, <a href="f"> and <showlink src="f"> for a file
 * of the event become its keyed link (v4).  Files the viewer may not see are
 * left alone, so their links lead nowhere.
 */
final class LinkRewriter implements Tag
{
    public function apply(string $html, PageContext $page): string
    {
        foreach ($page->files as $name => $file) {
            $link = $page->fileLink($file);
            foreach (['"', "'"] as $q) {
                $html = str_ireplace('<img src=' . $q . $name . $q, '<img src=' . $q . $link . $q, $html);
                $html = str_ireplace('<source src=' . $q . $name . $q, '<source src=' . $q . $link . $q, $html);
                $html = str_ireplace('<a href=' . $q . $name . $q, '<a href=' . $q . $link . $q, $html);
                $html = str_ireplace('<showlink src=' . $q . $name . $q . '>', $link, $html);
            }
        }
        return $html;
    }
}
