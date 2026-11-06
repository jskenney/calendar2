<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Render\PageContext;

/** <inst>: instructor only. */
final class InstTag implements Tag
{
    public function apply(string $html, PageContext $page): string
    {
        return $page->instructor ? BlockTag::unwrap($html, 'inst') : BlockTag::remove($html, 'inst');
    }
}
