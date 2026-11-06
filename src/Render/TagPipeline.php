<?php

declare(strict_types=1);

namespace Cal5\Render;

use Cal5\Render\Tags\AceTag;
use Cal5\Render\Tags\HostTag;
use Cal5\Render\Tags\InjectTag;
use Cal5\Render\Tags\InstTag;
use Cal5\Render\Tags\LinkRewriter;
use Cal5\Render\Tags\LockTag;
use Cal5\Render\Tags\PostMeetingTag;
use Cal5\Render\Tags\ReplaceTag;
use Cal5\Render\Tags\StudentTag;
use Cal5\Render\Tags\UrlTag;

/**
 * Processes the Cal4 tags of a page in v4's order (docs/design/architecture.md):
 *
 *   link rewriting -> replace -> inject -> codeinject   (up to 5 passes, so injected
 *                                                       files are processed too)
 *   -> postmeeting -> inst -> host -> lock -> student -> tabs -> ace -> url
 *   -> calphp (runs the scripts)
 */
final class TagPipeline
{
    public const PASSES = 5;

    public function __construct(
        private readonly string $serverName = '',
        private readonly string $serverAddress = '',
    ) {
    }

    public function process(string $html, PageContext $page): RenderedContent
    {
        $repeat = [new LinkRewriter(), new ReplaceTag(), new InjectTag(false), new InjectTag(true)];
        for ($pass = 0; $pass < self::PASSES; $pass++) {
            $before = $html;
            foreach ($repeat as $tag) {
                $html = $tag->apply($html, $page);
            }
            if ($html === $before) {
                break; // nothing left to do (v4 always ran 5 passes; the result is the same)
            }
        }
        foreach ([new PostMeetingTag(), new InstTag(), new HostTag($this->serverName, $this->serverAddress), new LockTag()] as $tag) {
            $html = $tag->apply($html, $page);
        }
        $hasAnswers = str_contains($html, '<student>');
        $html = (new StudentTag())->apply($html, $page);
        $html = str_replace("\t", '  ', $html); // v4: tabs become two spaces
        $ace = new AceTag();
        $html = $ace->apply($html, $page);
        $html = (new UrlTag())->apply($html, $page);
        $headings = HeadingIndex::find($html);
        $html = CalphpRunner::run($html, $page);
        return new RenderedContent($html, $hasAnswers, $ace->used, $headings);
    }
}
