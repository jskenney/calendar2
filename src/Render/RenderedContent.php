<?php

declare(strict_types=1);

namespace Cal5\Render;

/** A page's content after the tag pipeline. */
final class RenderedContent
{
    public function __construct(
        public readonly string $html,
        /** The page has <student> answers (the navbar offers to show / hide them) */
        public readonly bool $hasAnswers,
        /** The page uses <ace> (load the Ace editor) */
        public readonly bool $usesAce,
        public readonly HeadingIndex $headings,
    ) {
    }
}
