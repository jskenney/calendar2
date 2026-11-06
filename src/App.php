<?php

declare(strict_types=1);

namespace Cal5;

use Cal5\Access\SystemClock;
use Cal5\Auth\Csrf;
use Cal5\Auth\InstructorAuth;
use Cal5\Auth\LockCodes;
use Cal5\Auth\LockMode;
use Cal5\Auth\PhpSession;
use Cal5\Auth\RateLimiter;
use Cal5\Config\ConfigLoader;
use Cal5\Course;
use Cal5\Config\ConfigSource;
use Cal5\Config\LegacyVariables;
use Cal5\Config\LoadResult;
use Cal5\Http\Request;
use Cal5\View\InstalledPage;

/**
 * Request lifecycle (see docs/design/architecture.md).
 *
 * Milestone 5: loads the configuration (calendar.ini.php or a translated v4
 * calendar.php), reads the course content, builds the calendar, logs the
 * instructor in (v4 compatible), and shows an "installed" page summarising them.  Later milestones add the remaining
 * steps of the lifecycle.
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
        $config = $this->config->config;
        $course = $config !== null && !$this->isLibraryRequest()
            ? Course::load($this->courseDir, $config)
            : null;

        $auth = null;
        $csrf = null;
        $locked = false;
        if ($config !== null && !$this->isLibraryRequest()) {
            $request = Request::fromGlobals();
            $session = PhpSession::start($request->isHttps());
            $auth = (new InstructorAuth($config, $session, $request, new SystemClock(), RateLimiter::forCourse($this->courseDir)))->handle();
            foreach ($auth->cookies as $cookie) {
                $cookie->send($request->isHttps());
            }
            LockCodes::remember($request, $session);
            $csrf = Csrf::token($session);
            $locked = LockMode::locked($config, $this->courseDir);
        }

        $page = new InstalledPage(
            result: $this->config,
            course: $course,
            libraryRequest: $this->isLibraryRequest(),
            assetBase: $this->isLibraryRequest() ? '' : 'calendar/',
            auth: $auth,
            csrf: $csrf,
            locked: $locked,
        );
        echo $page->render();
    }
}
