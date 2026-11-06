<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\AccessPolicy;
use Cal5\Auth\AuthResult;
use Cal5\Config\ConfigSource;
use Cal5\Config\LoadResult;
use Cal5\Course;
use Cal5\Support\Html;
use Cal5\Version;

/**
 * Placeholder for the pages that do not exist yet (home page and calendar
 * views, milestones 7-9): confirms Cal5 is
 * installed and summarises the configuration it loaded.  It shows counts of
 * problems, not their text, and never shows passwords, secrets or file paths.
 */
final class InstalledPage
{
    public function __construct(
        private readonly LoadResult $result,
        private readonly ?Course $course,
        private readonly bool $libraryRequest,
        private readonly string $assetBase,
        private readonly ?AuthResult $auth = null,
        private readonly ?string $csrf = null,
        private readonly bool $locked = false,
    ) {
    }

    public function render(): string
    {
        $version = Html::escape(Version::VERSION);
        $css = Html::escape($this->assetBase . 'public/css/cal5.css');
        $icon = Html::escape($this->assetBase . 'images/web-icon.png');
        $source = $this->result->source;
        $config = $this->result->config;

        if ($this->libraryRequest) {
            $title = 'Cal5 library';
            $body = '<p>This directory is the Cal5 library. Courses use it through their own '
                . '<code>calendar.php</code> (v4 courses) or <code>index.php</code> (new courses).</p>';
        } elseif ($source === ConfigSource::None) {
            $title = 'Set up this course';
            $body = '<p>No configuration was found. Create <code>calendar.ini.php</code> in the course '
                . 'directory, or keep using a v4 <code>calendar.php</code>.</p>';
        } else {
            $from = $source === ConfigSource::Legacy
                ? 'a <strong>v4 configuration</strong> (<code>calendar.php</code>), translated automatically'
                : '<code>calendar.ini.php</code>';
            $title = $config !== null
                ? trim($config->course->number . ' ' . $config->course->name)
                : 'Configuration problem';
            $body = "<p>Configuration: $from.</p>";
            if ($config !== null) {
                $body .= '<dl class="cal5-summary">'
                    . '<dt>Semester</dt><dd>' . Html::escape($config->semester->start . ' to ' . $config->semester->end) . '</dd>'
                    . '<dt>Weekly schedule</dt><dd>' . Html::escape(self::weekly($config->schedule->weekly)) . '</dd>'
                    . '<dt>Special days</dt><dd>' . count($config->schedule->days) . '</dd>'
                    . '<dt>Boxes</dt><dd>' . Html::escape(implode(', ', array_map(static fn ($b) => $b->name, $config->boxes))) . '</dd>'
                    . self::contentSummary($this->course)
                    . '</dl>';
            }
            $errors = count($this->result->errors);
            $warnings = count($this->result->warnings) + count($this->course?->problems() ?? [])
                + ($this->course !== null ? count(AccessPolicy::forCourse($this->course)->problems()) : 0);
            if ($errors > 0 || $warnings > 0) {
                $body .= '<p class="cal5-problems">' . $errors . ' error(s) and ' . $warnings . ' warning(s): run '
                    . '<code>php calendar/bin/cal5 check</code> in the course directory to see them.</p>';
            }
        }

        $body .= $this->login();

        $title = Html::escape($title);
        $sourceValue = Html::escape($source->value);
        $instructor = $this->auth?->instructor ? 'yes' : 'no';
        $state = $config !== null ? 'ok' : ($source === ConfigSource::None ? 'none' : 'error');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{$title}</title>
  <link rel="icon" href="{$icon}">
  <link rel="stylesheet" href="{$css}">
</head>
<body>
  <main class="cal5-installed" data-cal5-version="{$version}" data-config-source="{$sourceValue}" data-config-state="{$state}" data-instructor="{$instructor}">
    <h1>{$title}</h1>
    {$body}
    <p class="cal5-note">Cal5 {$version} is installed.</p>
  </main>
</body>
</html>

HTML;
    }

    /** Login state and the login / logout form (no secrets; the nonce is public by design). */
    private function login(): string
    {
        if ($this->auth === null) {
            return '';
        }
        $csrf = Html::escape((string) $this->csrf);
        $html = '';
        if ($this->locked && !$this->auth->instructor) {
            $html .= '<p class="cal5-locked">This course site is locked.</p>';
        }
        $message = match ($this->auth->event) {
            AuthResult::FAILED => '<p class="cal5-problems">The password was not accepted.</p>',
            AuthResult::BLOCKED => '<p class="cal5-problems">Too many failed logins; try again in 15 minutes.</p>',
            default => '',
        };
        if ($this->auth->instructor) {
            return $html . '<form method="post" class="cal5-login"><p>Logged in as the instructor.</p>'
                . '<input type="hidden" name="password" value="">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<button type="submit">Log out</button></form>';
        }
        $nonce = Html::escape((string) $this->auth->nonce);
        return $html . $message . '<form method="post" class="cal5-login" data-nonce="' . $nonce . '">'
            . '<label>Instructor password <input type="password" name="password" autocomplete="current-password"></label>'
            . '<input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<button type="submit">Log in</button></form>';
    }

    private static function contentSummary(?Course $course): string
    {
        if ($course === null) {
            return '';
        }
        $parts = [];
        $scheduled = 0;
        foreach ($course->content->types as $type => $events) {
            $parts[] = count($events) . ' ' . $type;
            foreach ($events as $event) {
                if ($course->schedule->dateOf($type, $event->number) !== null) {
                    $scheduled++;
                }
            }
        }
        $total = array_sum(array_map('count', $course->content->types));
        $policy = AccessPolicy::forCourse($course);
        $files = 0;
        $visible = 0;
        foreach ($course->content->types as $events) {
            foreach ($events as $event) {
                foreach ($event->files as $file) {
                    $files++;
                    if ($policy->visibleToStudents($event, $file)) {
                        $visible++;
                    }
                }
            }
        }
        return '<dt>Events</dt><dd>' . Html::escape($parts === [] ? 'none yet' : implode(', ', $parts)) . '</dd>'
            . '<dt>On the calendar</dt><dd>' . $scheduled . ' of ' . $total . ' events, on ' . count($course->schedule->days) . ' days</dd>'
            . '<dt>Students can see</dt><dd>' . $visible . ' of ' . $files . ' files now</dd>';
    }

    /** @param array<string, \Cal5\Config\DayPlan> $weekly */
    private static function weekly(array $weekly): string
    {
        $parts = [];
        foreach ($weekly as $day => $plan) {
            $parts[] = $day . ' ' . $plan;
        }
        return $parts === [] ? 'none' : implode(', ', $parts);
    }
}
