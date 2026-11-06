<?php

declare(strict_types=1);

namespace Cal5;

use Cal5\Config\ConfigLoader;
use Cal5\Config\ConfigSource;
use Cal5\Config\LegacyVariables;
use Cal5\Config\LoadResult;
use Cal5\View\InstalledPage;

/**
 * Request lifecycle (see docs/design/architecture.md).
 *
 * Milestone 2: loads the configuration (calendar.ini.php or a translated v4
 * calendar.php) and shows an "installed" page summarising it.  Later
 * milestones add the remaining steps of the lifecycle.
 */
final class App
{
    public const INI_FILE = ConfigLoader::INI_FILE;

    private function __construct(
        private readonly string $courseDir,
        private readonly string $libraryDir,
        private readonly LoadResult $config,
    ) {
    }

    /**
     * Entry from a v4 course: calendar.php set its variables and then included
     * calendar_main.php, which passes the global variables here.
     *
     * @param array<string, mixed> $globals
     */
    public static function fromLegacyGlobals(array $globals, string $courseDir, string $libraryDir): self
    {
        return new self($courseDir, $libraryDir, ConfigLoader::forCourse($courseDir, $globals));
    }

    /** Entry from index.php (new courses). */
    public static function fromEntry(string $courseDir, string $libraryDir): self
    {
        $library = realpath($libraryDir);
        $result = ($library !== false && realpath($courseDir) === $library)
            ? new LoadResult(ConfigSource::None, null, [], [])
            : ConfigLoader::forCourse($courseDir);
        return new self($courseDir, $libraryDir, $result);
    }

    /**
     * calendar.ini.php wins; otherwise a v4 configuration; otherwise nothing.
     *
     * @param array<string, mixed> $legacy
     */
    public static function detect(string $courseDir, array $legacy): ConfigSource
    {
        if (is_file($courseDir . '/' . self::INI_FILE)) {
            return ConfigSource::Ini;
        }
        if (LegacyVariables::looksLikeV4($legacy)) {
            return ConfigSource::Legacy;
        }
        return ConfigSource::None;
    }

    public function configuration(): LoadResult
    {
        return $this->config;
    }

    /** True when the library directory itself was requested (not a course). */
    public function isLibraryRequest(): bool
    {
        $course = realpath($this->courseDir);
        $library = realpath($this->libraryDir);
        return $course !== false && $course === $library;
    }

    public function run(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: object-src 'none'; base-uri 'self'");
        }
        $page = new InstalledPage(
            result: $this->config,
            libraryRequest: $this->isLibraryRequest(),
            assetBase: $this->isLibraryRequest() ? '' : 'calendar/',
        );
        echo $page->render();
    }
}
