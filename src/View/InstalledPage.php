<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Config\ConfigSource;
use Cal5\Support\Html;
use Cal5\Version;

/**
 * Milestone 1 placeholder page: confirms Cal5 is installed and which kind of
 * configuration it found.  Never shows passwords, secrets or file paths.
 */
final class InstalledPage
{
    public function __construct(
        private readonly ConfigSource $source,
        private readonly bool $libraryRequest,
        private readonly string $course,
        private readonly string $courseName,
        private readonly int $settingsFound,
        private readonly string $assetBase,
    ) {
    }

    public function render(): string
    {
        $version = Html::escape(Version::VERSION);
        $css = Html::escape($this->assetBase . 'public/css/cal5.css');
        $icon = Html::escape($this->assetBase . 'images/web-icon.png');

        if ($this->libraryRequest) {
            $title = 'Cal5 library';
            $body = '<p>This directory is the Cal5 library. Courses use it through their own '
                . '<code>calendar.php</code> (v4 courses) or <code>index.php</code> (new courses).</p>';
        } else {
            [$title, $body] = match ($this->source) {
                ConfigSource::Legacy => [
                    trim($this->course . ' ' . $this->courseName),
                    '<p>Found a <strong>v4 configuration</strong> (<code>calendar.php</code>, '
                    . $this->settingsFound . ' settings). It will be translated automatically.</p>',
                ],
                ConfigSource::Ini => [
                    'Cal5 course',
                    '<p>Found <code>calendar.ini.php</code>.</p>',
                ],
                ConfigSource::None => [
                    'Set up this course',
                    '<p>No configuration was found. Create <code>calendar.ini.php</code> in the course '
                    . 'directory, or keep using a v4 <code>calendar.php</code>.</p>',
                ],
            };
        }

        $title = Html::escape($title);
        $source = Html::escape($this->source->value);

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
  <main class="cal5-installed" data-cal5-version="{$version}" data-config-source="{$source}">
    <h1>{$title}</h1>
    {$body}
    <p class="cal5-note">Cal5 {$version} is installed. This development build (milestone 1)
    does not serve course content yet.</p>
  </main>
</body>
</html>

HTML;
    }
}
