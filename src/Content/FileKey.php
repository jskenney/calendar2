<?php

declare(strict_types=1);

namespace Cal5\Content;

/**
 * File keys used in ?key= links.  The formulas are v4's, so every existing
 * link and bookmark keeps working when a course moves to Cal5.
 */
final class FileKey
{
    /** A file inside an event directory: sha1(secret . type . directory . path) */
    public static function forFile(string $secret, string $type, string $directory, string $path): string
    {
        return sha1($secret . $type . $directory . $path);
    }

    /** A virtual file: sha1(secret . real file . virtual name) */
    public static function forVirtual(string $secret, string $realFile, string $virtualName): string
    {
        return sha1($secret . $realFile . $virtualName);
    }
}
