<?php

declare(strict_types=1);

namespace Cal5\Content;

/** One event of a type, e.g. class 3: its directory, name and files. */
final class EventFolder
{
    /**
     * @param array<string, ContentFile> $files file name => file, in v4 order (sorted names, then virtual files)
     * @param array<string, string> $boxes box name => file name that fills it
     */
    public function __construct(
        public readonly string $type,
        public readonly int $number,
        public readonly string $directory,
        public readonly string $name,
        public readonly array $files,
        public readonly array $boxes,
    ) {
    }

    /** "01.Intro" -> "Intro"; "01.Intro.Part2" -> "Intro.Part2"; "Intro" -> "Intro" */
    public static function nameOf(string $directory): string
    {
        $parts = explode('.', $directory, 2);
        return $parts[1] ?? $parts[0];
    }

    public function file(string $name): ?ContentFile
    {
        return $this->files[$name] ?? null;
    }

    public function boxFile(string $box): ?ContentFile
    {
        return isset($this->boxes[$box]) ? ($this->files[$this->boxes[$box]] ?? null) : null;
    }

    public function fileByKey(string $key): ?ContentFile
    {
        foreach ($this->files as $file) {
            if ($file->key === $key) {
                return $file;
            }
        }
        return null;
    }
}
