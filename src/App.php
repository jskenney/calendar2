<?php

declare(strict_types=1);

namespace Cal5;

use Cal5\Config\ConfigSource;
use Cal5\Config\LegacyVariables;
use Cal5\View\InstalledPage;

/**
 * Request lifecycle (see docs/design/architecture.md).
 *
 * Milestone 1: only works out where the configuration comes from and shows an
 * "installed" page.  Later milestones add each step of the lifecycle.
 */
final class App
{
    public const INI_FILE = 'calendar.ini.php';

    /**
     * @param array<string, mixed> $legacyVariables v4 variables, if any
     */
    private function __construct(
        private readonly string $courseDir,
        private readonly string $libraryDir,
        private readonly ConfigSource $source,
        private readonly array $legacyVariables,
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
        $legacy = LegacyVariables::extract($globals);
        return new self($courseDir, $libraryDir, self::detect($courseDir, $legacy), $legacy);
    }

    /** Entry from index.php (new courses). */
    public static function fromEntry(string $courseDir, string $libraryDir): self
    {
        return new self($courseDir, $libraryDir, self::detect($courseDir, []), []);
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

    public function source(): ConfigSource
    {
        return $this->source;
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
            source: $this->source,
            libraryRequest: $this->isLibraryRequest(),
            course: is_string($this->legacyVariables['COURSE'] ?? null) ? $this->legacyVariables['COURSE'] : '',
            courseName: is_string($this->legacyVariables['COURSENAME'] ?? null) ? $this->legacyVariables['COURSENAME'] : '',
            settingsFound: count($this->legacyVariables),
            assetBase: $this->isLibraryRequest() ? '' : 'calendar/',
        );
        echo $page->render();
    }
}
