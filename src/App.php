<?php

declare(strict_types=1);

namespace Cal5;

use Cal5\Access\AccessPolicy;
use Cal5\Access\SystemClock;
use Cal5\Auth\Csrf;
use Cal5\Auth\InstructorAuth;
use Cal5\Auth\LockCodes;
use Cal5\Auth\LockMode;
use Cal5\Auth\PhpSession;
use Cal5\Auth\RateLimiter;
use Cal5\Config\ConfigLoader;
use Cal5\Content\ContentScanner;
use Cal5\Course;
use Cal5\Config\ConfigSource;
use Cal5\Config\LegacyVariables;
use Cal5\Config\LoadResult;
use Cal5\Http\PageRequest;
use Cal5\Http\Request;
use Cal5\View\EventPage;
use Cal5\View\InstalledPage;

/**
 * Request lifecycle (see docs/design/architecture.md).
 *
 * Milestone 6: loads the configuration (calendar.ini.php or a translated v4
 * calendar.php), reads the course content, builds the calendar, logs the
 * instructor in (v4 compatible), and serves event pages and their files
 * (View\EventPage).  Other requests still get the "installed" summary page
 * until the home page, calendar views and search arrive (milestones 7-9).
 */
final class App
{
    public const INI_FILE = ConfigLoader::INI_FILE;

    private function __construct(
        private readonly string $courseDir,
        private readonly string $libraryDir,
        private readonly LoadResult $config,
        /** @var array<string, mixed> v4 settings, available to <calphp> scripts as in v4 */
        private readonly array $legacyVariables = [],
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
        // v4 removed the password and the secret before running any course code
        $variables = array_diff_key($globals, array_flip(['ADMIN', 'SECRET', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_REQUEST', '_ENV', '_SESSION', 'cal5GlobalVariables']));
        return new self($courseDir, $libraryDir, ConfigLoader::forCourse($courseDir, $globals), $variables);
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

    /**
     * The script links point to: calendar.php for v4 courses (their links, bookmarks and
     * pages all use it), otherwise the script that was requested (index.php).
     */
    private function entryName(): string
    {
        if ($this->config->source === ConfigSource::Legacy) {
            return 'calendar.php';
        }
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        return preg_match('/^[A-Za-z0-9_.-]+\.php$/', $script) ? $script : 'index.php';
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

            $page = PageRequest::from($request, ContentScanner::componentTypes($config));
            if ($course !== null && $this->config->ok() && $page->isEvent() && !($locked && !$auth->instructor)) {
                $policy = AccessPolicy::forCourse($course);
                (new EventPage($course, $policy, $auth, $request, $session, $csrf, $this->entryName(), 'calendar/', $this->legacyVariables))->handle($page);
                return;
            }
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
