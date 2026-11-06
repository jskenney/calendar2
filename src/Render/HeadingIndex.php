<?php

declare(strict_types=1);

namespace Cal5\Render;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The page's section menu (v4 findheaders): headings h1-h6 with an id, and old
 * style <a name="..."> anchors (reported to the instructor, who can move to ids).
 */
final class HeadingIndex
{
    /**
     * @param list<string> $anchors ids / names, in page order
     * @param list<array{tag: string, name: string}> $oldAnchors <a name> style anchors
     */
    private function __construct(public readonly array $anchors, public readonly array $oldAnchors)
    {
    }

    public static function find(string $html): self
    {
        if (trim($html) === '') {
            return new self([], []);
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $anchors = [];
        $old = [];
        $lastName = null;
        $nodes = (new DOMXPath($dom))->query('//h1|//h2|//h3|//h4|//h5|//h6|//a');
        foreach ($nodes === false ? [] : $nodes as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            foreach ($element->attributes as $attribute) {
                if ($attribute->name === 'name') {
                    $lastName = $attribute->value;
                    $old[] = ['tag' => $element->tagName, 'name' => $attribute->value];
                    $anchors[] = $attribute->value;
                } elseif ($attribute->name === 'id' && $element->tagName !== 'a' && $attribute->value !== $lastName) {
                    $anchors[] = $attribute->value;
                }
            }
        }
        return new self($anchors, $old);
    }

    /**
     * Menu entries: anchor => label ("part-one" -> "Part one"); v4 skips "pagename:" anchors.
     *
     * @return array<string, string>
     */
    public function menu(): array
    {
        $menu = [];
        foreach ($this->anchors as $anchor) {
            if (!str_contains($anchor, 'pagename:')) {
                $menu[$anchor] = str_replace('-', ' ', ucfirst($anchor));
            }
        }
        return $menu;
    }
}
