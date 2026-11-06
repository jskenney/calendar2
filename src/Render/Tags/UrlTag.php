<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Render\PageContext;
use Cal5\Support\Html;

/**
 * <url value="name" default="text">: the request parameter "name" (remembered in
 * the session for later pages), or the default; always escaped (v4).
 */
final class UrlTag implements Tag
{
    public function apply(string $html, PageContext $page): string
    {
        foreach (Attributes::tags($html, 'url') as $tag) {
            $name = Attributes::doubleQuoted($tag, 'value') ?? '';
            $default = Attributes::doubleQuoted($tag, 'default') ?? '';
            $given = $name !== '' ? $page->request->param($name) : null;
            if ($given !== null) {
                $value = $given;
                $page->session->set('cal4-data-' . $name, $given);
            } elseif ($name !== '' && is_string($page->session->get('cal4-data-' . $name))) {
                $value = (string) $page->session->get('cal4-data-' . $name);
            } else {
                $value = $default;
            }
            $html = str_replace($tag, Html::escape($value), $html);
        }
        return $html;
    }
}
