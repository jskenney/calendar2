<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Render\PageContext;

/** One step of the tag pipeline (docs/design/architecture.md, "Rendering"). */
interface Tag
{
    public function apply(string $html, PageContext $page): string;
}
