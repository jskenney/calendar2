<?php

declare(strict_types=1);

namespace Cal5\Tests;

use RuntimeException;

/**
 * A fixture course from tests/fixtures/<name>/ (see tests/fixtures/README.md).
 *
 * install() copies it to a temporary directory, links "calendar" to the
 * implementation under test (Cal5 or a v4 calendar-core checkout), and creates
 * the extra files and links described in fixture.json.
 */
final class Fixture
{
    public const DIR = __DIR__ . '/../fixtures';

    /** @param array<string, mixed> $meta */
    private function __construct(public readonly string $name, public readonly array $meta)
    {
    }

    public static function load(string $name): self
    {
        $file = self::DIR . '/' . $name . '/fixture.json';
        $meta = json_decode((string) @file_get_contents($file), true);
        if (!is_array($meta)) {
            throw new RuntimeException("Missing or invalid $file");
        }
        return new self($name, $meta);
    }

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];
        foreach (glob(self::DIR . '/*/fixture.json') ?: [] as $file) {
            $names[] = basename(dirname($file));
        }
        sort($names);
        return $names;
    }

    public function sourceDir(): string
    {
        return self::DIR . '/' . $this->name;
    }

    /**
     * Copy the course to a fresh temporary directory and link the implementation.
     * Returns the course directory; remove it with Fixture::remove().
     */
    public function install(string $implementationDir): string
    {
        $root = sys_get_temp_dir() . '/cal5-fixture-' . $this->name . '-' . bin2hex(random_bytes(4));
        $course = $root . '/course';
        mkdir($course, 0777, true);
        self::copyTree($this->sourceDir(), $course, ['fixture.json']);
        if (!symlink((string) realpath($implementationDir), $course . '/calendar')) {
            throw new RuntimeException('Could not link the implementation');
        }
        // Files that must live outside the course directory (symlink confinement tests)
        foreach (($this->meta['outside_files'] ?? []) as $path => $contents) {
            @mkdir(dirname($root . '/outside/' . $path), 0777, true);
            file_put_contents($root . '/outside/' . $path, (string) $contents);
        }
        // Links created at install time: target "@outside/x" points into the outside directory
        foreach (($this->meta['links'] ?? []) as $link => $target) {
            $target = str_replace('@outside', $root . '/outside', (string) $target);
            symlink($target, $course . '/' . $link);
        }
        return $course;
    }

    /**
     * Build a throwaway course from a list of files (path => contents) and link
     * the implementation.  Remove it with Fixture::remove().
     *
     * @param array<string, string> $files
     */
    public static function temporaryCourse(array $files, string $implementationDir): string
    {
        $course = sys_get_temp_dir() . '/cal5-course-' . bin2hex(random_bytes(4)) . '/course';
        mkdir($course, 0777, true);
        foreach ($files as $path => $contents) {
            @mkdir(dirname($course . '/' . $path), 0777, true);
            file_put_contents($course . '/' . $path, $contents);
        }
        symlink((string) realpath($implementationDir), $course . '/calendar');
        return $course;
    }

    public static function remove(string $courseDir): void
    {
        self::removeTree(dirname($courseDir));
    }

    /** @param list<string> $skip names to skip at the top level */
    private static function copyTree(string $from, string $to, array $skip = []): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
                continue;
            }
            $src = $from . '/' . $entry;
            $dst = $to . '/' . $entry;
            if (is_link($src)) {
                symlink((string) readlink($src), $dst);
            } elseif (is_dir($src)) {
                mkdir($dst);
                self::copyTree($src, $dst);
            } else {
                copy($src, $dst);
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
