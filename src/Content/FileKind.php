<?php

declare(strict_types=1);

namespace Cal5\Content;

/** How a file is presented, decided by its extension (docs/design/architecture.md, "Rendering"). */
enum FileKind: string
{
    /** .html: rendered inside the layout */
    case Html = 'html';
    /** .htm: rendered without the layout (v4 "nocss") */
    case Htm = 'htm';
    /** .md: converted to HTML, then rendered */
    case Markdown = 'markdown';
    /** .txt: shown as escaped text inside the layout */
    case Text = 'text';
    /** .pdf: sent inline (the browser's PDF viewer) */
    case Pdf = 'pdf';
    /** .ppt, .pptx and anything else: sent as a download */
    case Download = 'download';

    public static function fromPath(string $path): self
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => self::Html,
            'htm' => self::Htm,
            'md' => self::Markdown,
            'txt' => self::Text,
            'pdf' => self::Pdf,
            default => self::Download,
        };
    }

    /** Shown as a page (as opposed to a file transfer). */
    public function isPage(): bool
    {
        return match ($this) {
            self::Html, self::Htm, self::Markdown, self::Text => true,
            self::Pdf, self::Download => false,
        };
    }
}
