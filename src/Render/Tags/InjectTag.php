<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Content\FileKind;
use Cal5\Render\Markdown;
use Cal5\Render\PageContext;

/**
 * <inject src="file"> inserts another file of the event as it is (Markdown is
 * converted); <codeinject src="file"> inserts it escaped, for showing code.
 */
final class InjectTag implements Tag
{
    public function __construct(private readonly bool $code = false)
    {
    }

    public function apply(string $html, PageContext $page): string
    {
        $name = $this->code ? 'codeinject' : 'inject';
        foreach (Attributes::tags($html, $name) as $tag) {
            $src = Attributes::quoted($tag, 'src');
            if ($src === null || $src === '' || !isset($page->files[$src])) {
                continue;
            }
            $file = $page->files[$src];
            $data = (string) @file_get_contents($file->actualPath);
            if ($this->code) {
                $data = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $data);
            } elseif ($file->kind === FileKind::Markdown || FileKind::fromPath($file->actualPath) === FileKind::Markdown) {
                $data = Markdown::convert($data, $page->config->display->markdownMenuLevels);
            }
            $html = Attributes::replaceForms($html, $name, 'src', $src, $data);
        }
        return $html;
    }
}
