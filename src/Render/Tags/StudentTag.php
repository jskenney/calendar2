<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Render\PageContext;

/** <student>: answers, hidden until ?answers is given (a convenience, not a security control). */
final class StudentTag implements Tag
{
    public function apply(string $html, PageContext $page): string
    {
        if (!$page->request->has('answers')) {
            $html = BlockTag::remove($html, 'student');
        }
        return str_replace(['<student>', '</student>'], '', $html);
    }
}
