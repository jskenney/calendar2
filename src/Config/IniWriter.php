<?php

declare(strict_types=1);

namespace Cal5\Config;

use Cal5\Version;

/**
 * Writes a Config as calendar.ini.php.  Loading the result gives an identical
 * Config (the "translate round trip", tested in tests/unit/RoundTripTest.php).
 */
final class IniWriter
{
    /** @var list<string> */
    private array $problems = [];

    /** @return list<string> settings that could not be written exactly */
    public function problems(): array
    {
        return $this->problems;
    }

    public function write(Config $config, ?string $passwordHash = null): string
    {
        $this->problems = [];
        $c = $config;
        $out = [];
        $out[] = ';<?php http_response_code(404); exit; ?>';
        $out[] = '; Cal5 course configuration (written by Cal5 ' . Version::VERSION . ').';
        $out[] = '; Reference: docs/design/configuration.md in the Cal5 repository.';
        $out[] = '; Lines starting with ; are comments.  Delete this file to go back to calendar.php.';

        $this->section($out, 'course');
        $this->pair($out, 'number', $c->course->number);
        $this->pair($out, 'name', $c->course->name);
        $this->pair($out, 'full_name', $c->course->fullName);
        $this->pair($out, 'title', $c->course->title);
        $this->pair($out, 'timezone', $c->course->timezone);

        $this->section($out, 'semester');
        $this->pair($out, 'start', $c->semester->start);
        $this->pair($out, 'end', $c->semester->end);
        $this->pair($out, 'weekends', self::yes($c->semester->weekends));
        if ($c->semester->showFrom !== null) {
            $this->pair($out, 'show_from', $c->semester->showFrom);
        }
        if ($c->semester->showUntil !== null) {
            $this->pair($out, 'show_until', $c->semester->showUntil);
        }

        $this->section($out, 'schedule', 'weekday = type [meeting time HH:MM-HH:MM]');
        foreach ($c->schedule->weekly as $day => $plan) {
            $this->pair($out, $day, (string) $plan);
        }

        $this->section($out, 'days', 'date = what happens that day (replaces the weekly schedule)');
        foreach ($c->schedule->days as $date => $plan) {
            $this->pair($out, $date, (string) $plan);
        }

        if ($c->schedule->combine !== []) {
            $this->section($out, 'combine', 'type number = extra types added to that event');
            foreach ($c->schedule->combine as $rule) {
                $this->pair($out, $rule->type . ' ' . $rule->number, $this->joinList($rule->add, 'combine'));
            }
        }

        $this->section($out, 'boxes', 'box = candidate files (inline: pastes a text file into the calendar box)');
        foreach ($c->boxes as $box) {
            $this->pair($out, $box->name, $this->joinList(array_map('strval', $box->candidates), "box {$box->name}"));
        }

        $this->section($out, 'menus', 'type = label | icon');
        foreach ($c->menus as $menu) {
            $this->pair($out, $menu->type, (string) $menu);
        }
        if ($c->instructorMenus !== null) {
            $this->section($out, 'instructor_menus');
            foreach ($c->instructorMenus as $menu) {
                $this->pair($out, $menu->type, (string) $menu);
            }
        }

        if ($c->replace !== []) {
            $this->section($out, 'replace', 'values for <replace value="..."> tags');
            foreach ($c->replace as $key => $value) {
                $this->pair($out, (string) $key, $value);
            }
        }

        $this->section($out, 'files');
        if ($c->files->access !== null) {
            $this->pair($out, 'access', $c->files->access);
        }
        if ($c->files->virtual !== null) {
            $this->pair($out, 'virtual', $c->files->virtual);
        }
        $this->pair($out, 'load_pages', $this->joinList($c->files->loadPages, 'load_pages'));
        if ($c->files->allowedPaths !== []) {
            $this->pair($out, 'allowed_paths', $this->joinList($c->files->allowedPaths, 'allowed_paths'));
        }

        $this->section($out, 'security');
        if ($passwordHash !== null) {
            $this->pair($out, 'password_hash', $passwordHash);
        } else {
            if ($c->security->passwordHash !== null) {
                $this->pair($out, 'password_hash', $c->security->passwordHash);
            }
            if ($c->security->password !== null) {
                $this->pair($out, 'password', $c->security->password);
            }
        }
        $this->pair($out, 'secret', $c->security->secret);
        $this->pair($out, 'session_hours', self::number($c->security->sessionSeconds / 3600));
        if ($c->security->lockAlways) {
            $this->pair($out, 'lock', 'yes');
        } elseif ($c->security->lockFile !== null) {
            $this->pair($out, 'lock', $c->security->lockFile);
        }

        $this->section($out, 'display');
        $this->pair($out, 'start_page', $c->display->startPage);
        $this->pair($out, 'calendar_view', $c->display->calendarView);
        $this->pair($out, 'markdown_menu_levels', implode(', ', $c->display->markdownMenuLevels));
        $this->pair($out, 'heading_warnings', self::yes($c->display->headingWarnings));
        $this->pair($out, 'box_pick', $c->display->boxPick);
        if ($c->display->calendarBoxes !== null) {
            $this->pair($out, 'calendar_boxes', $this->joinList($c->display->calendarBoxes, 'calendar_boxes'));
        }

        $style = $c->style;
        if ($style->css !== [] || $style->cssByType !== [] || $style->cssCalendar !== [] || $style->js !== [] || $style->jsByType !== []) {
            $this->section($out, 'style');
            if ($style->css !== []) {
                $this->pair($out, 'css', $this->joinList($style->css, 'css'));
            }
            foreach ($style->cssByType as $type => $files) {
                $this->pair($out, 'css.' . $type, $this->joinList($files, "css.$type"));
            }
            if ($style->cssCalendar !== []) {
                $this->pair($out, 'css.calendar', $this->joinList($style->cssCalendar, 'css.calendar'));
            }
            if ($style->js !== []) {
                $this->pair($out, 'js', $this->joinList($style->js, 'js'));
            }
            foreach ($style->jsByType as $type => $files) {
                $this->pair($out, 'js.' . $type, $this->joinList($files, "js.$type"));
            }
        }

        $this->section($out, 'features');
        $this->pair($out, 'postmeeting', $c->features->postmeeting);
        if ($c->features->accessLog !== null) {
            $this->pair($out, 'access_log', $c->features->accessLog);
        }
        $this->pair($out, 'legacy_css', self::yes($c->features->legacyCss));
        $this->pair($out, 'legacy_js', implode(', ', $c->features->legacyJs));
        $this->pair($out, 'debug', self::yes($c->features->debug));

        return implode("\n", $out) . "\n";
    }

    /** @param list<string> $out */
    private function section(array &$out, string $name, string $comment = ''): void
    {
        $out[] = '';
        $out[] = "[$name]";
        if ($comment !== '') {
            $out[] = '; ' . $comment;
        }
    }

    /** @param list<string> $out */
    private function pair(array &$out, string $key, string $value): void
    {
        if ($key === '' || str_contains($key, '=') || $key !== trim($key) || in_array($key[0], ['[', ';', '#'], true)) {
            $this->problems[] = "the key \"$key\" can not be written to an INI file and was left out";
            return;
        }
        $out[] = $key . ' = ' . self::quote($value);
    }

    /** @param list<string> $items */
    private function joinList(array $items, string $what): string
    {
        foreach ($items as $item) {
            if (str_contains($item, ',') || $item !== trim($item) || $item === '') {
                $this->problems[] = "$what: \"$item\" can not be written in a comma separated list";
            }
        }
        return implode(', ', $items);
    }

    /** Quote a value when the reader would otherwise change it. */
    public static function quote(string $value): string
    {
        $plain = $value === ''
            || ($value === trim($value)
                && !preg_match('/[;"\'\\\\\n\r\t]|\s#/', $value)
                && $value[0] !== '#');
        if ($plain) {
            return $value;
        }
        return '"' . str_replace(['\\', '"', "\n", "\t"], ['\\\\', '\\"', '\\n', '\\t'], $value) . '"';
    }

    private static function yes(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        return $text === '' ? '0' : $text;
    }
}
