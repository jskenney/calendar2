<?php

declare(strict_types=1);

namespace Cal5\Content;

use Cal5\Config\Box;
use Cal5\Config\Config;
use Cal5\Config\Problem;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds every event and file of a course, exactly as v4 (calendar-core 4.6) does:
 *
 *  - event directories of each type in natural order (1, 2, 10), named after the first "."
 *  - every file below an event directory, sorted; names with a hidden part (".x") are skipped
 *  - symbolic links are resolved and must stay inside the course directory or files.allowed_paths
 *  - virtual files are added after the real ones (replacing a real file of the same name in place)
 *  - each box is filled by the first matching file (box_pick) and each file knows its box
 */
final class ContentScanner
{
    /** @var list<Problem> */
    private array $problems = [];
    /** @var list<string> */
    private array $roots = [];
    private string $course = '';

    public function __construct(private readonly Config $config)
    {
    }

    public function scan(string $courseDir): CourseContent
    {
        $this->problems = [];
        $this->course = rtrim((string) realpath($courseDir), '/');
        $this->roots = [$this->course];
        foreach ($this->config->files->allowedPaths as $path) {
            $real = realpath(str_starts_with($path, '/') ? $path : $this->course . '/' . $path);
            if ($real !== false) {
                $this->roots[] = $real;
            } else {
                $this->problems[] = new Problem("allowed path \"$path\" does not exist", 'files.allowed_paths');
            }
        }

        // name => [type, number, files] while building
        $types = [];
        foreach (self::componentTypes($this->config) as $type) {
            $typeDir = $this->course . '/' . $type;
            if (!is_dir($typeDir)) {
                continue;
            }
            $directories = [];
            foreach (scandir($typeDir) ?: [] as $entry) {
                if ($entry[0] !== '.' && is_dir($typeDir . '/' . $entry)) {
                    $directories[] = $entry;
                }
            }
            sort($directories, SORT_NATURAL);
            foreach ($directories as $directory) {
                $types[$type][] = ['directory' => $directory, 'files' => $this->scanEvent($type, $directory)];
            }
        }

        $virtual = VirtualFiles::read($this->config->files->virtual, $this->course, $this->config->files->virtual ?? '');
        $this->problems = array_merge($this->problems, $virtual->problems);
        foreach (self::groupVirtual($virtual->entries) as $entry) {
            $index = $entry['number'] - 1;
            if (!isset($types[$entry['type']][$index])) {
                $this->problems[] = new Problem("virtual file {$entry['name']}: there is no {$entry['type']} {$entry['number']}", $this->config->files->virtual);
                continue;
            }
            $real = realpath(str_starts_with($entry['real'], '/') ? $entry['real'] : $this->course . '/' . $entry['real']);
            if ($real === false || !$this->allowed($real) || !is_file($real)) {
                $this->problems[] = new Problem("virtual file {$entry['name']}: {$entry['real']} is missing or outside the course", $this->config->files->virtual);
                continue;
            }
            $types[$entry['type']][$index]['files'][$entry['name']] = $this->file(
                $entry['name'],
                $real,
                FileKey::forVirtual($this->config->security->secret, $entry['real'], $entry['name']),
                [$entry['real'], $entry['name']],
                $entry['real'],
            );
        }

        $result = [];
        foreach ($types as $type => $events) {
            foreach ($events as $index => $event) {
                $result[$type][] = new EventFolder(
                    $type,
                    $index + 1,
                    $event['directory'],
                    EventFolder::nameOf($event['directory']),
                    $event['files'],
                    $this->boxes($event['files']),
                );
            }
        }
        return new CourseContent($result, $this->problems);
    }

    /**
     * Directories that may hold events: every type named in the schedule (v4 $COMPONENTS).
     *
     * @return list<string>
     */
    public static function componentTypes(Config $config): array
    {
        $types = [];
        foreach ([$config->schedule->weekly, $config->schedule->days] as $plans) {
            foreach ($plans as $plan) {
                foreach ($plan->entries as $entry) {
                    $types[$entry->type] = true;
                }
            }
        }
        foreach ($config->schedule->combine as $rule) {
            $types[$rule->type] = true;
            foreach ($rule->add as $type) {
                $types[$type] = true;
            }
        }
        return array_keys($types);
    }

    /** @return array<string, ContentFile> */
    private function scanEvent(string $type, string $directory): array
    {
        $prefix = $this->course . '/' . $type . '/' . $directory . '/';
        $names = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($prefix, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $path => $info) {
                $names[] = substr((string) $path, strlen($prefix));
            }
        } catch (\Exception) {
            // unreadable directory: no files (v4 behaviour)
        }
        sort($names);

        $files = [];
        foreach ($names as $name) {
            if ($name === '' || preg_match('#(^|/)\.#', $name)) {
                continue;
            }
            $full = $prefix . $name;
            $real = realpath($full);
            if ($real === false || !$this->allowed($real)) {
                if (is_link($full)) {
                    $this->problems[] = new Problem("$type/$directory/$name points outside the course and is ignored");
                }
                continue;
            }
            if (!is_file($real)) {
                continue;
            }
            $files[$name] = $this->file(
                $name,
                $real,
                FileKey::forFile($this->config->security->secret, $type, $directory, $name),
                [$name],
            );
        }
        return $files;
    }

    /**
     * @param list<string> $matchNames names to look up in the box candidates, in order (v4: real file first, then the name)
     */
    private function file(string $name, string $real, string $key, array $matchNames, ?string $virtualSource = null): ContentFile
    {
        [$box, $inline] = $this->boxOf($matchNames);
        return new ContentFile($name, $real, $key, FileKind::fromPath($name), $box, $inline, $virtualSource);
    }

    /**
     * The box a file belongs to: the first box listing one of its names.
     *
     * @param list<string> $names
     * @return array{0: ?string, 1: bool}
     */
    private function boxOf(array $names): array
    {
        foreach ($names as $name) {
            foreach ($this->config->boxes as $box) {
                foreach ($box->candidates as $candidate) {
                    if ($candidate->file === $name) {
                        return [$box->name, $candidate->inline];
                    }
                }
            }
        }
        return [null, false];
    }

    /**
     * Which file fills each box of an event.
     *
     * @param array<string, ContentFile> $files
     * @return array<string, string>
     */
    private function boxes(array $files): array
    {
        $boxes = [];
        if ($this->config->display->boxPick === 'first-by-name') {
            // v4: the first file (in file order) that belongs to a box fills it
            foreach ($files as $name => $file) {
                if ($file->box !== null && !isset($boxes[$file->box])) {
                    $boxes[$file->box] = $name;
                }
            }
            return $boxes;
        }
        foreach ($this->config->boxes as $box) {
            $chosen = self::firstListed($box, $files);
            if ($chosen !== null) {
                $boxes[$box->name] = $chosen;
            }
        }
        return $boxes;
    }

    /** @param array<string, ContentFile> $files */
    private static function firstListed(Box $box, array $files): ?string
    {
        foreach ($box->candidates as $candidate) {
            if (isset($files[$candidate->file])) {
                return $candidate->file;
            }
        }
        return null;
    }

    private function allowed(string $real): bool
    {
        foreach ($this->roots as $root) {
            if ($real === $root || str_starts_with($real, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * v4 applies virtual files grouped by type, then by event number, in order of first appearance.
     *
     * @param list<array{type: string, number: int, name: string, real: string}> $entries
     * @return list<array{type: string, number: int, name: string, real: string}>
     */
    private static function groupVirtual(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry['type']][$entry['number']][] = $entry;
        }
        $ordered = [];
        foreach ($grouped as $byNumber) {
            foreach ($byNumber as $list) {
                foreach ($list as $entry) {
                    $ordered[] = $entry;
                }
            }
        }
        return $ordered;
    }
}
