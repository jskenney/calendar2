<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Render\PageContext;

/** <replace value="name"> -> a value from [replace] (v4 $PAGE_MODIFY) or a built-in value, escaped. */
final class ReplaceTag implements Tag
{
    public function apply(string $html, PageContext $page): string
    {
        foreach (Attributes::tags($html, 'replace') as $tag) {
            $name = Attributes::quoted($tag, 'value');
            if ($name === null || $name === '' || !array_key_exists($name, $page->replace)) {
                continue;
            }
            $value = htmlspecialchars($page->replace[$name], ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
            $html = Attributes::replaceForms($html, 'replace', 'value', $name, $value);
        }
        return $html;
    }
}
