<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

/** <name ...>content</name>: keep the content, or remove the whole block (v4 regular expressions). */
final class BlockTag
{
    public static function unwrap(string $html, string $name): string
    {
        return (string) preg_replace('/<' . $name . '[^>]*>([\s\S]*?)<\/' . $name . '[^>]*>/', '\1', $html);
    }

    public static function remove(string $html, string $name, string $with = ''): string
    {
        return (string) preg_replace_callback('/<' . $name . '[^>]*>([\s\S]*?)<\/' . $name . '[^>]*>/', static fn (): string => $with, $html);
    }
}
