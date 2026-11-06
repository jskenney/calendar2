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
use Cal5\Config\ConfigChecks;
use Cal5\Config\ConfigLoader;
use Cal5\Content\ContentScanner;
use Cal5\Course;
use Cal5\Config\ConfigSource;
use Cal5\Config\LegacyVariables;
use Cal5\Config\LoadResult;
use Cal5\Auth\AuthResult;
use Cal5\Http\AccessLog;
use Cal5\Http\Request;
use Cal5\Http\Route;
use Cal5\Http\Router;
use Cal5\View\DebugView;
use Cal5\View\EventPage;
use Cal5\View\InstalledPage;
use Cal5\View\SitePage;

/**
 * Request lifecycle (see docs/design/architecture.md).
 *
 * Loads the configuration (calendar.ini.php or a translated v4 calendar.php),
 * reads the course content, builds the calendar, logs the instructor in (v4
 * compatible), writes the access log, and lets the Router decide what the request
 * shows: an event page or file (View\EventPage), or any other page (View\SitePage).
 * Without a configuration, the set-up page (View\InstalledPage).
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
        // a configuration that fails a check still shows its error page (Router), with a random secret
        $config = $this->config->config ?? $this->config->draft;
        if ($config === null || $this->isLibraryRequest()) {
            // no configuration (or the library itself): the set-up page
            echo (new InstalledPage(result: $this->config, course: null, libraryRequest: $this->isLibraryRequest(),
                assetBase: $this->isLibraryRequest() ? '' : 'calendar/'))->render();
            return;
        }
        $course = Course::load($this->courseDir, $config);
        $request = Request::fromGlobals();
        $session = PhpSession::start($request->isHttps());
        $realAuth = (new InstructorAuth($config, $session, $request, new SystemClock(), RateLimiter::forCourse($this->courseDir)))->handle();
        foreach ($realAuth->cookies as $cookie) {
            $cookie->send($request->isHttps());
        }
        LockCodes::remember($request, $session);
        $csrf = Csrf::token($session);
        $locked = LockMode::locked($config, $this->courseDir);
        $policy = AccessPolicy::forCourse($course);
        AccessLog::write($config->features->accessLog, $this->courseDir, $request, $policy->now());

        // the instructor can look at any page as a student would see it now (?as=student)
        $auth = $realAuth;
        $preview = null;
        if ($realAuth->instructor && $request->param('as') === 'student') {
            $auth = new AuthResult(false, [], null, null);
            $preview = $this->withoutParameter($request, 'as');
        }

        $route = Router::route(
            $request,
            ContentScanner::componentTypes($config),
            $auth->instructor,
            $locked,
            $this->error($policy),
            $config->display->startPage,
            static function () use ($course, $policy, $auth, $request, $session, $csrf): ?array {
                $folder = (new SitePage($course, $policy, $auth, $request, $session, $csrf, '', '', ''))->todaysEvent();
                return $folder === null ? null : [$folder->type, $folder->number];
            },
        );

        // shown to the instructor on every page (v4 showed its configuration warnings the same way)
        $warnings = array_map('strval', array_merge($this->config->errors,
            array_filter($this->config->warnings, static fn ($p): bool => $p->message !== ConfigChecks::PLAIN_TEXT_ADVICE),
            $course->problems()));
        if (AccessLog::insideWebRoot($config->features->accessLog, $this->courseDir, $request->server('DOCUMENT_ROOT'))) {
            $warnings[] = 'The access log is inside the web root and may be downloadable; move it outside of the web root.';
        }
        $debug = DebugView::enabled($this->config, $realAuth->instructor, $request)
            ? DebugView::render($course, $policy, $this->config, $request, $session, $route->page, $route->describe())
            : '';

        if ($route->kind === Route::EVENT) {
            (new EventPage($course, $policy, $auth, $request, $session, $csrf, $this->entryName(), 'calendar/', $this->legacyVariables,
                $warnings, $debug, $preview))->handle($route->page);
            return;
        }
        (new SitePage($course, $policy, $auth, $request, $session, $csrf, $this->entryName(), 'calendar/', $this->libraryDir,
            $locked, $warnings, $debug, $route->replace, $preview))->handle($route->page, $route->load, $route->show);
    }

    /**
     * Why the course can not run, as v4's error page says it: the configuration has errors
     * (for example the published sample secret), or the access file can not be read.
     *
     * @return array{0: string, 1: string}|null
     */
    private function error(AccessPolicy $policy): ?array
    {
        if (!$this->config->ok()) {
            return ['This course site is not set up correctly.',
                'The instructor can run "php calendar/bin/cal5 check" in the course directory to see what needs to be fixed.'];
        }
        if ($policy->unreadable()) {
            return ['The release rules can not be read.',
                'The access file exists but can not be opened, so as a precaution the calendar shows no content until this is corrected.'];
        }
        return null;
    }

    /** The current address without one parameter (the way back from ?as=student). */
    private function withoutParameter(Request $request, string $name): string
    {
        $query = (string) parse_url($request->server('REQUEST_URI'), PHP_URL_QUERY);
        parse_str($query, $params);
        unset($params[$name]);
        return $this->entryName() . ($params === [] ? '' : '?' . http_build_query($params));
    }
}
